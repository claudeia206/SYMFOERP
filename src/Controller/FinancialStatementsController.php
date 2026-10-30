<?php
declare(strict_types=1);

namespace App\Controller;

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
        foreach ([$from, $to] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw $this->createNotFoundException('Période invalide.');
            }
        }
        if ($from > $to) {
            throw $this->createNotFoundException('Période invalide.');
        }

        // Balance sheet: cumulative posted balances through the closing date.
        // Income statement: movements restricted to the selected financial year.
        $rows = $db->fetchAllAssociative(<<<'SQL'
SELECT a.code, a.name, LEFT(a.code, 1) AS class,
       ROUND(COALESCE(SUM(CASE WHEN e.entry_date <= ? THEN l.debit ELSE 0 END), 0), 2) AS cumulative_debit,
       ROUND(COALESCE(SUM(CASE WHEN e.entry_date <= ? THEN l.credit ELSE 0 END), 0), 2) AS cumulative_credit,
       ROUND(COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? THEN l.debit ELSE 0 END), 0), 2) AS period_debit,
       ROUND(COALESCE(SUM(CASE WHEN e.entry_date BETWEEN ? AND ? THEN l.credit ELSE 0 END), 0), 2) AS period_credit
FROM app_accounting_account a
LEFT JOIN app_journal_entry_line l ON l.account_id = a.id
LEFT JOIN app_journal_entry e ON e.id = l.entry_id AND e.status = 'posted' AND e.entry_date <= ?
WHERE a.is_active = 1 AND a.code REGEXP '^[1-7]'
GROUP BY a.id, a.code, a.name
ORDER BY a.code
SQL, [$to, $to, $from, $to, $from, $to, $to]);

        $groups = ['assets' => [], 'liabilities' => [], 'equity' => [], 'income' => [], 'expenses' => []];
        $totals = ['assets' => 0.0, 'liabilities' => 0.0, 'equity' => 0.0, 'income' => 0.0, 'expenses' => 0.0];

        foreach ($rows as $row) {
            $class = (string) $row['class'];
            $code = (string) $row['code'];
            $debit = (float) $row['cumulative_debit'];
            $credit = (float) $row['cumulative_credit'];
            $periodDebit = (float) $row['period_debit'];
            $periodCredit = (float) $row['period_credit'];

            $add = static function (array &$groups, array &$totals, string $key, array $row, float $balance): void {
                if (abs($balance) < 0.005) {
                    return;
                }
                $groups[$key][] = ['code' => $row['code'], 'name' => $row['name'], 'balance' => round($balance, 2)];
                $totals[$key] += $balance;
            };

            if (in_array($class, ['2', '3'], true)) {
                $add($groups, $totals, 'assets', $row, $debit - $credit);
            } elseif ($class === '1') {
                // PCG accounts 10–15 are equity; 16–18 are financing liabilities.
                $key = preg_match('/^1[0-5]/', $code) ? 'equity' : 'liabilities';
                $add($groups, $totals, $key, $row, $credit - $debit);
            } elseif (in_array($class, ['4', '5'], true)) {
                $signed = $debit - $credit;
                $key = $signed >= 0 ? 'assets' : 'liabilities';
                $add($groups, $totals, $key, $row, abs($signed));
            } elseif ($class === '6') {
                $add($groups, $totals, 'expenses', $row, $periodDebit - $periodCredit);
            } elseif ($class === '7') {
                $add($groups, $totals, 'income', $row, $periodCredit - $periodDebit);
            }
        }

        foreach ($totals as &$value) {
            $value = round($value, 2);
        }
        unset($value);

        return $this->render('accounting/statements.html.twig', [
            'groups' => $groups,
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
            'net_result' => round($totals['income'] - $totals['expenses'], 2),
        ]);
    }
}
