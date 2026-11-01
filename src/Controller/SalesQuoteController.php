<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SalesQuote;
use App\Entity\Product;
use App\Entity\SalesQuoteLine;
use App\Entity\SalesOrder;
use App\Entity\ThirdParty;
use App\Repository\SalesQuoteRepository;
use App\Repository\ProductRepository;
use App\Repository\SalesOrderRepository;
use App\Repository\ThirdPartyRepository;
use DateTimeImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sales/quotes')]
final class SalesQuoteController extends AbstractController
{
    private const STATUSES = [
        SalesQuote::STATUS_DRAFT => 'Brouillon',
        SalesQuote::STATUS_SENT => 'Envoyé',
        SalesQuote::STATUS_ACCEPTED => 'Accepté',
        SalesQuote::STATUS_REJECTED => 'Refusé',
    ];

    #[Route('', name: 'app_sales_quotes', methods: ['GET'])]
    public function index(Request $request, SalesQuoteRepository $quotes): Response
    {
        $term = mb_substr(trim((string) $request->query->get('q', '')), 0, 120);
        $status = (string) $request->query->get('status', '');
        $total = $quotes->countSearchResults($term, $status);
        $pageCount = max(1, (int) ceil($total / 25));
        $page = min($pageCount, max(1, $request->query->getInt('page', 1)));

        return $this->render('sales_quote/index.html.twig', [
            'quotes' => $quotes->search($term, $status, $page, 25),
            'search' => $term,
            'selected_status' => $status,
            'current_page' => $page,
            'page_count' => $pageCount,
            'total_count' => $total,
            'statuses' => self::STATUSES,
        ]);
    }

    #[Route('/new', name: 'app_sales_quote_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ThirdPartyRepository $thirdParties, ProductRepository $productRepository, EntityManagerInterface $entityManager): Response
    {
        $customers = $thirdParties->search('', ThirdParty::CATEGORY_CUSTOMER, 1, 100);
        $products = $productRepository->findBy(['active' => true], ['name' => 'ASC'], 500);
        $errors = [];
        $values = ['customer_id' => '', 'valid_until' => (new DateTimeImmutable('+30 days'))->format('Y-m-d'), 'notes' => ''];
        $lineValues = [['product_id' => '', 'description' => '', 'quantity' => '1', 'unit_price' => '', 'vat_rate' => '']];

        if ($request->isMethod('POST')) {
            $values = [
                'customer_id' => (string) $request->request->get('customer_id', ''),
                'valid_until' => (string) $request->request->get('valid_until', ''),
                'notes' => (string) $request->request->get('notes', ''),
            ];
            $submittedLines = $request->request->all('lines');
            // Normalize attacker-controlled form keys before using them as line positions.
            $lineValues = array_slice(array_values($submittedLines), 0, 10);
            if (!$this->isCsrfTokenValid('sales_quote_create', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            $customer = ctype_digit($values['customer_id']) ? $thirdParties->find((int) $values['customer_id']) : null;
            if (!$customer instanceof ThirdParty || $customer->getCategory() !== ThirdParty::CATEGORY_CUSTOMER) {
                $errors[] = 'Veuillez sélectionner un client valide.';
            }
            try {
                if ($values['valid_until'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $values['valid_until'])) {
                    throw new \InvalidArgumentException('Invalid date format.');
                }
                $validUntil = DateTimeImmutable::createFromFormat('!Y-m-d', $values['valid_until']);
                $dateErrors = DateTimeImmutable::getLastErrors();
                if ($validUntil === false || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
                    throw new \InvalidArgumentException('Invalid calendar date.');
                }
                if ($validUntil < new DateTimeImmutable('today')) {
                    $errors[] = 'La date de validité doit être aujourd’hui ou ultérieure.';
                }
            } catch (\Throwable) {
                $validUntil = new DateTimeImmutable('+30 days');
                $errors[] = 'Veuillez saisir une date de validité correcte.';
            }
            if (count($submittedLines) > 10) {
                $errors[] = 'Un devis ne peut pas contenir plus de 10 lignes.';
            }

            $parsedLines = [];
            foreach ($lineValues as $index => $line) {
                if (!is_array($line)) {
                    $errors[] = sprintf('Ligne %d : données invalides.', $index + 1);
                    continue;
                }
                $productId = trim((string) ($line['product_id'] ?? ''));
                $selectedProduct = null;
                if ($productId !== '') {
                    $selectedProduct = ctype_digit($productId) ? $productRepository->find((int) $productId) : null;
                    if (!$selectedProduct instanceof Product || !$selectedProduct->isActive()) {
                        $errors[] = sprintf('Ligne %d : produit sélectionné invalide ou archivé.', $index + 1);
                        continue;
                    }
                }
                $description = trim((string) ($line['description'] ?? ''));
                $quantity = trim((string) ($line['quantity'] ?? ''));
                $unitPrice = trim((string) ($line['unit_price'] ?? ''));
                $vatRate = trim((string) ($line['vat_rate'] ?? ''));
                if ($description === '' && $quantity === '' && $unitPrice === '' && $vatRate === '' && $selectedProduct === null) {
                    continue;
                }
                $description = $description !== '' ? $description : ($selectedProduct?->getName() ?? '');
                $quantity = $quantity !== '' ? $quantity : '1';
                $unitPrice = $unitPrice !== '' ? $unitPrice : ($selectedProduct?->getSalePrice() ?? '');
                $vatRate = $vatRate !== '' ? $vatRate : ($selectedProduct?->getVatRate() ?? '');
                try {
                    $quoteLine = new SalesQuoteLine($description, $quantity, $unitPrice, $vatRate, $index + 1);
                    $quoteLine->setProduct($selectedProduct);
                    $parsedLines[] = $quoteLine;
                } catch (InvalidArgumentException $exception) {
                    $errors[] = sprintf('Ligne %d : %s', $index + 1, $exception->getMessage());
                }
            }
            if ($parsedLines === []) {
                $errors[] = 'Ajoutez au moins une ligne au devis.';
            }

            if ($errors === [] && $customer instanceof ThirdParty) {
                $quote = new SalesQuote($customer);
                $quote->setValidUntil($validUntil)->setNotes($values['notes']);
                foreach ($parsedLines as $line) {
                    $quote->addLine($line);
                }
                $entityManager->getConnection()->transactional(static function () use ($entityManager, $quote): void {
                    $entityManager->persist($quote);
                    $entityManager->flush();
                    $quote->assignNumber();
                    $entityManager->flush();
                });

                $this->addFlash('success', sprintf('Le devis %s a été créé.', $quote->getNumber()));
                return $this->redirectToRoute('app_sales_quote_show', ['id' => $quote->getId()]);
            }
        }

        return $this->render('sales_quote/new.html.twig', [
            'customers' => $customers,
            'products' => $products,
            'values' => $values,
            'line_values' => $lineValues,
            'errors' => $errors,
        ]);
    }

    #[Route('/{id}/convert', name: 'app_sales_quote_convert', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function convertToOrder(Request $request, SalesQuote $quote, SalesOrderRepository $orders, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('sales_quote_convert_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if ($quote->getStatus() !== SalesQuote::STATUS_ACCEPTED) {
            throw $this->createAccessDeniedException('Seul un devis accepté peut être converti en commande.');
        }

        [$order, $created] = $entityManager->getConnection()->transactional(function () use ($entityManager, $orders, $quote): array {
            $entityManager->lock($quote, LockMode::PESSIMISTIC_WRITE);
            $existing = $orders->findOneBy(['sourceQuote' => $quote]);
            if ($existing instanceof SalesOrder) {
                return [$existing, false];
            }

            $order = new SalesOrder($quote);
            $entityManager->persist($order);
            $entityManager->flush();
            $order->assignNumber();
            $entityManager->flush();

            return [$order, true];
        });

        $this->addFlash('success', $created
            ? sprintf('La commande %s a été créée à partir du devis.', $order->getNumber())
            : 'Ce devis avait déjà été converti en commande.');
        return $this->redirectToRoute('app_sales_order_show', ['id' => $order->getId()]);
    }

