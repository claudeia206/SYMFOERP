<?php
declare(strict_types=1);
namespace App\Controller;
use App\Service\AccountingAmount;
use App\Entity\CompanyProfile;
use App\Entity\ThirdParty;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/third-parties/{id}/account-statement',requirements:['id'=>'\d+'])]
final class CustomerAccountStatementController extends AbstractController
{
    #[Route('',name:'app_customer_account_statement',methods:['GET'])]
    public function show(ThirdParty $thirdParty,Connection $db):Response
    {
        if($thirdParty->getCategory()!==ThirdParty::CATEGORY_CUSTOMER)throw $this->createNotFoundException('Relevé disponible uniquement pour un client.');
        [$movements,$balance]=$this->buildStatement($db,(int)$thirdParty->getId(),new DateTimeImmutable('today'));
        return $this->render('customer_account_statement/show.html.twig',['customer'=>$thirdParty,'movements'=>$movements,'balance'=>$balance,'as_of'=>new DateTimeImmutable('today')]);
    }

    #[Route('/pdf',name:'app_customer_account_statement_pdf',methods:['GET'])]
    public function pdf(ThirdParty $thirdParty,Connection $db,EntityManagerInterface $em):Response
    {
        if($thirdParty->getCategory()!==ThirdParty::CATEGORY_CUSTOMER)throw $this->createNotFoundException('Relevé disponible uniquement pour un client.');
        $asOf=new DateTimeImmutable('today');
        [$movements,$balance]=$this->buildStatement($db,(int)$thirdParty->getId(),$asOf);
        $profile=$em->find(CompanyProfile::class,1);
        $options=new Options();$options->set('defaultFont','DejaVu Sans');$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);
        $dompdf=new Dompdf($options);
        $dompdf->loadHtml($this->renderView('customer_account_statement/pdf.html.twig',['customer'=>$thirdParty,'movements'=>$movements,'balance'=>$balance,'as_of'=>$asOf,'company'=>$profile]),'UTF-8');
        $dompdf->setPaper('A4','portrait');$dompdf->render();
        return new Response($dompdf->output(),200,['Content-Type'=>'application/pdf','Content-Disposition'=>'inline; filename="releve-client-'.$thirdParty->getId().'.pdf"','X-Content-Type-Options'=>'nosniff']);
    }

    /** @return array{0:list<array<string,mixed>>,1:string} */
    private function buildStatement(Connection $db,int $customerId,DateTimeImmutable $asOf):array
    {
        $rows=$db->fetchAllAssociative(<<<'SQL'
SELECT movement_date,kind,document,description,amount,sort_order,source_id FROM (
 SELECT i.invoice_date AS movement_date,'invoice' AS kind,i.number AS document,'Facture client' AS description,
        SUM(ROUND(l.quantity*l.unit_price,2)+ROUND(ROUND(l.quantity*l.unit_price,2)*l.vat_rate/100,2)) AS amount,1 AS sort_order,i.id AS source_id
 FROM app_sales_invoice i INNER JOIN app_sales_invoice_line l ON l.invoice_id=i.id
 WHERE i.third_party_id=? AND i.status NOT IN ('draft','cancelled') AND i.invoice_date<=?
 GROUP BY i.id,i.invoice_date,i.number
 UNION ALL
 SELECT p.paid_at AS movement_date,'payment' AS kind,COALESCE(p.reference,CONCAT('REG-',p.id)) AS document,'Règlement client' AS description,
        p.amount AS amount,2 AS sort_order,p.id AS source_id
 FROM app_sales_payment p INNER JOIN app_sales_invoice i ON i.id=p.invoice_id
 WHERE i.third_party_id=? AND p.paid_at<=?
 UNION ALL
 SELECT n.credit_date AS movement_date,'credit' AS kind,n.number AS document,'Avoir client' AS description,
        SUM(ROUND(l.quantity*l.unit_price,2)+ROUND(ROUND(l.quantity*l.unit_price,2)*l.vat_rate/100,2)) AS amount,3 AS sort_order,n.id AS source_id
 FROM app_sales_credit_note n INNER JOIN app_sales_credit_note_line l ON l.credit_note_id=n.id
 INNER JOIN app_sales_invoice i ON i.id=n.invoice_id
 WHERE i.third_party_id=? AND n.credit_date<=?
 GROUP BY n.id,n.credit_date,n.number
) movements ORDER BY movement_date ASC,sort_order ASC,source_id ASC
SQL,[$customerId,$asOf->format('Y-m-d'),$customerId,$asOf->format('Y-m-d'),$customerId,$asOf->format('Y-m-d')]);
        $balanceCents=0;$result=[];
        foreach($rows as $row){
            $amountCents=AccountingAmount::toCents((string)$row['amount']);
            $isDebit=$row['kind']==='invoice';
            $balanceCents=AccountingAmount::addSignedCents($balanceCents,$isDebit?$amountCents:-$amountCents);
            $result[]=$row+[
                'amount'=>AccountingAmount::formatCents($amountCents),
                'debit'=>AccountingAmount::formatCents($isDebit?$amountCents:0),
                'credit'=>AccountingAmount::formatCents($isDebit?0:$amountCents),
                'balance'=>AccountingAmount::formatCents($balanceCents),
            ];
        }
        return [$result,AccountingAmount::formatCents($balanceCents)];
    }
}
