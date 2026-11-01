<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AccountingAmount;
use App\Service\AccountingEntryValidator;
use App\Service\AccountingYearClosingCalculator;
use App\Service\FecExportFormatter;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Throwable;
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
        $currentYear = (int) (new DateTimeImmutable('today'))->format('Y');
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);

        $summary = $db->fetchAssociative(
            'SELECT COUNT(DISTINCT e.id) AS entries, COUNT(l.id) AS line_count,
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
        $period = $db->fetchAssociative('SELECT closed_at, closing_entry_id, opening_entry_id FROM app_accounting_fiscal_year WHERE fiscal_year = ?', [$year]);
        $nextPeriod = $year < 2100 ? $db->fetchAssociative('SELECT closed_at, opening_entry_id FROM app_accounting_fiscal_year WHERE fiscal_year = ?', [$year + 1]) : null;
        $unclosedHistoricalEntries = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM app_journal_entry e
             INNER JOIN app_accounting_fiscal_year fy ON fy.fiscal_year = YEAR(e.entry_date)
             WHERE e.entry_date < ? AND fy.closed_at IS NULL',
            [sprintf('%04d-01-01', $year)]
        );
        $nextYearEntries = $year < 2100 ? (int) $db->fetchOne(
            'SELECT COUNT(*) FROM app_journal_entry WHERE entry_date BETWEEN ? AND ?',
            [sprintf('%04d-01-01', $year + 1), sprintf('%04d-12-31', $year + 1)]
        ) : 0;
        $historicalProfitAndLossBalances = (int) $db->fetchOne(
            'SELECT COUNT(*) FROM (
                SELECT a.id
                FROM app_accounting_account a
                LEFT JOIN app_journal_entry_line l ON l.account_id = a.id
                LEFT JOIN app_journal_entry e ON e.id = l.entry_id AND e.status = ? AND e.entry_date < ?
                WHERE a.account_class IN (6, 7)
                GROUP BY a.id
                HAVING COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit ELSE 0 END), 0)
                    <> COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit ELSE 0 END), 0)
            ) historical_result_accounts',
            ['posted', sprintf('%04d-01-01', $year)]
        );
        $debit = (string) ($summary['debit'] ?? '0.00');
        $credit = (string) ($summary['credit'] ?? '0.00');
        $debitCents = AccountingAmount::toCents($debit);
        $creditCents = AccountingAmount::toCents($credit);
        $differenceCents = AccountingAmount::addSignedCents($debitCents, -$creditCents);

        return $this->render('accounting/fec.html.twig', [
            'year' => $year,
            'current_year' => $currentYear,
            'summary' => [
                'entries' => (int) ($summary['entries'] ?? 0),
                'lines' => (int) ($summary['lines'] ?? 0),
                'debit' => $this->formatFrenchCents($debitCents),
                'credit' => $this->formatFrenchCents($creditCents),
                'difference' => $this->formatFrenchCents($differenceCents),
            ],
            'draft_count' => $draftCount,
            'unbalanced_count' => $unbalanced,
            'balanced' => $differenceCents === 0,
            'ready' => $year < $currentYear && $draftCount === 0 && $unbalanced === 0 && $differenceCents === 0 && ($period['closed_at'] ?? null) === null && $nextPeriod !== null && ($nextPeriod['closed_at'] ?? null) === null && ($nextPeriod['opening_entry_id'] ?? null) === null && $unclosedHistoricalEntries === 0 && $nextYearEntries === 0 && $historicalProfitAndLossBalances === 0,
            'closed_at' => $period['closed_at'] ?? null,
            'closing_entry_id' => $period['closing_entry_id'] ?? null,
            'opening_entry_id' => $period['opening_entry_id'] ?? null,
            'next_opening_entry_id' => $nextPeriod['opening_entry_id'] ?? null,
            'next_year' => $year + 1,
            'unclosed_historical_entries' => $unclosedHistoricalEntries,
            'next_year_entries' => $nextYearEntries,
            'historical_profit_and_loss_balances' => $historicalProfitAndLossBalances,
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
        if ($year === false || $year < 2000 || $year > 2099) {
            throw $this->createNotFoundException('Exercice invalide : un exercice suivant doit être disponible pour les à-nouveaux.');
        }

        if ($year >= (int) (new DateTimeImmutable('today'))->format('Y')) {
            throw $this->createNotFoundException('Un exercice ne peut être clôturé qu’après sa date de fin.');
        }

        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);
        $nextYear = $year + 1;
        $nextFrom = sprintf('%04d-01-01', $nextYear);

        $db->beginTransaction();
        try {
            $period = $db->fetchAssociative(
                'SELECT fiscal_year, closed_at, opening_entry_id FROM app_accounting_fiscal_year WHERE fiscal_year = ? FOR UPDATE',
                [$year]
            );
            if (!$period) {
                throw new RuntimeException('Exercice comptable non initialisé.');
            }
            if ($period['closed_at'] !== null) {
                throw new RuntimeException('Cet exercice est déjà clôturé.');
            }

            $nextPeriod = $db->fetchAssociative(
                'SELECT fiscal_year, closed_at, opening_entry_id FROM app_accounting_fiscal_year WHERE fiscal_year = ? FOR UPDATE',
                [$nextYear]
            );
            if (!$nextPeriod || $nextPeriod['closed_at'] !== null) {
                throw new RuntimeException('L’exercice suivant doit être initialisé et ouvert avant de générer les à-nouveaux.');
            }
            if ($nextPeriod['opening_entry_id'] !== null) {
                throw new RuntimeException('Les à-nouveaux de l’exercice suivant ont déjà été générés.');
            }

            $unclosedHistoricalEntries = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM app_journal_entry e
                 INNER JOIN app_accounting_fiscal_year fy ON fy.fiscal_year = YEAR(e.entry_date)
                 WHERE e.entry_date < ? AND fy.closed_at IS NULL',
                [$from]
            );
            if ($unclosedHistoricalEntries > 0) {
                throw new RuntimeException('Clôturez d’abord les exercices antérieurs qui contiennent déjà des écritures.');
            }
            $historicalProfitAndLossBalances = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM (
                    SELECT a.id
                    FROM app_accounting_account a
                    LEFT JOIN app_journal_entry_line l ON l.account_id = a.id
                    LEFT JOIN app_journal_entry e ON e.id = l.entry_id AND e.status = ? AND e.entry_date < ?
                    WHERE a.account_class IN (6, 7)
                    GROUP BY a.id
                    HAVING COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit ELSE 0 END), 0)
                        <> COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit ELSE 0 END), 0)
                ) historical_result_accounts',
                ['posted', $from]
            );
            if ($historicalProfitAndLossBalances > 0) {
                throw new RuntimeException('Des comptes de charges ou produits des exercices antérieurs ne sont pas soldés : régularisez-les avant cette clôture.');
            }

            $nextYearEntries = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM app_journal_entry WHERE entry_date BETWEEN ? AND ?',
                [$nextFrom, sprintf('%04d-12-31', $nextYear)]
            );
            if ($nextYearEntries > 0) {
                throw new RuntimeException('Des écritures existent déjà dans l’exercice suivant : les à-nouveaux doivent être générés avant leur saisie.');
            }

            $draftCount = (int) $db->fetchOne(
                'SELECT COUNT(*) FROM app_journal_entry WHERE status <> ? AND entry_date BETWEEN ? AND ?',
                ['posted', $from, $to]
            );
            $unbalancedCount = $this->countInvalidPostedEntries($db, $from, $to);
            if ($draftCount !== 0 || $unbalancedCount !== 0) {
                throw new RuntimeException('Clôture refusée : écritures non validées, incomplètes ou déséquilibrées.');
            }

            $journal = $db->fetchAssociative(
                'SELECT id, code FROM app_accounting_journal WHERE code = ? FOR UPDATE',
                ['OD']
            );
            if (!$journal) {
                throw new RuntimeException('Le journal OD est requis pour clôturer l’exercice.');
            }
            if ($db->fetchOne('SELECT id FROM app_journal_entry WHERE journal_id = ? AND reference = ?', [(int) $journal['id'], 'CL-'.$year])) {
                throw new RuntimeException('Une écriture de clôture portant cette référence existe déjà ; vérifiez le journal OD.');
            }
            if ($db->fetchOne('SELECT id FROM app_journal_entry WHERE journal_id = ? AND reference = ?', [(int) $journal['id'], 'AN-'.$nextYear])) {
                throw new RuntimeException('Une écriture d’à-nouveaux portant cette référence existe déjà ; vérifiez le journal OD.');
            }

            $resultAccounts = $db->fetchAllAssociative(
                'SELECT id, code, account_class FROM app_accounting_account WHERE code IN (?, ?) AND is_active = 1 ORDER BY code FOR UPDATE',
                ['120000', '129000']
            );
            $resultAccountIds = [];
            foreach ($resultAccounts as $account) {
                if ((int) $account['account_class'] !== 1) {
                    throw new RuntimeException('Les comptes de résultat 120000 et 129000 doivent appartenir à la classe 1.');
                }
                $resultAccountIds[(string) $account['code']] = (int) $account['id'];
            }
            if (!isset($resultAccountIds['120000'], $resultAccountIds['129000'])) {
                throw new RuntimeException('Les comptes 120000 (bénéfice) et 129000 (perte) doivent être actifs.');
            }

            $profitAndLossRows = $db->fetchAllAssociative(
                'SELECT a.id AS account_id, a.code, a.name, a.account_class,
                        COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit ELSE 0 END), 0) AS debit, COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit ELSE 0 END), 0) AS credit
                 FROM app_accounting_account a
                 LEFT JOIN app_journal_entry_line l ON l.account_id = a.id
                 LEFT JOIN app_journal_entry e ON e.id = l.entry_id AND e.status = ? AND e.entry_date BETWEEN ? AND ?
                 WHERE a.account_class IN (6, 7)
                 GROUP BY a.id, a.code, a.name, a.account_class
                 ORDER BY a.code',
                ['posted', $from, $to]
            );
            foreach ($profitAndLossRows as &$row) {
                $row['debit'] = AccountingAmount::toCents((string) $row['debit']);
                $row['credit'] = AccountingAmount::toCents((string) $row['credit']);
            }
            unset($row);

            $closingLines = AccountingYearClosingCalculator::closingLines(
                $profitAndLossRows,
                $resultAccountIds['120000'],
                $resultAccountIds['129000']
            );
            $closingEntryId = null;
            if ($closingLines !== []) {
                $closingEntryId = $this->createClosingEntry(
                    $db,
                    (int) $journal['id'],
                    $to,
                    'CL-'.$year,
                    'Clôture du résultat de l’exercice '.$year,
                    $closingLines
                );
            }

            $balanceStart = $period['opening_entry_id'] !== null ? $from : '2000-01-01';
            $balanceSheetRows = $db->fetchAllAssociative(
                'SELECT a.id AS account_id, a.code, a.name, a.account_class,
                        COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.debit ELSE 0 END), 0) AS debit, COALESCE(SUM(CASE WHEN e.id IS NOT NULL THEN l.credit ELSE 0 END), 0) AS credit
                 FROM app_accounting_account a
                 LEFT JOIN app_journal_entry_line l ON l.account_id = a.id
                 LEFT JOIN app_journal_entry e ON e.id = l.entry_id AND e.status = ? AND e.entry_date BETWEEN ? AND ?
                 WHERE a.account_class BETWEEN 1 AND 5
                 GROUP BY a.id, a.code, a.name, a.account_class
                 ORDER BY a.code',
                ['posted', $balanceStart, $to]
            );
            foreach ($balanceSheetRows as &$row) {
                $row['debit'] = AccountingAmount::toCents((string) $row['debit']);
                $row['credit'] = AccountingAmount::toCents((string) $row['credit']);
            }
            unset($row);

            $openingLines = AccountingYearClosingCalculator::openingLines($balanceSheetRows);
            $openingEntryId = null;
            if ($openingLines !== []) {
                $openingEntryId = $this->createClosingEntry(
                    $db,
                    (int) $journal['id'],
                    $nextFrom,
                    'AN-'.$nextYear,
                    'À-nouveaux de l’exercice '.$nextYear,
                    $openingLines
                );
            }

            $closedBy = $this->getUser()?->getUserIdentifier();
            $db->update('app_accounting_fiscal_year', [
                'closed_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                'closed_by' => $closedBy,
                'closing_entry_id' => $closingEntryId,
            ], ['fiscal_year' => $year]);
            $db->update('app_accounting_fiscal_year', [
                'opening_entry_id' => $openingEntryId,
            ], ['fiscal_year' => $nextYear]);

            $db->commit();
            $this->addFlash('success', sprintf(
                'Exercice %d clôturé. Écriture de résultat : %s. À-nouveaux %d : %s.',
                $year,
                $closingEntryId === null ? 'aucune (résultat nul)' : '#'.$closingEntryId,
                $nextYear,
                $openingEntryId === null ? 'aucun solde à reprendre' : '#'.$openingEntryId
            ));
        } catch (Throwable $exception) {
            if ($db->isTransactionActive()) {
                $db->rollBack();
            }
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_accounting_fec', ['year' => $year]);
    }

    /**
     * @param list<array{account_id: int, label: string, debit: int, credit: int}> $lines
     */
    private function createClosingEntry(
        Connection $db,
        int $journalId,
        string $date,
        string $reference,
        string $description,
        array $lines
    ): int {
        $validatorLines = [];
        foreach ($lines as $line) {
            $validatorLines[] = [
                'account' => (string) $line['account_id'],
                'label' => $line['label'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
            ];
        }
        AccountingEntryValidator::validate($validatorLines);

        $db->fetchOne('SELECT id FROM app_accounting_journal WHERE id = ? FOR UPDATE', [$journalId]);
        $number = (int) $db->fetchOne(
            'SELECT COALESCE(MAX(entry_number), 0) + 1 FROM app_journal_entry WHERE journal_id = ?',
            [$journalId]
        );
        $db->insert('app_journal_entry', [
            'journal_id' => $journalId,
            'entry_number' => $number,
            'entry_date' => $date,
            'reference' => $reference,
            'description' => $description,
            'status' => 'posted',
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $entryId = (int) $db->lastInsertId();

        foreach ($lines as $line) {
            $db->insert('app_journal_entry_line', [
                'entry_id' => $entryId,
                'account_id' => $line['account_id'],
                'label' => $line['label'],
                'debit' => AccountingAmount::formatCents($line['debit']),
                'credit' => AccountingAmount::formatCents($line['credit']),
            ]);
        }

        return $entryId;
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
                HAVING COUNT(l.id)<2
                    OR COALESCE(SUM(l.debit),0)<>COALESCE(SUM(l.credit),0)
                    OR SUM(CASE WHEN l.debit<0 OR l.credit<0
                                     OR (l.debit>0 AND l.credit>0)
                                     OR (l.debit=0 AND l.credit=0)
                                THEN 1 ELSE 0 END)>0
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
