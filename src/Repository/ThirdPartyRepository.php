<?php

namespace App\Repository;

use App\Entity\ThirdParty;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ThirdParty>
 */
final class ThirdPartyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThirdParty::class);
    }

    /**
     * @return list<ThirdParty>
     */
    public function findRecent(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));

        return $this->createQueryBuilder('thirdParty')
            ->orderBy('thirdParty.createdAt', 'DESC')
            ->addOrderBy('thirdParty.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ThirdParty>
     */
    public function search(string $term = '', string $category = '', int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min($perPage, 100));

        return $this->createFilteredQuery($term, $category)
            ->orderBy('thirdParty.name', 'ASC')
            ->addOrderBy('thirdParty.id', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countSearchResults(string $term = '', string $category = ''): int
    {
        return (int) $this->createFilteredQuery($term, $category)
            ->select('COUNT(thirdParty.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function createFilteredQuery(string $term, string $category): QueryBuilder
    {
        $query = $this->createQueryBuilder('thirdParty');

        if ($term !== '') {
            $query->andWhere($query->expr()->orX(
                'LOWER(thirdParty.name) LIKE :term',
                'LOWER(thirdParty.email) LIKE :term',
                'LOWER(thirdParty.phone) LIKE :term'
            ))->setParameter('term', '%'.mb_strtolower($term).'%');
        }

        if (in_array($category, [
            ThirdParty::CATEGORY_CUSTOMER,
            ThirdParty::CATEGORY_PROSPECT,
            ThirdParty::CATEGORY_SUPPLIER,
        ], true)) {
            $query->andWhere('thirdParty.category = :category')->setParameter('category', $category);
        }

        return $query;
    }
}
