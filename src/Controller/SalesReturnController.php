<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\ProductStockMovement;
use App\Entity\SalesOrder;
use App\Entity\SalesReturn;
use App\Entity\SalesReturnLine;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/sales/orders/{id}/returns')]
final class SalesReturnController extends AbstractController
{
    #[Route('',name:'app_sales_return_create',requirements:['id'=>'\\d+'],methods:['POST'])]
    public function create(Request $request,SalesOrder $order,EntityManagerInterface $em): Response
    {
        if(!$this->isCsrfTokenValid('sales_return_'.$order->getId(),(string)$request->request->get('_token'))) throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        try {
            $salesReturn=$em->getConnection()->transactional(function() use($request,$order,$em): SalesReturn {
                $em->lock($order,LockMode::PESSIMISTIC_WRITE); $em->refresh($order);
                $salesReturn=new SalesReturn($order,(string)$request->request->get('notes','')); $count=0;
                $quantities=$request->request->all('quantities');
                foreach($order->getLines() as $line) {
                    $raw=trim((string)($quantities[(string)$line->getId()]??''));
                    if($raw==='') continue;
                    if(!is_numeric($raw)||round((float)$raw,3)<=0) throw new InvalidArgumentException('Chaque quantité retournée doit être un nombre strictement positif.');
                    $quantity=number_format((float)$raw,3,'.','');
                    $returnLine=new SalesReturnLine($line,$quantity);
                    $product=$line->getProduct();
                    if($product!==null&&$product->isStockTracked()) {
                        $em->lock($product,LockMode::PESSIMISTIC_WRITE);
                        $product->adjustStock($quantity);
                        $em->persist(new ProductStockMovement($product,$quantity,'return',sprintf('Retour client commande %s · ligne %d',$order->getNumber()??('#'.$order->getId()),$line->getId())));
                    }
                    $line->recordReturn($quantity); $salesReturn->addLine($returnLine); ++$count;
                }
                if($count===0) throw new InvalidArgumentException('Saisissez au moins une quantité à retourner.');
                $em->persist($salesReturn); $em->flush(); $salesReturn->assignNumber(); $em->flush();
                return $salesReturn;
            });
            $this->addFlash('success','Le retour '.$salesReturn->getNumber().' a été enregistré. Les quantités retournées ont été ajoutées au stock suivi.');
        } catch(InvalidArgumentException $e) { $this->addFlash('error',$e->getMessage()); }
          catch(\Throwable) { $this->addFlash('error','Le retour n’a pas été enregistré. Aucune entrée de stock n’a été confirmée.'); }
        return $this->redirectToRoute('app_sales_order_show',['id'=>$order->getId()]);
    }
}
