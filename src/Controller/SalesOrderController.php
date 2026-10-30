<?php
declare(strict_types=1);
namespace App\Controller;
use App\Entity\ProductStockMovement;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Entity\SalesDelivery;
use App\Entity\SalesDeliveryLine;
use App\Entity\SalesOrder;
use App\Repository\SalesOrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
#[Route('/sales/orders')]
final class SalesOrderController extends AbstractController
{
    #[Route('', name: 'app_sales_orders', methods: ['GET'])]
    public function index(SalesOrderRepository $orders): Response
    {
        return $this->render('sales_order/index.html.twig',['orders'=>$orders->findBy([],['createdAt'=>'DESC'],100)]);
    }
    #[Route('/{id}', name: 'app_sales_order_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(SalesOrder $order,EntityManagerInterface $em): Response
    {
        return $this->render('sales_order/show.html.twig',[
            'order'=>$order,
            'deliveries'=>$em->getRepository(SalesDelivery::class)->findBy(['order'=>$order],['deliveryDate'=>'DESC','id'=>'DESC']),
            'statuses'=>[SalesOrder::STATUS_OPEN=>'En cours',SalesOrder::STATUS_FULFILLED=>'Terminée',SalesOrder::STATUS_CANCELLED=>'Annulée'],
            'allowed_actions'=>$this->allowedActions($order->getStatus()),
        ]);
    }
    #[Route('/{id}/deliveries', name: 'app_sales_order_delivery_create', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function createDelivery(Request $request,SalesOrder $order,EntityManagerInterface $em): Response
    {
        if(!$this->isCsrfTokenValid('sales_order_delivery_'.$order->getId(),(string)$request->request->get('_token'))) throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        try {
            $delivery=$em->getConnection()->transactional(function() use($request,$order,$em): SalesDelivery {
                $em->lock($order,LockMode::PESSIMISTIC_WRITE); $em->refresh($order);
                if($order->getStatus()!==SalesOrder::STATUS_OPEN) throw new InvalidArgumentException('Cette commande ne peut plus être livrée.');
                return $this->registerDelivery($order,$request->request->all('quantities'),(string)$request->request->get('notes',''),$em);
            });
            $this->addFlash('success','Le bon de livraison '.$delivery->getNumber().' a été créé. Les quantités et les mouvements de stock sont enregistrés.');
        } catch(InvalidArgumentException $e) { $this->addFlash('error',$e->getMessage()); }
          catch(\Throwable) { $this->addFlash('error','La livraison n’a pas été enregistrée. Aucune sortie de stock n’a été confirmée.'); }
        return $this->redirectToRoute('app_sales_order_show',['id'=>$order->getId()]);
    }
    #[Route('/{id}/status', name: 'app_sales_order_status', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function changeStatus(Request $request,SalesOrder $order,EntityManagerInterface $em): Response
    {
        if(!$this->isCsrfTokenValid('sales_order_status_'.$order->getId(),(string)$request->request->get('_token'))) throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        $target=(string)$request->request->get('status','');
        try {
            $em->getConnection()->transactional(function() use($target,$order,$em): void {
                $em->lock($order,LockMode::PESSIMISTIC_WRITE); $em->refresh($order);
                if(!in_array($target,$this->allowedActions($order->getStatus()),true)) throw new InvalidArgumentException('Cette transition de statut n’est plus autorisée.');
                if($target===SalesOrder::STATUS_FULFILLED) {
                    $remaining=[];
                    foreach($order->getLines() as $line) if((float)$line->getRemainingQuantity()>0) $remaining[(string)$line->getId()]=$line->getRemainingQuantity();
                    if($remaining!==[]) $this->registerDelivery($order,$remaining,'Livraison complète générée depuis la clôture de commande.',$em);
                    if($order->getStatus()!==SalesOrder::STATUS_FULFILLED) $order->setStatus(SalesOrder::STATUS_FULFILLED);
                } else { $order->setStatus($target); }
                $em->flush();
            });
            $this->addFlash('success',$target===SalesOrder::STATUS_FULFILLED?'La commande est terminée et un bon de livraison final a été enregistré.':'Le statut de la commande a été mis à jour.');
        } catch(InvalidArgumentException $e) { $this->addFlash('error',$e->getMessage()); }
          catch(\Throwable) { $this->addFlash('error','La mise à jour a échoué. Aucune opération de stock n’a été confirmée.'); }
        return $this->redirectToRoute('app_sales_order_show',['id'=>$order->getId()]);
    }
    private function registerDelivery(SalesOrder $order,array $quantities,string $notes,EntityManagerInterface $em): SalesDelivery
    {
        $delivery=new SalesDelivery($order,$notes); $count=0;
        foreach($order->getLines() as $line) {
            $raw=trim((string)($quantities[(string)$line->getId()]??''));
            if($raw==='') continue;
            if(!is_numeric($raw)||round((float)$raw,3)<=0) throw new InvalidArgumentException('Chaque quantité livrée doit être un nombre strictement positif.');
            $quantity=number_format((float)$raw,3,'.','');
            $deliveryLine=new SalesDeliveryLine($line,$quantity);
            $product=$line->getProduct();
            if($product!==null&&$product->isStockTracked()) {
                $em->lock($product,LockMode::PESSIMISTIC_WRITE);
                $out=number_format(-(float)$quantity,3,'.','');
                $product->adjustStock($out);
                $em->persist(new ProductStockMovement($product,$out,'sale',sprintf('Livraison commande %s · ligne %d',$order->getNumber()??('#'.$order->getId()),$line->getId())));
            }
            $line->recordDelivery($quantity); $delivery->addLine($deliveryLine); ++$count;
        }
        if($count===0) throw new InvalidArgumentException('Saisissez au moins une quantité à livrer.');
        $em->persist($delivery); $em->flush(); $delivery->assignNumber(); $em->flush();
        $allDelivered=true;
        foreach($order->getLines() as $line) if((float)$line->getRemainingQuantity()>0.000001) { $allDelivered=false; break; }
        if($allDelivered) $order->setStatus(SalesOrder::STATUS_FULFILLED);
        return $delivery;
    }

    #[Route('/{id}/deliveries/{deliveryId}/pdf', name: 'app_sales_delivery_pdf', requirements: ['id' => '\\d+', 'deliveryId' => '\\d+'], methods: ['GET'])]
    public function deliveryPdf(SalesOrder $order, int $deliveryId, EntityManagerInterface $em): Response
    {
        $delivery = $em->find(SalesDelivery::class, $deliveryId);
        if (!$delivery instanceof SalesDelivery || $delivery->getOrder()->getId() !== $order->getId()) {
            throw $this->createNotFoundException('Ce bon de livraison ne correspond pas à cette commande.');
        }
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->renderView('sales_delivery/pdf.html.twig', [
            'delivery' => $delivery,
            'order' => $order,
        ]), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        return new Response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$delivery->getNumber().'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return list<string> */
    private function allowedActions(string $status): array
    {
        return match($status) { SalesOrder::STATUS_OPEN=>[SalesOrder::STATUS_FULFILLED,SalesOrder::STATUS_CANCELLED], default=>[] };
    }
}
