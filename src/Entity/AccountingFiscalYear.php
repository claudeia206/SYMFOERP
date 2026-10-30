<?php
declare(strict_types=1);
namespace App\Entity;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name:'app_accounting_fiscal_year',uniqueConstraints:[new ORM\UniqueConstraint(name:'uniq_accounting_fiscal_year_code',columns:['code'])])]
#[ORM\Index(name:'idx_accounting_fiscal_year_dates',columns:['starts_on','ends_on','closed'])]
class AccountingFiscalYear {
 #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
 #[ORM\Column(length:9)] private string $code;
 #[ORM\Column(name:'starts_on',type:'date_immutable')] private DateTimeImmutable $startsOn;
 #[ORM\Column(name:'ends_on',type:'date_immutable')] private DateTimeImmutable $endsOn;
 #[ORM\Column(options:['default'=>false])] private bool $closed=false;
 public function __construct(string $code,DateTimeImmutable $startsOn,DateTimeImmutable $endsOn){if(trim($code)===''||mb_strlen($code)>9||$endsOn<$startsOn)throw new InvalidArgumentException('Exercice comptable invalide.');$this->code=trim($code);$this->startsOn=$startsOn;$this->endsOn=$endsOn;}
 public function getId():?int{return $this->id;} public function getCode():string{return $this->code;} public function getStartsOn():DateTimeImmutable{return $this->startsOn;} public function getEndsOn():DateTimeImmutable{return $this->endsOn;} public function isClosed():bool{return $this->closed;}
 public function contains(DateTimeImmutable $date):bool{return $date>=$this->startsOn&&$date<=$this->endsOn;} public function close():self{$this->closed=true;return $this;}
}