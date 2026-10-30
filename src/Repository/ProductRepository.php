<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Product> */
final class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /** @return list<Product> */
    public function search(string $term = '', string $stock = '', string $active = '', int $page = 1, int $perPage = 25): array
    {
        return $this->filtered($term, $stock, $active)
            ->orderBy('product.name', 'ASC')->addOrderBy('product.id', 'ASC')
            ->setFirstResult((max(1, $page) - 1) * max(1, min(100, $perPage)))
            ->setMaxResults(max(1, min(100, $perPage)))->getQuery()->getResult();
    }

    public function countSearchResults(string $term = '', string $stock = '', string $active = ''): int
    {
        return (int) $this->filtered($term, $stock, $active)->select('COUNT(product.id)')->getQuery()->getSingleScalarResult();
    }

    public function countLowStock(): int
    {
        return (int) $this->createQueryBuilder('product')
            ->select('COUNT(product.id)')
            ->andWhere('product.active = true')
            ->andWhere('product.stockTracked = true')
            ->andWhere('product.stockQuantity <= product.stockAlertThreshold')
            ->getQuery()->getSingleScalarResult();
    }

    private function filtered(string $term, string $stock, string $active): QueryBuilder
    {
        $query = $this->createQueryBuilder('product');
        if ($term !== '') {
            $query->andWhere('LOWER(product.sku) LIKE :term OR LOWER(product.name) LIKE :term')
                ->setParameter('term', '%'.mb_strtolower($term).'%');
        }
        if ($stock === 'low') {
            $query->andWhere('product.stockTracked = true')->andWhere('product.stockQuantity <= product.stockAlertThreshold');
        } elseif ($stock === 'available') {
            $query->andWhere('product.stockTracked = true')->andWhere('product.stockQuantity > product.stockAlertThreshold');
        }
        if ($active === '1') {
            $query->andWhere('product.active = true');
        } elseif ($active === '0') {
            $query->andWhere('product.active = false');
        }
        return $query;
    }
}
