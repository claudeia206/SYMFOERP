<?php
declare(strict_types=1);

namespace App\Controller;

use App\Service\AccountingAmount;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/accounting/statements')]
final class FinancialStatementsController extends AbstractController
{
    #[Route('', name: 'app_financial_statements', methods: ['GET'])]
    public function index(Connection $db, Request $request): Response
    {
        $from = (string) $request->query->get('from', (new DateTimeImmutable('first day of january this year'))->format('Y-m-d'));
        $to = (string) $request->query->get('to', (new DateTimeImmutable('today'))->format('Y-m-d'));
        $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if (
            !$fromDate || !$toDate
            || $fromDate->format('Y-m-d') !== $from
            || $toDate->format('Y-m-d') !== $to
            || $from > $to
            || $fromDate->format('Y') !== $toDate->format('Y')
        ) {
            throw $this->createNotFoundException('Période invalide : les états financiers doivent rester dans un même exercice.');
        }

        $year = (int) $fromDate->format('Y');
        $period = $db->fetchAssociative(
            'SELECT opening_entry_id, closing_entry_id FROM app_accounting_fiscal_year WHERE fiscal_year = ?',
            [$year]
        );
        $balanceStart = ($period['opening_entry_id'] ?? null) !== null
            ? sprintf('%04d-01-01', $year)
            : '2000-01-01';
        $closingEntryId = (int) ($period['closing_entry_id'] ?? 0);

        // If an opening entry exists, it replaces the prior years' cumulative postings.
        // The closing result entry is excluded from the income statement but retained in equity.
        $rows = $db->fetchAllAssociative(<<<'SQL'
SELECT a.code, a.name, LEFT(a.code, 1) AS class,
       ROUND(COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? THEN l.debit ELSE 0 END), 0), 2) AS cumulative_debit,
       ROUND(COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? THEN l.credit ELSE 0 END), 0), 2) AS cumulative_credit,
       ROUND(COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? AND e.id <> ? THEN l.debit ELSE 0 END), 0), 2) AS period_debit,
       ROUND(COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? AND e.id <> ? THEN l.credit ELSE 0 END), 0), 2) AS period_credit
FROM app_accounting_account a
LEFT JOIN app_journal_entry_line l ON l.account_id = a.id
LEFT JOIN app_journal_entry e ON e.id = l.entry_id AND e.status = 'posted' AND e.entry_date <= ?
WHERE a.code REGEXP '^[1-7]'
GROUP BY a.id, a.code, a.name
ORDER BY a.code
SQL, [
            $balanceStart, $to,
            $balanceStart, $to,
            $from, $to, $closingEntryId,
            $from, $to, $closingEntryId,
            $to,
        ]);

        $groups = ['assets' => [], 'liabilities' => [], 'equity' => [], 'income' => [], 'expenses' => []];
        $totals = ['assets' => 0, 'liabilities' => 0, 'equity' => 0, 'income' => 0, 'expenses' => 0];

        foreach ($rows as $row) {
            $class = (string) $row['class'];
            $code = (string) $row['code'];
            $debit = AccountingAmount::toCents((string) $row['cumulative_debit']);
            $credit = AccountingAmount::toCents((string) $row['cumulative_credit']);
            $periodDebit = AccountingAmount::toCents((string) $row['period_debit']);
            $periodCredit = AccountingAmount::toCents((string) $row['period_credit']);

            $add = static function (array &$groups, array &$totals, string $key, array $row, int $balance): void {
                if ($balance === 0) {
                    return;
                }
                $groups[$key][] = ['code' => $row['code'], 'name' => $row['name'], 'balance' => AccountingAmount::formatFrenchCents($balance)];
                $totals[$key] = AccountingAmount::addSignedCents($totals[$key], $balance);
            };

            if (in_array($class, ['2', '3'], true)) {
                $add($groups, $totals, 'assets', $row, AccountingAmount::addSignedCents($debit, -$credit));
            } elseif ($class === '1') {
                $key = preg_match('/^1[0-5]/', $code) ? 'equity' : 'liabilities';
                $add($groups, $totals, $key, $row, AccountingAmount::addSignedCents($credit, -$debit));
            } elseif (in_array($class, ['4', '5'], true)) {
                $signed = AccountingAmount::addSignedCents($debit, -$credit);
                $key = $signed >= 0 ? 'assets' : 'liabilities';
                $add($groups, $totals, $key, $row, abs($signed));
            } elseif ($class === '6') {
                $add($groups, $totals, 'expenses', $row, AccountingAmount::addSignedCents($periodDebit, -$periodCredit));
            } elseif ($class === '7') {
                $add($groups, $totals, 'income', $row, AccountingAmount::addSignedCents($periodCredit, -$periodDebit));
            }
        }

        $balanceSheetTotal = AccountingAmount::addSignedCents($totals['liabilities'], $totals['equity']);
        $netResult = AccountingAmount::addSignedCents($totals['income'], -$totals['expenses']);
        foreach ($totals as &$value) {
            $value = AccountingAmount::formatFrenchCents($value);
        }
        unset($value);

        return $this->render('accounting/statements.html.twig', [
            'groups' => $groups,
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
            'balance_sheet_total' => AccountingAmount::formatFrenchCents($balanceSheetTotal),
            'net_result' => AccountingAmount::formatFrenchCents($netResult),
        ]);
    }
}
