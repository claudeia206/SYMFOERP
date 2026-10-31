<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AccountingAmount;
use App\Service\FecExportFormatter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;

#[Route('/accounting')]
final class FiscalClosingController extends AbstractController
{
    #[Route('/fec', name: 'app_accounting_fec', methods: ['GET'])]
    public function index(Connection $db, Request $request): Response
    {
        $year = $this->resolveYear($request->query->get('year'), (int) (new DateTimeImmutable())->format('Y'));
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);

        $summary = $db->fetchAssociative(
            'SELECT COUNT(DISTINCT e.id) AS entries, COUNT(l.id) AS lines,
                    COALESCE(SUM(l.debit),0) AS debit, COALESCE(SUM(l.credit),0) AS credit
             FROM app_journal_entry e
             LEFT JOIN app_journal_entry_line l ON l.entry_id=e.id
             WHERE e.status=? AND e.entry_date BETWEEN ? AND ?',
            ['posted', $from, $to]
        ) ?: [];
        $draftCount = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM app_journal_entry WHERE status<>? AND entry_date BETWEEN ? AND ?',
            ['posted', $from, $to]
        );
        $unbalanced = $this->countInvalidPostedEntries($db, $from, $to);
        $period = $db->fetchAssociative('SELECT closed_at FROM app_accounting_fiscal_year WHERE fiscal_year = ?', [$year]);
        $debit = (string) ($summary['debit'] ?? '0.00');
        $credit = (string) ($summary['credit'] ?? '0.00');
        $debitCents = AccountingAmount::toCents($debit);
        $creditCents = AccountingAmount::toCents($credit);
        $differenceCents = AccountingAmount::addSignedCents($debitCents, -$creditCents);

        return $this->render('accounting/fec.html.twig', [
            'year' => $year,
            'summary' => [
                'entries' => (int) ($summary['entries'] ?? 0),
                'lines' => (int) ($summary['lines'] ?? 0),
                'debit' => $this->formatFrenchCents($debitCents),
                'credit' => $this->formatFrenchCents($creditCents),
                'difference' => $this->formatFrenchCents($differenceCents),
            ],
            'draft_count' => $draftCount,
            'unbalanced_count' => $unbalanced,
            'ready' => $draftCount === 0 && $unbalanced === 0 && $differenceCents === 0 && ($period['closed_at'] ?? null) === null,
            'closed_at' => $period['closed_at'] ?? null,
        ]);
    }

    #[Route('/fec/export', name: 'app_accounting_fec_export', methods: ['GET'])]
    public function export(Connection $db, Request $request, FecExportFormatter $formatter): Response
    {
        $year = $this->resolveYear($request->query->get('year'), (int) (new DateTimeImmutable())->format('Y'));
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);

        $draftCount = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM app_journal_entry WHERE status<>? AND entry_date BETWEEN ? AND ?',
            ['posted', $from, $to]
        );
        $invalidCount = $this->countInvalidPostedEntries($db, $from, $to);
        if ($draftCount !== 0 || $invalidCount !== 0) {
            return new Response(
                sprintf('Export FEC refusé : %d écriture(s) non comptabilisée(s), %d écriture(s) comptabilisée(s) incomplète(s) ou déséquilibrée(s).', $draftCount, $invalidCount),
                Response::HTTP_CONFLICT,
                ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']
            );
        }

        $rows = $db->fetchAllAssociative(
            'SELECT j.code AS journal_code, j.name AS journal_name, e.entry_number,
                    e.entry_date, e.created_at, a.code AS account_code, a.name AS account_name,
                    e.reference, e.description, l.label, l.debit, l.credit
             FROM app_journal_entry e
             INNER JOIN app_accounting_journal j ON j.id=e.journal_id
             INNER JOIN app_journal_entry_line l ON l.entry_id=e.id
             INNER JOIN app_accounting_account a ON a.id=l.account_id
             WHERE e.status=? AND e.entry_date BETWEEN ? AND ?
             ORDER BY e.entry_date, j.code, e.entry_number, l.id',
            ['posted', $from, $to]
        );

        $fecRows = [];
        foreach ($rows as $row) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $row['entry_date']);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if ($date === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== (string) $row['entry_date']) {
                throw new RuntimeException('Une date d’écriture est invalide : export FEC interrompu.');
            }
            $formattedDate = $date->format('Ymd');
            $createdAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr((string) $row['created_at'], 0, 19));
            $createdAtErrors = DateTimeImmutable::getLastErrors();
            if ($createdAt === false || ($createdAtErrors !== false && ($createdAtErrors['warning_count'] > 0 || $createdAtErrors['error_count'] > 0))) {
                throw new RuntimeException('Une date de validation est invalide : export FEC interrompu.');
            }
            $validationDate = $createdAt->format('Ymd');
            $fecRows[] = [
                $row['journal_code'],
                $row['journal_name'],
                (string) $row['entry_number'],
                $formattedDate,
                $row['account_code'],
                $row['account_name'],
                '',
                '',
                $row['reference'] ?? '',
                $formattedDate,
                ($row['label'] ?? '') !== '' ? $row['label'] : $row['description'],
                $formatter->formatAmount((string) $row['debit']),
                $formatter->formatAmount((string) $row['credit']),
                '',
                '',
                $validationDate,
                '',
                '',
            ];
        }

        $response = new Response($formatter->formatDocument($fecRows));
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="FEC_%d.txt"', $year));
        $response->headers->set('Cache-Control', 'no-store, private');
        return $response;
    }

    #[Route('/fec/close', name: 'app_accounting_fiscal_year_close', methods: ['POST'])]
    public function closeYear(Connection $db, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('accounting_fiscal_year_close', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $year = filter_var($request->request->get('year'), FILTER_VALIDATE_INT);
        if ($year === false || $year < 2000 || $year > 2100) {
            throw $this->createNotFoundException('Exercice invalide.');
        }

        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $db->beginTransaction();
        try {
            $period = $db->fetchAssociative(
                'SELECT fiscal_year, closed_at FROM app_accounting_fiscal_year WHERE fiscal_year = ? FOR UPDATE',
                [$year]
            );
            if (!$period) {
                throw new RuntimeException('Exercice comptable non initialisé.');
            }
            if ($period['closed_at'] !== null) {
                throw new RuntimeException('Cet exercice est déjà clôturé.');
            }

            $draftCount = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM app_journal_entry WHERE status <> ? AND entry_date BETWEEN ? AND ?',
                ['posted', $from, $to]
            );
            $unbalancedCount = $this->countInvalidPostedEntries($db, $from, $to);
            if ($draftCount !== 0 || $unbalancedCount !== 0) {
                throw new RuntimeException('Clôture refusée : écritures non validées, incomplètes ou déséquilibrées.');
            }

            $db->update('app_accounting_fiscal_year', [
                'closed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], ['fiscal_year' => $year]);
            $db->commit();
            $this->addFlash('success', sprintf('Exercice %d clôturé. Toute nouvelle écriture est désormais bloquée.', $year));
        } catch (\Throwable $exception) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_accounting_fec', ['year' => $year]);
    }

    private function countInvalidPostedEntries(Connection $db, string $from, string $to): int
    {
        return (int) $db->fetchOne(
            'SELECT COUNT(*) FROM (
                SELECT e.id
                FROM app_journal_entry e
                LEFT JOIN app_journal_entry_line l ON l.entry_id=e.id
                WHERE e.status=? AND e.entry_date BETWEEN ? AND ?
                GROUP BY e.id
                HAVING COUNT(l.id)<2 OR COALESCE(SUM(l.debit),0)<>COALESCE(SUM(l.credit),0)
             ) invalid_entries',
            ['posted', $from, $to]
        );
    }

    private function resolveYear(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $year = filter_var($value, FILTER_VALIDATE_INT);
        if ($year === false || $year < 2000 || $year > 2100) {
            throw $this->createNotFoundException('Exercice invalide.');
        }

        return $year;
    }

    private function formatFrenchCents(int $cents): string
    {
        $formatted = AccountingAmount::formatCents($cents);
        $negative = str_starts_with($formatted, '-');
        $unsigned = $negative ? substr($formatted, 1) : $formatted;
        [$integer, $fraction] = explode('.', $unsigned, 2);
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $integer);

        return ($negative ? '-' : '').$grouped.','.$fraction;
    }
}
