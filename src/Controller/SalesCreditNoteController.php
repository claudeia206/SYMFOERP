<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SalesCreditNote;
use App\Entity\SalesCreditNoteLine;
use App\Entity\SalesInvoice;
use App\Service\AccountingPostingService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use InvalidArgumentException;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sales/invoices/{id}/credit-notes')]
final class SalesCreditNoteController extends AbstractController
{
    #[Route('', name: 'app_sales_credit_note_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(Request $request, SalesInvoice $invoice, EntityManagerInterface $em, AccountingPostingService $posting): Response
    {
        if (!$this->isCsrfTokenValid('sales_credit_note_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        try {
            $creditNote = $em->getConnection()->transactional(function () use ($request, $invoice, $em, $posting): SalesCreditNote {
                $em->lock($invoice, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($invoice);
                if ($invoice->getSellerSnapshot() === null || $invoice->getCustomerSnapshot() === null) {
                    throw new InvalidArgumentException('La facture ne contient pas les coordonnées légales figées nécessaires.');
                }
                $note = new SalesCreditNote(
                    $invoice,
                    (string) $request->request->get('reason', ''),
                    $invoice->getSellerSnapshot(),
                    $invoice->getCustomerSnapshot()
                );
                $quantities = $request->request->all('quantities');
                $count = 0;
                foreach ($invoice->getLines() as $line) {
                    $raw = trim((string) ($quantities[(string) $line->getId()] ?? ''));
                    if ($raw === '') continue;
                    if (!is_numeric($raw) || round((float) $raw, 3) <= 0) {
                        throw new InvalidArgumentException('Chaque quantité d’avoir doit être un nombre strictement positif.');
                    }
                    $em->lock($line, LockMode::PESSIMISTIC_WRITE);
                    $line->recordCredit(number_format((float) $raw, 3, '.', ''));
                    $note->addLine(new SalesCreditNoteLine($note, $line, $raw));
                    ++$count;
                }
                if ($count === 0) throw new InvalidArgumentException('Saisissez au moins une quantité à créditer.');
                $em->persist($note);
                $em->flush();

                $year = (int) (new DateTimeImmutable('today'))->format('Y');
                $connection = $em->getConnection();
                $connection->executeStatement(
                    'INSERT INTO app_credit_note_sequence (fiscal_year, last_number) VALUES (?, 1) ON DUPLICATE KEY UPDATE last_number = last_number + 1',
                    [$year]
                );
                $sequence = (int) $connection->fetchOne('SELECT last_number FROM app_credit_note_sequence WHERE fiscal_year = ? FOR UPDATE', [$year]);
                $note->assignNumber(sprintf('AV-%d-%06d', $year, $sequence));
                $em->flush();
                $posting->postSalesCreditNote((int) $note->getId());
                return $note;
            });
            $this->addFlash('success', 'L’avoir '.$creditNote->getNumber().' a été émis et comptabilisé.');
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'L’avoir n’a pas pu être émis. Aucune quantité n’a été créditée.');
        }

        return $this->redirectToRoute('app_sales_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/{creditNoteId}/pdf', name: 'app_sales_credit_note_pdf', requirements: ['id' => '\d+', 'creditNoteId' => '\d+'], methods: ['GET'])]
    public function pdf(int $creditNoteId, SalesInvoice $invoice, EntityManagerInterface $em): Response
    {
        $note = $em->getRepository(SalesCreditNote::class)->findOneBy(['id' => $creditNoteId, 'invoice' => $invoice]);
        if (!$note instanceof SalesCreditNote || $note->getNumber() === null) {
            throw $this->createNotFoundException('Avoir introuvable.');
        }
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->renderView('sales_credit_note/pdf.html.twig', [
            'credit_note' => $note,
            'invoice' => $invoice,
            'seller' => $note->getSellerSnapshot(),
            'customer' => $note->getCustomerSnapshot(),
        ]), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$note->getNumber().'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
