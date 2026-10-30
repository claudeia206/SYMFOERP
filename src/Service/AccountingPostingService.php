<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\AccountingEntry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;
final class AccountingPostingService
{
 public function __construct(private readonly EntityManagerInterface $em){}
 public function post(AccountingEntry $entry):void{
  $this->em->wrapInTransaction(function()use($entry):void{
   $year=$entry->getFiscalYear();
   $this->em->lock($year,LockMode::PESSIMISTIC_WRITE);
   $this->em->refresh($year);
   if($year->isClosed()||!$year->contains($entry->getEntryDate()))throw new InvalidArgumentException('Exercice comptable fermé ou date hors exercice.');
   $entry->validateForPosting();
   $entry->post();
   $this->em->flush();
  });
 }
}