<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AccountingEntryReversal;
use App\Service\AccountingPeriodGuard;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route('/accounting/entries')]
final class AccountingEntryReversalController extends AbstractController
{
    #[Route('/{id}/reverse', name: 'app_accounting_entry_reverse', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function reverse(
        int $id,
        Request $request,
        Connection $db,
        AccountingPeriodGuard $periodGuard,
    ): Response {
        if (!$this->isCsrfTokenValid('accounting_entry_reverse_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $date = (string) $request->request->get('reversal_date', '');
        $reason = trim((string) $request->request->get('reason', ''));
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || $reason === '' || mb_strlen($reason) > 180) {
            $this->addFlash('error', 'Date de contrepassation invalide ou motif absent (180 caractères maximum).');
            return $this->redirectToRoute('app_general_ledger');
        }

        try {
            $db->beginTransaction();
            try {
                $original = $db->fetchAssociative(
                    'SELECT id, journal_id, entry_number, entry_date, status FROM app_journal_entry WHERE id = ? FOR UPDATE',
                    [$id]
                );
                if (!$original || $original['status'] !== 'posted') {
                    throw new InvalidArgumentException('Écriture comptable introuvable ou non comptabilisée.');
                }
                if ($date < (string) $original['entry_date']) {
                    throw new InvalidArgumentException('La contrepassation ne peut pas être antidatée avant l’écriture d’origine.');
                }
                if ($db->fetchOne('SELECT id FROM app_journal_entry WHERE reversal_of_entry_id = ?', [$id])) {
                    throw new InvalidArgumentException('Cette écriture a déjà été contrepassée.');
                }

                $periodGuard->assertOpenDate($db, $date);
                $journal = $db->fetchAssociative(
                    'SELECT id, code FROM app_accounting_journal WHERE id = ? FOR UPDATE',
                    [(int) $original['journal_id']]
                );
                if (!$journal) {
                    throw new InvalidArgumentException('Journal d’origine introuvable.');
                }

                $sourceLines = $db->fetchAllAssociative(
                    'SELECT account_id, label, debit, credit FROM app_journal_entry_line WHERE entry_id = ? ORDER BY id FOR UPDATE',
                    [$id]
                );
                $lines = AccountingEntryReversal::reverseLines($sourceLines);
                $number = (int) $db->fetchOne(
                    'SELECT COALESCE(MAX(entry_number), 0) + 1 FROM app_journal_entry WHERE journal_id = ?',
                    [(int) $journal['id']]
                );

                $db->insert('app_journal_entry', [
                    'journal_id' => (int) $journal['id'],
                    'entry_number' => $number,
                    'entry_date' => $date,
                    'reference' => 'CONTREPASSATION-'.$id,
                    'description' => mb_substr(sprintf('Contrepassation #%d – %s', $id, $reason), 0, 255),
                    'status' => 'posted',
                    'reversal_of_entry_id' => $id,
                    'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
                $reversalId = (int) $db->lastInsertId();

                foreach ($lines as $line) {
                    $db->insert('app_journal_entry_line', [
                        'entry_id' => $reversalId,
                        'account_id' => $line['account_id'],
                        'label' => $line['label'],
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                    ]);
                }

                $db->commit();
            } catch (Throwable $exception) {
                if ($db->isTransactionActive()) {
                    $db->rollBack();
                }
                throw $exception;
            }

            $this->addFlash('success', sprintf(
                'Écriture %s-%06d créée pour contrepasser l’écriture d’origine.',
                $journal['code'],
                $number
            ));
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (Throwable) {
            $this->addFlash('error', 'La contrepassation a échoué ; aucune écriture partielle n’a été conservée.');
        }

        return $this->redirectToRoute('app_general_ledger');
    }
}
