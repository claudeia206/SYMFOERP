<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name:'app_accounting_journal',uniqueConstraints:[new ORM\UniqueConstraint(name:'uniq_accounting_journal_code',columns:['code'])])]
class AccountingJournal {
 #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
 #[ORM\Column(length:8)] private string $code;
 #[ORM\Column(length:120)] private string $label;
 #[ORM\Column(options:['default'=>true])] private bool $active=true;
 public function __construct(string $code,string $label){$code=strtoupper(trim($code));$label=trim($label);if(!preg_match('/^[A-Z0-9]{2,8}$/D',$code)||$label===''||mb_strlen($label)>120)throw new InvalidArgumentException('Journal comptable invalide.');$this->code=$code;$this->label=$label;}
 public function getId():?int{return $this->id;} public function getCode():string{return $this->code;} public function getLabel():string{return $this->label;} public function isActive():bool{return $this->active;}
}