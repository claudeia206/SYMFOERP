<?php
declare(strict_types=1);

namespace App\Controller;

use App\Entity\AccountingEntryLine;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/accounting/lettering')]
final class AccountingLetteringController extends AbstractController
{
    #[Route('', name: 'app_accounting_lettering', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $em): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('accounting_lettering', $request->request->getString('_token'))) {
                $this->addFlash('error', 'Jeton de sécurité invalide.');
                return $this->redirectToRoute('app_accounting_lettering');
            }

            try {
                $rawIds = $request->request->all('line_ids');
                if (count($rawIds) < 2 || count($rawIds) > 100) {
                    throw new InvalidArgumentException('Sélectionnez entre 2 et 100 lignes à lettrer.');
                }
                $ids = array_values(array_unique(array_map(static function ($id): int {
                    if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $id)) {
                        throw new InvalidArgumentException('Identifiant de ligne invalide.');
                    }
                    return (int) $id;
                }, $rawIds)));
                if (count($ids) !== count($rawIds) || count($ids) < 2) {
                    throw new InvalidArgumentException('Les lignes sélectionnées doivent être distinctes.');
                }
                sort($ids, SORT_NUMERIC);

                $em->wrapInTransaction(function () use ($em, $ids): void {
                    $lines = $em->createQueryBuilder()
                        ->select('l', 'e', 'a')
                        ->from(AccountingEntryLine::class, 'l')
                        ->join('l.entry', 'e')
                        ->join('l.account', 'a')
                        ->where('l.id IN (:ids)')
                        ->andWhere('l.matchingCode IS NULL')
                        ->andWhere('e.status = :status')
                        ->andWhere('a.class = :accountClass')
                        ->setParameter('ids', $ids)
                        ->setParameter('status', 'posted')
                        ->setParameter('accountClass', '4')
                        ->orderBy('l.id', 'ASC')
                        ->getQuery()
                        ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                        ->getResult();

                    if (count($lines) !== count($ids)) {
                        throw new InvalidArgumentException('Une ligne est déjà lettrée, non comptabilisée ou indisponible.');
                    }

                    $accountId = $lines[0]->getAccount()->getId();
                    $net = 0;
                    foreach ($lines as $line) {
                        if ($line->getAccount()->getId() !== $accountId) {
                            throw new InvalidArgumentException('Toutes les lignes doivent appartenir au même compte auxiliaire.');
                        }
                        $net += $line->getDebitCents() - $line->getCreditCents();
                    }
                    if ($net !== 0) {
                        throw new InvalidArgumentException('Le lettrage doit être équilibré au centime près.');
                    }

                    $code = 'LET-' . strtoupper(bin2hex(random_bytes(8)));
                    foreach ($lines as $line) {
                        $line->setMatchingCode($code);
                    }
                    $em->flush();
                });

                $this->addFlash('success', 'Lettrage enregistré. Les lignes restent consultables dans le grand livre.');
            } catch (\Throwable $error) {
                $this->addFlash('error', $error instanceof InvalidArgumentException ? $error->getMessage() : 'Impossible d’enregistrer ce lettrage.');
            }

            return $this->redirectToRoute('app_accounting_lettering');
        }

        $lines = $em->createQueryBuilder()
            ->select('l', 'e', 'j', 'a')
            ->from(AccountingEntryLine::class, 'l')
            ->join('l.entry', 'e')
            ->join('e.journal', 'j')
            ->join('l.account', 'a')
            ->where('l.matchingCode IS NULL')
            ->andWhere('e.status = :status')
            ->andWhere('a.class = :accountClass')
            ->setParameter('status', 'posted')
            ->setParameter('accountClass', '4')
            ->orderBy('a.number', 'ASC')
            ->addOrderBy('e.entryDate', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        $groups = [];
        foreach ($lines as $line) {
            $accountId = $line->getAccount()->getId();
            $groups[$accountId]['account'] = $line->getAccount();
            $groups[$accountId]['lines'][] = $line;
        }

        return $this->render('accounting/lettering.html.twig', ['groups' => $groups]);
    }
}
