<?php
declare(strict_types=1);
namespace App\Controller;
use App\Service\AccountingAmount;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
#[Route('/accounting/vat')]
final class VatDeclarationController extends AbstractController
{
    #[Route('',name:'app_vat_declaration',methods:['GET'])]
    public function index(Connection $db,Request $request):Response
    {
        [$from,$to]=$this->period($request);
        $months=$this->rows($db,$from,$to);
        $collectedCents=0;$deductibleCents=0;
        foreach($months as &$month){
            $monthCollected=AccountingAmount::toCents((string)$month['collected']);
            $monthDeductible=AccountingAmount::toCents((string)$month['deductible']);
            $month['collected']=AccountingAmount::formatCents($monthCollected);
            $month['deductible']=AccountingAmount::formatCents($monthDeductible);
            $month['net']=AccountingAmount::formatCents(AccountingAmount::addSignedCents($monthCollected,-$monthDeductible));
            $collectedCents=AccountingAmount::addSignedCents($collectedCents,$monthCollected);$deductibleCents=AccountingAmount::addSignedCents($deductibleCents,$monthDeductible);
        }
        unset($month);
        return $this->render('vat_declaration/index.html.twig',['months'=>$months,'from'=>$from,'to'=>$to,'collected'=>AccountingAmount::formatCents($collectedCents),'deductible'=>AccountingAmount::formatCents($deductibleCents),'net'=>AccountingAmount::formatCents(AccountingAmount::addSignedCents($collectedCents,-$deductibleCents))]);
    }
    #[Route('/export.csv',name:'app_vat_declaration_export',methods:['GET'])]
    public function export(Connection $db,Request $request):Response
    {
        [$from,$to]=$this->period($request);$rows=$this->rows($db,$from,$to);
        $handle=fopen('php://temp','r+');
        if($handle===false)throw new \RuntimeException('Impossible de préparer l’export CSV.');
        fwrite($handle,"\xEF\xBB\xBF");
        fputcsv($handle,['Période','TVA collectée (€)','TVA déductible (€)','TVA nette (€)'],';');
        foreach($rows as $row){
            $collected=AccountingAmount::toCents((string)$row['collected']);
            $deductible=AccountingAmount::toCents((string)$row['deductible']);
            fputcsv($handle,[$row['period'],AccountingAmount::formatCents($collected),AccountingAmount::formatCents($deductible),AccountingAmount::formatCents(AccountingAmount::addSignedCents($collected,-$deductible))],';');
        }
        rewind($handle);$csv=stream_get_contents($handle);fclose($handle);
        return new Response($csv===false?'':$csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="declaration-tva-'.$from.'-'.$to.'.csv"','X-Content-Type-Options'=>'nosniff']);
    }
    private function rows(Connection $db,string $from,string $to):array
    {
        return $db->fetchAllAssociative(<<<'SQL'
SELECT DATE_FORMAT(e.entry_date,'%Y-%m') AS period,
       SUM(CASE WHEN a.code='445710' THEN l.credit-l.debit ELSE 0 END) AS collected,
       SUM(CASE WHEN a.code IN ('445660','445620') THEN l.debit-l.credit ELSE 0 END) AS deductible
FROM app_journal_entry_line l
INNER JOIN app_journal_entry e ON e.id=l.entry_id
INNER JOIN app_accounting_account a ON a.id=l.account_id
WHERE e.status='posted' AND e.entry_date BETWEEN ? AND ?
  AND a.code IN ('445710','445660','445620')
GROUP BY DATE_FORMAT(e.entry_date,'%Y-%m')
ORDER BY period
SQL,[$from,$to]);
    }
    private function period(Request $request):array
    {
        $from=(string)$request->query->get('from',(new DateTimeImmutable('first day of this month'))->format('Y-m-d'));
        $to=(string)$request->query->get('to',(new DateTimeImmutable('today'))->format('Y-m-d'));
        $a=DateTimeImmutable::createFromFormat('!Y-m-d',$from);$b=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
        if(!$a||!$b||$a->format('Y-m-d')!==$from||$b->format('Y-m-d')!==$to||$from>$to)throw new BadRequestHttpException('Période de déclaration TVA invalide.');
        return [$from,$to];
    }
}
