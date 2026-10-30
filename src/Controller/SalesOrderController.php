<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\SalesOrder;
use App\Entity\ProductStockMovement;
use App\Repository\SalesOrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/sales/orders')]
final class SalesOrderController extends AbstractController
{
    #[Route('', name: 'app_sales_orders', methods: ['GET'])]
    public function index(SalesOrderRepository $orders): Response
    {
        return $this->render('sales_order/index.html.twig', [
            'orders' => $orders->findBy([], ['createdAt' => 'DESC'], 100),
        ]);
    }

    #[Route('/{id}', name: 'app_sales_order_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(SalesOrder $order): Response
    {
        return $this->render('sales_order/show.html.twig', [
            'order' => $order,
            'statuses' => [
                SalesOrder::STATUS_OPEN => 'En cours',
                SalesOrder::STATUS_FULFILLED => 'Terminée',
                SalesOrder::STATUS_CANCELLED => 'Annulée',
            ],
            'allowed_actions' => $this->allowedActions($order->getStatus()),
        ]);
    }
    #[Route('/{id}/status', name: 'app_sales_order_status', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function changeStatus(Request $request, SalesOrder $order, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('sales_order_status_'.$order->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $target = (string) $request->request->get('status', '');
        try {
            $entityManager->getConnection()->transactional(function () use ($target, $order, $entityManager): void {
                $entityManager->lock($order, LockMode::PESSIMISTIC_WRITE);
                $entityManager->refresh($order);
                if (!in_array($target, $this->allowedActions($order->getStatus()), true)) {
                    throw new InvalidArgumentException('Cette transition de statut n’est plus autorisée.');
                }

                if ($target === SalesOrder::STATUS_FULFILLED) {
                    // Lock each distinct product once, in a stable order. Refreshing
                    // per line would discard earlier unflushed changes for duplicate products.
                    $products = [];
                    $linesByProduct = [];
                    foreach ($order->getLines() as $line) {
                        $product = $line->getProduct();
                        if ($product === null || !$product->isStockTracked()) {
                            continue;
                        }
                        $productId = $product->getId();
                        if ($productId === null) {
                            throw new InvalidArgumentException('Un produit de la commande n’est pas enregistré.');
                        }
                        $products[$productId] = $product;
                        $linesByProduct[$productId][] = $line;
                    }

                    ksort($products, SORT_NUMERIC);
                    foreach ($products as $productId => $product) {
                        $entityManager->lock($product, LockMode::PESSIMISTIC_WRITE);
                        $entityManager->refresh($product);

                        $totalQuantity = 0.0;
                        foreach ($linesByProduct[$productId] as $line) {
                            $lineQuantity = (float) $line->getQuantity();
                            if ($lineQuantity <= 0 || round($lineQuantity, 3) !== $lineQuantity) {
                                throw new InvalidArgumentException('Une quantité de livraison est invalide.');
                            }
                            $totalQuantity += $lineQuantity;
                        }

                        $product->adjustStock(number_format(-$totalQuantity, 3, '.', ''));
                        foreach ($linesByProduct[$productId] as $line) {
                            $quantity = number_format(-(float) $line->getQuantity(), 3, '.', '');
                            $entityManager->persist(new ProductStockMovement(
                                $product,
                                $quantity,
                                'sale',
                                sprintf('Livraison commande %s · ligne %d', $order->getNumber() ?? ('#'.$order->getId()), $line->getId())
                            ));
                        }
                    }
                }

                $order->setStatus($target);
                $entityManager->flush();
            });
            $this->addFlash('success', $target === SalesOrder::STATUS_FULFILLED
                ? 'La commande est terminée et les sorties de stock ont été enregistrées.'
                : 'Le statut de la commande a été mis à jour.');
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('app_sales_order_show', ['id' => $order->getId()]);
    }

    /** @return list<string> */
    private function allowedActions(string $status): array
    {
        return match ($status) {
            SalesOrder::STATUS_OPEN => [SalesOrder::STATUS_FULFILLED, SalesOrder::STATUS_CANCELLED],
            default => [],
        };
    }
}
