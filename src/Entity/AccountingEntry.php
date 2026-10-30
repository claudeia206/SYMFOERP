<?php
declare(strict_types=1);
namespace App\Entity;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
#[ORM\Entity]
#[ORM\Table(name:'app_accounting_entry',uniqueConstraints:[new ORM\UniqueConstraint(name:'uniq_accounting_entry_journal_number',columns:['journal_id','number']),new ORM\UniqueConstraint(name:'uniq_accounting_entry_source',columns:['source_type','source_id'])])]
#[ORM\Index(name:'idx_accounting_entry_date',columns:['entry_date','fiscal_year_id'])]
class AccountingEntry {
 #[ORM\Id,ORM\GeneratedValue,ORM\Column] private ?int $id=null;
 #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false,onDelete:'RESTRICT')] private AccountingJournal $journal;
 #[ORM\ManyToOne] #[ORM\JoinColumn(name:'fiscal_year_id',nullable:false,onDelete:'RESTRICT')] private AccountingFiscalYear $fiscalYear;
 #[ORM\Column(length:32)] private string $number;
 #[ORM\Column(name:'entry_date',type:'date_immutable')] private DateTimeImmutable $entryDate;
 #[ORM\Column(length:255)] private string $label;
 #[ORM\Column(length:20)] private string $status='draft';
 #[ORM\Column(name:'source_type',length:40,nullable:true)] private ?string $sourceType=null;
 #[ORM\Column(name:'source_id',nullable:true)] private ?int $sourceId=null;
 #[ORM\Column(name:'created_at',type:'datetime_immutable')] private DateTimeImmutable $createdAt;
 /** @var Collection<int,AccountingEntryLine> */ #[ORM\OneToMany(mappedBy:'entry',targetEntity:AccountingEntryLine::class,cascade:['persist'],orphanRemoval:true)] #[ORM\OrderBy(['position'=>'ASC','id'=>'ASC'])] private Collection $lines;
 public function __construct(AccountingJournal $journal,AccountingFiscalYear $year,string $number,DateTimeImmutable $date,string $label){if(!$journal->isActive()||$year->isClosed()||!$year->contains($date)||!preg_match('/^[A-Z0-9_-]{1,32}$/D',$number)||trim($label)==='')throw new InvalidArgumentException('En-tête d’écriture invalide ou exercice fermé.');$this->journal=$journal;$this->fiscalYear=$year;$this->number=$number;$this->entryDate=$date;$this->label=mb_substr(trim($label),0,255);$this->createdAt=new DateTimeImmutable();$this->lines=new ArrayCollection();}
 public function getId():?int{return $this->id;} public function getJournal():AccountingJournal{return $this->journal;} public function getFiscalYear():AccountingFiscalYear{return $this->fiscalYear;} public function getNumber():string{return $this->number;} public function getEntryDate():DateTimeImmutable{return $this->entryDate;} public function getLabel():string{return $this->label;} public function getStatus():string{return $this->status;} /** @return Collection<int,AccountingEntryLine> */ public function getLines():Collection{return $this->lines;}
 public function setSource(string $type,int $id):self{if(trim($type)===''||strlen($type)>40||$id<1)throw new InvalidArgumentException('Source comptable invalide.');$this->sourceType=$type;$this->sourceId=$id;return $this;} public function getSourceType():?string{return $this->sourceType;} public function getSourceId():?int{return $this->sourceId;}
 public function addLine(AccountingEntryLine $line):self{if($this->status!=='draft')throw new InvalidArgumentException('Écriture non modifiable.');$this->lines->add($line);$line->setEntry($this);return $this;}
 public function totals():array{$debit=0;$credit=0;foreach($this->lines as $line){$debit+=$line->getDebitCents();$credit+=$line->getCreditCents();}return ['debit'=>$debit,'credit'=>$credit];}
 public function validateForPosting():self{$t=$this->totals();if($this->status!=='draft'||$this->lines->count()<2||$t['debit']<=0||$t['debit']!==$t['credit'])throw new InvalidArgumentException('Une écriture doit comporter au moins deux lignes équilibrées.');return $this;}
 public function post():self{$this->validateForPosting();$this->status='posted';return $this;}
}