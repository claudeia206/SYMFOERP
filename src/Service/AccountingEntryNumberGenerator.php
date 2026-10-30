<?php
declare(strict_types=1);
namespace App\Service;
use App\Entity\AccountingJournal;
use App\Entity\AccountingFiscalYear;
use Doctrine\DBAL\Connection;
final class AccountingEntryNumberGenerator
{
 public function __construct(private readonly Connection $connection){}
 public function next(AccountingJournal $journal,AccountingFiscalYear $year):string{
  if($journal->getId()===null||$year->getId()===null)throw new \LogicException('Journal et exercice doivent être persistés avant de numéroter.');
  $prefix=$journal->getCode().'-'.$year->getCode().'-';
  $last=$this->connection->fetchOne('SELECT MAX(number) FROM app_accounting_entry WHERE journal_id = ? AND fiscal_year_id = ?',[$journal->getId(),$year->getId()]);
  $sequence=1;
  if(is_string($last)&&str_starts_with($last,$prefix)){
   $suffix=substr($last,strlen($prefix));
   if(preg_match('/^[0-9]{6,}$/D',$suffix))$sequence=(int)$suffix+1;
  }
  return $prefix.str_pad((string)$sequence,6,'0',STR_PAD_LEFT);
 }
}