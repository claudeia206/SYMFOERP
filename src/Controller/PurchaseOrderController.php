<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ProductStockMovement;
use App\Entity\PurchaseOrder;
use App\Entity\PurchaseOrderLine;
use App\Entity\ThirdParty;
use App\Repository\ProductRepository;
use App\Repository\PurchaseOrderRepository;
use App\Repository\ThirdPartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/purchases')]
final class PurchaseOrderController extends AbstractController
{
    #[Route('', name: 'app_purchase_orders', methods: ['GET'])]
    public function index(PurchaseOrderRepository $orders): Response
    {
        return $this->render('purchase_order/index.html.twig', [
            'orders' => $orders->findBy([], ['createdAt' => 'DESC'], 100),
            'statuses' => $this->statuses(),
        ]);
    }

    #[Route('/new', name: 'app_purchase_order_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ThirdPartyRepository $thirdParties, ProductRepository $products, EntityManagerInterface $em): Response
    {
        $suppliers = $thirdParties->findBy(['category' => ThirdParty::CATEGORY_SUPPLIER], ['name' => 'ASC']);
        $activeProducts = $products->findBy(['active' => true], ['name' => 'ASC']);
        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('purchase_order_create', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }
            try {
                $supplier = $thirdParties->find((int) $request->request->get('supplier_id'));
                if (!$supplier instanceof ThirdParty || $supplier->getCategory() !== ThirdParty::CATEGORY_SUPPLIER) {
                    throw new InvalidArgumentException('Sélectionnez un fournisseur valide.');
                }
                $order = new PurchaseOrder($supplier);
                $order->setNotes((string) $request->request->get('notes', ''));
                $rawLines = $request->request->all('lines');
                $position = 0;
                foreach ($rawLines as $rawLine) {
                    if (!is_array($rawLine) || (int) ($rawLine['product_id'] ?? 0) < 1) { continue; }
                    $product = $products->find((int) $rawLine['product_id']);
                    if ($product === null) { throw new InvalidArgumentException('Un produit sélectionné est introuvable.'); }
                    $order->addLine(new PurchaseOrderLine($product, (string) ($rawLine['quantity'] ?? ''), ++$position));
                }
                if ($position === 0) { throw new InvalidArgumentException('Ajoutez au moins une ligne produit.'); }
                $order->setStatus(PurchaseOrder::STATUS_SENT);
                $em->persist($order);
                $em->flush();
                $order->assignNumber();
                $em->flush();
                $this->addFlash('success', 'La commande fournisseur a été créée.');
                return $this->redirectToRoute('app_purchase_order_show', ['id' => $order->getId()]);
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        return $this->render('purchase_order/new.html.twig', [
            'suppliers' => $suppliers, 'products' => $activeProducts, 'errors' => $errors,
        ]);
    }

    #[Route('/{id}', name: 'app_purchase_order_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(PurchaseOrder $order): Response
    {
        return $this->render('purchase_order/show.html.twig', [
            'order' => $order, 'statuses' => $this->statuses(),
            'can_receive' => in_array($order->getStatus(), [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIALLY_RECEIVED], true),
        ]);
    }

    #[Route('/{id}/receive', name: 'app_purchase_order_receive', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function receive(Request $request, PurchaseOrder $order, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('purchase_order_receive_'.$order->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        try {
            $em->getConnection()->transactional(function () use ($request, $order, $em): void {
                $em->lock($order, LockMode::PESSIMISTIC_WRITE);
                $em->refresh($order);
                if (!in_array($order->getStatus(), [PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_PARTIALLY_RECEIVED], true)) {
                    throw new InvalidArgumentException('Cette commande ne peut plus être réceptionnée.');
                }
                $quantities = $request->request->all('received');
                foreach ($order->getLines() as $line) {
                    $quantity = trim((string) ($quantities[(string) $line->getId()] ?? ''));
                    if ($quantity === '') { continue; }
                    if (!is_numeric($quantity)) { throw new InvalidArgumentException('Une quantité réceptionnée doit être numérique.'); }
                    if ((float) $quantity == 0.0) { continue; }
                    $product = $line->getProduct();
                    $em->lock($product, LockMode::PESSIMISTIC_WRITE);
                    $line->receive($quantity);
                    if ($product->isStockTracked()) {
                        $product->adjustStock($quantity);
                        $em->persist(new ProductStockMovement(
                            $product,
                            $quantity,
                            'purchase',
                            sprintf('Réception %s · ligne %d', $order->getNumber() ?? ('#'.$order->getId()), $line->getId())
                        ));
                    }
                }
                $order->refreshReceiptStatus();
                $em->flush();
            });
            $this->addFlash('success', 'Les quantités réceptionnées ont été enregistrées et le stock suivi a été mis à jour.');
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('app_purchase_order_show', ['id' => $order->getId()]);
    }

    /** @return array<string, string> */
    private function statuses(): array
    {
        return [
            PurchaseOrder::STATUS_DRAFT => 'Brouillon',
            PurchaseOrder::STATUS_SENT => 'Envoyée',
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED => 'Partiellement réceptionnée',
            PurchaseOrder::STATUS_RECEIVED => 'Réceptionnée',
            PurchaseOrder::STATUS_CANCELLED => 'Annulée',
        ];
    }
}
