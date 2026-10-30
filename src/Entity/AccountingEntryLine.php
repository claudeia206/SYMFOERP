<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name:'app_accounting_entry_line')]
#[ORM\Index(name:'idx_accounting_entry_line_account',columns:['account_id'])]
class AccountingEntryLine {
 #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
 #[ORM\ManyToOne(inversedBy:'lines',targetEntity:AccountingEntry::class)] #[ORM\JoinColumn(nullable:false,onDelete:'CASCADE')] private ?AccountingEntry $entry=null;
 #[ORM\ManyToOne] #[ORM\JoinColumn(name:'account_id',nullable:false,onDelete:'RESTRICT')] private AccountingAccount $account;
 #[ORM\Column(length:255)] private string $label;
 #[ORM\Column(type:'decimal',precision:14,scale:2)] private string $debit='0.00';
 #[ORM\Column(type:'decimal',precision:14,scale:2)] private string $credit='0.00';
 #[ORM\Column] private int $position;
 public function __construct(AccountingAccount $account,string $label,string $debit,string $credit,int $position){
  if(!$account->isActive()||trim($label)===''||$position<1||!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D',$debit)||!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D',$credit))throw new InvalidArgumentException('Ligne comptable invalide.');
  $d=self::toCents($debit);$c=self::toCents($credit);
  if(($d>0&&$c>0)||($d===0&&$c===0))throw new InvalidArgumentException('Une ligne doit être débit ou crédit, jamais les deux.');
  $this->account=$account;$this->label=mb_substr(trim($label),0,255);$this->debit=self::fromCents($d);$this->credit=self::fromCents($c);$this->position=$position;
 }
 private static function toCents(string $amount):int{[$whole,$fraction]=array_pad(explode('.',$amount,2),2,'');return ((int)$whole*100)+(int)str_pad($fraction,2,'0');}
 private static function fromCents(int $cents):string{return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);}
 public function setEntry(AccountingEntry $entry):void{$this->entry=$entry;}
 public function getDebitCents():int{return self::toCents($this->debit);}
 public function getCreditCents():int{return self::toCents($this->credit);}
 public function getEntry():?AccountingEntry{return $this->entry;}
 public function getAccount():AccountingAccount{return $this->account;}
 public function getLabel():string{return $this->label;}
 public function getDebit():string{return $this->debit;}
 public function getCredit():string{return $this->credit;}
}