<?php
declare(strict_types=1);

namespace App\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
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
        $year = (int) $request->query->get('year', (new DateTimeImmutable())->format('Y'));
        if ($year < 2000 || $year > 2100) {
            throw $this->createNotFoundException('Exercice invalide.');
        }

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
        $unbalanced = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM (
                SELECT e.id FROM app_journal_entry e
                INNER JOIN app_journal_entry_line l ON l.entry_id=e.id
                WHERE e.status=? AND e.entry_date BETWEEN ? AND ?
                GROUP BY e.id HAVING ABS(SUM(l.debit)-SUM(l.credit))>0.005
             ) unbalanced_entries',
            ['posted', $from, $to]
        );
        $period = $db->fetchAssociative('SELECT closed_at FROM app_accounting_fiscal_year WHERE fiscal_year = ?', [$year]);
        $debit = (float) ($summary['debit'] ?? 0);
        $credit = (float) ($summary['credit'] ?? 0);

        return $this->render('accounting/fec.html.twig', [
            'year' => $year,
            'summary' => [
                'entries' => (int) ($summary['entries'] ?? 0),
                'lines' => (int) ($summary['lines'] ?? 0),
                'debit' => $debit,
                'credit' => $credit,
                'difference' => round($debit - $credit, 2),
            ],
            'draft_count' => $draftCount,
            'unbalanced_count' => $unbalanced,
            'ready' => $draftCount === 0 && $unbalanced === 0 && abs($debit - $credit) < 0.005 && ($period['closed_at'] ?? null) === null,
            'closed_at' => $period['closed_at'] ?? null,
        ]);
    }

    #[Route('/fec/export', name: 'app_accounting_fec_export', methods: ['GET'])]
    public function export(Connection $db, Request $request): Response
    {
        $year = (int) $request->query->get('year', (new DateTimeImmutable())->format('Y'));
        if ($year < 2000 || $year > 2100) {
            throw $this->createNotFoundException('Exercice invalide.');
        }
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $rows = $db->fetchAllAssociative(
            'SELECT j.code AS journal_code, j.name AS journal_name, e.entry_number,
                    e.entry_date, a.code AS account_code, a.name AS account_name,
                    e.reference, e.description, l.label, l.debit, l.credit
             FROM app_journal_entry e
             INNER JOIN app_accounting_journal j ON j.id=e.journal_id
             INNER JOIN app_journal_entry_line l ON l.entry_id=e.id
             INNER JOIN app_accounting_account a ON a.id=l.account_id
             WHERE e.status=? AND e.entry_date BETWEEN ? AND ?
             ORDER BY e.entry_date, j.code, e.entry_number, l.id',
            ['posted', $from, $to]
        );

        $columns = [
            'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate',
            'CompteNum', 'CompteLib', 'CompAuxNum', 'CompAuxLib',
            'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit',
            'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
        ];
        $lines = [implode('|', $columns)];
        foreach ($rows as $row) {
            $date = (new DateTimeImmutable((string) $row['entry_date']))->format('Ymd');
            $values = [
                $row['journal_code'], $row['journal_name'], (string) $row['entry_number'], $date,
                $row['account_code'], $row['account_name'], '', '',
                $row['reference'] ?? '', $date, $row['label'] ?: $row['description'],
                number_format((float) $row['debit'], 2, '.', ''),
                number_format((float) $row['credit'], 2, '.', ''),
                '', '', $date, '', '',
            ];
            $lines[] = implode('|', array_map(static function ($value): string {
                $value = str_replace(["\r", "\n", '|'], [' ', ' ', '/'], (string) $value);
                return $value;
            }, $values));
        }

        $response = new Response(implode("\r\n", $lines) . "\r\n");
        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="FEC_%d.txt"', $year));
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
                throw new \RuntimeException('Exercice comptable non initialisé.');
            }
            if ($period['closed_at'] !== null) {
                throw new \RuntimeException('Cet exercice est déjà clôturé.');
            }

            $draftCount = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM app_journal_entry WHERE status <> ? AND entry_date BETWEEN ? AND ?',
                ['posted', $from, $to]
            );
            $unbalancedCount = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM (
                    SELECT e.id FROM app_journal_entry e
                    INNER JOIN app_journal_entry_line l ON l.entry_id = e.id
                    WHERE e.status = ? AND e.entry_date BETWEEN ? AND ?
                    GROUP BY e.id HAVING ABS(SUM(l.debit) - SUM(l.credit)) > 0.005
                ) unbalanced_entries',
                ['posted', $from, $to]
            );
            if ($draftCount !== 0 || $unbalancedCount !== 0) {
                throw new \RuntimeException('Clôture refusée : écritures non validées ou déséquilibrées.');
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

}
