<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Entity\ProductStockMovement;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/products')]
final class ProductController extends AbstractController
{
    #[Route('', name: 'app_products', methods: ['GET'])]
    public function index(Request $request, ProductRepository $products): Response
    {
        $term = mb_substr(trim((string) $request->query->get('q', '')), 0, 120);
        $stock = (string) $request->query->get('stock', '');
        $active = (string) $request->query->get('active', '1');
        $total = $products->countSearchResults($term, $stock, $active);
        $pages = max(1, (int) ceil($total / 25));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        return $this->render('product/index.html.twig', [
            'products' => $products->search($term, $stock, $active, $page, 25),
            'search' => $term, 'selected_stock' => $stock, 'selected_active' => $active,
            'current_page' => $page, 'page_count' => $pages, 'total_count' => $total,
            'low_stock_count' => $products->countLowStock(),
        ]);
    }

    #[Route('/new', name: 'app_product_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ProductRepository $products, EntityManagerInterface $em): Response
    {
        $product = new Product('A-RENSEIGNER', 'Nouveau produit');
        $values = $this->values($product);
        $errors = [];
        if ($request->isMethod('POST')) {
            $values = $request->request->all();
            if (!$this->isCsrfTokenValid('product_create', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }
            try {
                $product->update($values);
                if ($products->findOneBy(['sku' => $product->getSku()]) !== null) {
                    throw new InvalidArgumentException('Cette référence SKU existe déjà.');
                }
                $em->persist($product);
                $em->flush();
                $this->addFlash('success', 'Le produit a été créé.');
                return $this->redirectToRoute('app_product_show', ['id' => $product->getId()]);
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        return $this->render('product/form.html.twig', ['product' => $product, 'values' => $values, 'errors' => $errors, 'is_edit' => false, 'token_id' => 'product_create']);
    }

    #[Route('/{id}', name: 'app_product_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(Product $product, EntityManagerInterface $em): Response
    {
        return $this->render('product/show.html.twig', [
            'product' => $product,
            'movements' => $em->getRepository(ProductStockMovement::class)->findBy(['product' => $product], ['createdAt' => 'DESC'], 30),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_product_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Product $product, ProductRepository $products, EntityManagerInterface $em): Response
    {
        $tokenId = 'product_edit_'.$product->getId();
        $values = $this->values($product);
        $errors = [];
        if ($request->isMethod('POST')) {
            $values = $request->request->all();
            if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }
            try {
                $product->update($values);
                $duplicate = $products->findOneBy(['sku' => $product->getSku()]);
                if ($duplicate !== null && $duplicate->getId() !== $product->getId()) {
                    throw new InvalidArgumentException('Cette référence SKU est déjà utilisée.');
                }
                $em->flush();
                $this->addFlash('success', 'Le produit a été mis à jour.');
                return $this->redirectToRoute('app_product_show', ['id' => $product->getId()]);
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        return $this->render('product/form.html.twig', ['product' => $product, 'values' => $values, 'errors' => $errors, 'is_edit' => true, 'token_id' => $tokenId]);
    }

    #[Route('/{id}/stock', name: 'app_product_stock', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function adjustStock(Request $request, Product $product, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('product_stock_'.$product->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        try {
            $em->getConnection()->transactional(function () use ($request, $product, $em): void {
                $em->lock($product, LockMode::PESSIMISTIC_WRITE);
                $quantity = (string) $request->request->get('quantity', '');
                $reason = (string) $request->request->get('reason', '');
                $note = (string) $request->request->get('note', '');
                $product->adjustStock($quantity);
                $em->persist(new ProductStockMovement($product, $quantity, $reason, $note));
                $em->flush();
            });
            $this->addFlash('success', 'Le stock a été ajusté et le mouvement enregistré.');
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }
        return $this->redirectToRoute('app_product_show', ['id' => $product->getId()]);
    }

    #[Route('/{id}/archive', name: 'app_product_archive', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function archive(Request $request, Product $product, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('product_archive_'.$product->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
        $product->setActive(false);
        $em->flush();
        $this->addFlash('success', 'Le produit a été archivé. Son historique est conservé.');
        return $this->redirectToRoute('app_products');
    }

    private function values(Product $product): array
    {
        return [
            'sku' => $product->getSku(), 'name' => $product->getName(),
            'description' => $product->getDescription() ?? '', 'unit' => $product->getUnit(),
            'purchase_price' => $product->getPurchasePrice(), 'sale_price' => $product->getSalePrice(),
            'vat_rate' => $product->getVatRate(), 'stock_tracked' => $product->isStockTracked() ? '1' : '0',
            'stock_alert_threshold' => $product->getStockAlertThreshold(), 'active' => $product->isActive() ? '1' : '0',
        ];
    }
}
