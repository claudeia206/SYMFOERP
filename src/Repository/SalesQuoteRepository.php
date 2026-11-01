<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SalesQuote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<SalesQuote> */
final class SalesQuoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SalesQuote::class);
    }

    /** @return list<SalesQuote> */
    public function search(string $term = '', string $status = '', int $page = 1, int $perPage = 25): array
    {
        $query = $this->createQueryBuilder('quote')
            ->join('quote.customer', 'customer')
            ->addSelect('customer')
            ->orderBy('quote.createdAt', 'DESC')
            ->addOrderBy('quote.id', 'DESC');

        if ($term !== '') {
            $query->andWhere('LOWER(quote.number) LIKE :term OR LOWER(customer.name) LIKE :term')
                ->setParameter('term', '%'.mb_strtolower($term).'%');
        }
        if (in_array($status, [SalesQuote::STATUS_DRAFT, SalesQuote::STATUS_SENT, SalesQuote::STATUS_ACCEPTED, SalesQuote::STATUS_REJECTED], true)) {
            $query->andWhere('quote.status = :status')->setParameter('status', $status);
        }

        return $query->setFirstResult((max(1, $page) - 1) * max(1, min(100, $perPage)))
            ->setMaxResults(max(1, min(100, $perPage)))
            ->getQuery()->getResult();
    }

    public function countSearchResults(string $term = '', string $status = ''): int
    {
        $query = $this->createQueryBuilder('quote')->join('quote.customer', 'customer')->select('COUNT(quote.id)');
        if ($term !== '') {
            $query->andWhere('LOWER(quote.number) LIKE :term OR LOWER(customer.name) LIKE :term')
                ->setParameter('term', '%'.mb_strtolower($term).'%');
        }
        if (in_array($status, [SalesQuote::STATUS_DRAFT, SalesQuote::STATUS_SENT, SalesQuote::STATUS_ACCEPTED, SalesQuote::STATUS_REJECTED], true)) {
            $query->andWhere('quote.status = :status')->setParameter('status', $status);
        }
        return (int) $query->getQuery()->getSingleScalarResult();
    }
}
