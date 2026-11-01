<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PurchaseOrder;
use App\Entity\SupplierInvoice;
use App\Entity\SupplierPayment;
use App\Repository\PurchaseOrderRepository;
use App\Entity\ThirdParty;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/purchases/invoices')]
final class SupplierInvoiceController extends AbstractController
{
    #[Route('', name: 'app_supplier_invoices', methods: ['GET'])]
    public function index(EntityManagerInterface $em, PurchaseOrderRepository $orders): Response
    {
        return $this->render('supplier_invoice/index.html.twig', [
            'invoices' => $em->getRepository(SupplierInvoice::class)->findBy([], ['createdAt' => 'DESC'], 100),
            'orders' => array_values(array_filter($orders->findBy([], ['createdAt' => 'DESC'], 100), static fn(PurchaseOrder $o): bool => in_array($o->getStatus(), [PurchaseOrder::STATUS_PARTIALLY_RECEIVED, PurchaseOrder::STATUS_RECEIVED], true))),
        ]);
    }

    #[Route('/from-order/{id}', name: 'app_supplier_invoice_from_order', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function createFromOrder(Request $request, PurchaseOrder $order, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supplier_invoice_create_'.$order->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        try {
            $reference = trim((string) $request->request->get('supplier_invoice_number', ''));
            $em->getConnection()->transactional(function () use ($em, $order, $reference): void {
                $em->lock($order, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($order);
                $invoice = new SupplierInvoice($order, $reference);
                $em->persist($invoice);
                $em->flush();
            });
            $this->addFlash('success', 'La facture fournisseur brouillon a été créée depuis les quantités réceptionnées.');
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Création impossible : vérifiez que cette commande ne possède pas déjà une facture et que la référence fournisseur est unique.');
        }
        return $this->redirectToRoute('app_supplier_invoices');
    }

    #[Route('/{id}', name: 'app_supplier_invoice_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(SupplierInvoice $invoice): Response
    {
        return $this->render('supplier_invoice/show.html.twig', ['invoice' => $invoice, 'methods' => $this->methods()]);
    }

    #[Route('/{id}/receive', name: 'app_supplier_invoice_receive', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function receive(Request $request, SupplierInvoice $invoice, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supplier_invoice_receive_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        try {
            $date = new \DateTimeImmutable((string) $request->request->get('invoice_date', ''));
            $due = new \DateTimeImmutable((string) $request->request->get('due_date', ''));
            $em->getConnection()->transactional(function () use ($em, $invoice, $date, $due): void {
                $em->lock($invoice, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($invoice);
                $invoice->receive($date, $due);
                $em->flush();
            });
            $this->addFlash('success', 'La facture fournisseur a été validée.');
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Validation impossible. Vérifiez les dates saisies.');
        }
        return $this->redirectToRoute('app_supplier_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/{id}/payments', name: 'app_supplier_invoice_payment', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addPayment(Request $request, SupplierInvoice $invoice, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('supplier_invoice_payment_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        try {
            $amount = trim((string) $request->request->get('amount', ''));
            $date = new \DateTimeImmutable((string) $request->request->get('paid_at', ''));
            $method = (string) $request->request->get('method', '');
            $reference = (string) $request->request->get('reference', '');
            $note = (string) $request->request->get('note', '');
            $em->getConnection()->transactional(function () use ($em, $invoice, $amount, $date, $method, $reference, $note): void {
                $em->lock($invoice, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($invoice);
                $payment = new SupplierPayment($invoice, $amount, $date, $method, $reference, $note);
                $invoice->addPayment($payment);
                $em->persist($payment);
                $em->flush();
            });
            $this->addFlash('success', 'Le règlement fournisseur a été enregistré.');
        } catch (InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Le règlement n’a pas pu être enregistré. Vérifiez le montant et les informations saisies.');
        }
        return $this->redirectToRoute('app_supplier_invoice_show', ['id' => $invoice->getId()]);
    }

    private function methods(): array
    {
        return ['bank_transfer' => 'Virement', 'card' => 'Carte', 'cash' => 'Espèces', 'check' => 'Chèque', 'other' => 'Autre'];
    }
}
