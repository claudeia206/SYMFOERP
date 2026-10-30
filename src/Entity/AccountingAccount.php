<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name: 'app_accounting_account', uniqueConstraints: [new ORM\UniqueConstraint(name: 'uniq_accounting_account_number', columns: ['number'])])]
#[ORM\Index(name: 'idx_accounting_account_class', columns: ['class', 'active'])]
class AccountingAccount {
 #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id=null;
 #[ORM\Column(length:12)] private string $number;
 #[ORM\Column(length:180)] private string $label;
 #[ORM\Column(length:1)] private string $class;
 #[ORM\Column(options:['default'=>true])] private bool $active=true;
 public function __construct(string $number,string $label){$this->rename($number,$label);}
 public function getId():?int{return $this->id;} public function getNumber():string{return $this->number;} public function getLabel():string{return $this->label;} public function getClass():string{return $this->class;} public function isActive():bool{return $this->active;}
 public function rename(string $number,string $label):self{$number=trim($number);$label=trim($label);if(!preg_match('/^[1-7][0-9A-Za-z]{0,11}$/D',$number)||$label===''||mb_strlen($label)>180)throw new InvalidArgumentException('Compte comptable invalide.');$this->number=$number;$this->label=$label;$this->class=$number[0];return $this;}
 public function deactivate():self{$this->active=false;return $this;}
}