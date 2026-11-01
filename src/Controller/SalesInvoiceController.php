<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SalesInvoice;
use App\Entity\CompanyProfile;
use App\Entity\SalesOrder;
use App\Entity\SalesPayment;
use App\Repository\SalesInvoiceRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use DateTimeImmutable;
use InvalidArgumentException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sales/invoices')]
final class SalesInvoiceController extends AbstractController
{
    #[Route('', name: 'app_sales_invoices', methods: ['GET'])]
    public function index(SalesInvoiceRepository $invoices): Response
    {
        return $this->render('sales_invoice/index.html.twig', [
            'invoices' => $invoices->findBy([], ['createdAt' => 'DESC'], 100),
        ]);
    }

    #[Route('/from-order/{id}', name: 'app_sales_invoice_from_order', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function createFromOrder(Request $request, SalesOrder $order, SalesInvoiceRepository $invoices, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('sales_invoice_create_'.$order->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $invoice = $entityManager->getConnection()->transactional(function () use ($entityManager, $invoices, $order): SalesInvoice {
            $entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
            $existing = $invoices->findOneBy(['order' => $order]);
            if ($existing instanceof SalesInvoice) {
                return $existing;
            }
            $invoice = new SalesInvoice($order);
            $entityManager->persist($invoice);
            $entityManager->flush();
            return $invoice;
        });

        $this->addFlash('success', 'La facture brouillon a été créée. Vérifiez les informations légales avant toute émission.');
        return $this->redirectToRoute('app_sales_invoice_show', ['id' => $invoice->getId()]);
    }


    #[Route('/{id}/issue', name: 'app_sales_invoice_issue', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function issue(Request $request, SalesInvoice $invoice, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('sales_invoice_issue_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        try {
            $entityManager->getConnection()->transactional(function () use ($entityManager, $invoice): void {
                $entityManager->lock($invoice, LockMode::PESSIMISTIC_WRITE);
                $profile = $entityManager->find(CompanyProfile::class, 1);
                if (!$profile instanceof CompanyProfile) {
                    throw new InvalidArgumentException('Configurez d’abord l’identité légale de l’entreprise.');
                }
                $profileErrors = $profile->getInvoiceReadinessErrors();
                if ($profileErrors !== []) {
                    throw new InvalidArgumentException(implode(' ', $profileErrors));
                }
                $customer = $invoice->getCustomer();
                $customerSnapshot = [
                    'name' => $customer->getName(),
                    'address' => $customer->getAddress(),
                    'postal_code' => $customer->getPostalCode(),
                    'city' => $customer->getCity(),
                    'country' => $customer->getCountry(),
                    'email' => $customer->getEmail(),
                ];
                if (!$customerSnapshot['address'] || !$customerSnapshot['postal_code'] || !$customerSnapshot['city'] || !$customerSnapshot['country']) {
                    throw new InvalidArgumentException('Complétez l’adresse de facturation du client avant émission.');
                }
                $connection = $entityManager->getConnection();
                $year = (int) (new DateTimeImmutable('today'))->format('Y');
                $connection->executeStatement(
                    'INSERT INTO app_invoice_sequence (fiscal_year, last_number) VALUES (?, 1) ON DUPLICATE KEY UPDATE last_number = last_number + 1',
                    [$year]
                );
                $sequence = (int) $connection->fetchOne('SELECT last_number FROM app_invoice_sequence WHERE fiscal_year = ? FOR UPDATE', [$year]);
                $issuedAt = new DateTimeImmutable('now');
                $dueDate = $issuedAt->modify(sprintf('+%d days', $profile->getPaymentDelayDays()));
                $invoice->issue(sprintf('FAC-%d-%06d', $year, $sequence), $issuedAt, $dueDate, $profile->toInvoiceSnapshot(), $customerSnapshot);
                $entityManager->flush();
            });
            $this->addFlash('success', 'La facture a été émise et numérotée.');
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Émission impossible. Aucune facture n’a été confirmée comme émise.');
        }

        return $this->redirectToRoute('app_sales_invoice_show', ['id' => $invoice->getId()]);
    }


    #[Route('/{id}/pdf', name: 'app_sales_invoice_pdf', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function pdf(SalesInvoice $invoice): Response
    {
        if ($invoice->getNumber() === null || $invoice->getSellerSnapshot() === null || $invoice->getCustomerSnapshot() === null) {
            throw $this->createNotFoundException('Le PDF est disponible uniquement après émission.');
        }
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->renderView('sales_invoice/pdf.html.twig', [
            'invoice' => $invoice,
            'seller' => $invoice->getSellerSnapshot(),
            'customer' => $invoice->getCustomerSnapshot(),
        ]), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->getNumber().'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/{id}/payments', name: 'app_sales_invoice_payment', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function addPayment(Request $request, SalesInvoice $invoice, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('sales_invoice_payment_'.$invoice->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        try {
            $amount = trim((string) $request->request->get('amount', ''));
            $date = new DateTimeImmutable((string) $request->request->get('paid_at', ''));
            $method = (string) $request->request->get('method', '');
            $reference = (string) $request->request->get('reference', '');
            $note = (string) $request->request->get('note', '');

            $entityManager->getConnection()->transactional(function () use ($entityManager, $invoice, $amount, $date, $method, $reference, $note): void {
                $entityManager->lock($invoice, LockMode::PESSIMISTIC_WRITE);
                $payment = new SalesPayment($invoice, $amount, $date, $method, $reference, $note);
                $invoice->addPayment($payment);
                $entityManager->persist($payment);
                $entityManager->flush();
            });
            $this->addFlash('success', 'Le règlement a été enregistré.');
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Le règlement n’a pas pu être enregistré. Vérifiez les informations saisies.');
        }

        return $this->redirectToRoute('app_sales_invoice_show', ['id' => $invoice->getId()]);
    }

    #[Route('/{id}', name: 'app_sales_invoice_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(SalesInvoice $invoice, EntityManagerInterface $entityManager): Response
    {
        $profile = $entityManager->find(CompanyProfile::class, 1);
        $issueErrors = $profile instanceof CompanyProfile ? $profile->getInvoiceReadinessErrors() : ['Configurez l’identité légale de l’entreprise.'];
        $customer = $invoice->getCustomer();
        if (!$customer->getAddress() || !$customer->getPostalCode() || !$customer->getCity() || !$customer->getCountry()) {
            $issueErrors[] = 'Complétez l’adresse de facturation du client.';
        }
        return $this->render('sales_invoice/show.html.twig', [
            'invoice' => $invoice,
            'issue_errors' => $issueErrors,
        ]);
    }
}