    #[Route('/{id}/pdf', name: 'app_sales_quote_pdf', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function pdf(SalesQuote $quote): Response
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->renderView('sales_quote/pdf.html.twig', ['quote' => $quote]), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();

        return new Response($pdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s.pdf"', $quote->getNumber()),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/{id}', name: 'app_sales_quote_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(SalesQuote $quote, SalesOrderRepository $orders): Response
    {
        return $this->render('sales_quote/show.html.twig', [
            'quote' => $quote,
            'order' => $orders->findOneBy(['sourceQuote' => $quote]),
            'statuses' => self::STATUSES,
            'allowed_actions' => $this->allowedActions($quote->getStatus()),
        ]);
    }

    #[Route('/{id}/status', name: 'app_sales_quote_status', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function changeStatus(Request $request, SalesQuote $quote, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('sales_quote_status_'.$quote->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $target = (string) $request->request->get('status', '');
        if (!in_array($target, $this->allowedActions($quote->getStatus()), true)) {
            throw $this->createAccessDeniedException('Cette transition de statut n’est pas autorisée.');
        }
        $quote->setStatus($target);
        $entityManager->flush();
        $this->addFlash('success', 'Le statut du devis a été mis à jour.');

        return $this->redirectToRoute('app_sales_quote_show', ['id' => $quote->getId()]);
    }

    /** @return list<string> */
    private function allowedActions(string $status): array
    {
        return match ($status) {
            SalesQuote::STATUS_DRAFT => [SalesQuote::STATUS_SENT],
            SalesQuote::STATUS_SENT => [SalesQuote::STATUS_ACCEPTED, SalesQuote::STATUS_REJECTED],
            default => [],
        };
    }
}
