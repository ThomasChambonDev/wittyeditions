<?php

namespace Witty\OrderBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use JMS\SecurityExtraBundle\Annotation\Secure;

class OrderController extends Controller
{
    /**
     * @Route("commander", name="order_commander")
     * @Template()
	 * @Secure(roles="ROLE_USER")
     */
    public function commanderAction()
    {
		$em = $this->getDoctrine()->getEntityManager();
		$products = $em->getRepository('WittyOrderBundle:Product')->findAllOrderedByPriority();
		
        return array(
			'products' => $products, 
			'franco' => $this->container->getParameter('witty.distribution.franco'), 
			'frais_port' => $this->container->getParameter('witty.distribution.frais_port'), 
			'tva' => $this->container->getParameter('witty.distribution.tva')
		);
    }
	
    /**
     * @Route("confirmation-commande", name="order_confirmationCommande")
     * @Template()
	 * @Secure(roles="ROLE_USER")
     */
    public function confirmationCommandeAction()
    {
		$em = $this->getDoctrine()->getEntityManager();
		$user = $this->container->get('security.context')->getToken()->getUser();
		$request = $this->getRequest();
		$order = new \Witty\OrderBundle\Entity\Order();

		//Ajout de l'user
		$order->setUser($user);
		
		//Récupération et sauvegarde de la commande
		foreach($request->get('customerOrders') as $id_product => $quantity)
		{
			if ($quantity > 0)
			{
				$product = $em->getRepository('WittyOrderBundle:Product')->find($id_product);
			
				$orderProduct = new \Witty\OrderBundle\Entity\OrderProduct();
				$orderProduct->setProduct($product);
				$orderProduct->setQuantity($quantity);
				$orderProduct->setUnitPrice($product->getUnitPrice()); //"Redondance" de l'information unitPrice pour que l'on puisse changer le prix d'un Product sans en créer un nouveau, et tout de même garder l'historique du prix payé par l'utilisateur

				$order->addOrderProduct($orderProduct);
				$orderProduct->setOrder($order);
			}
		}
		
		//Gestion de la promo
		if ($customerPromo = $request->get('promo'))
			if ($promo =$em->getRepository('WittyOrderBundle:Promo')->find($customerPromo))
				$orderProduct->setPromo($promo);
	
		//Gestion du franco
		if ($order->getHtAmount() >= $this->container->getParameter('witty.distribution.franco')) $order->setShipping(0);
		else $order->setShipping($this->container->getParameter('witty.distribution.frais_port'));
	
		//Persistence de la commande
		$em->persist($order);
		$em->flush();
	
		//Envoi des mails
			//Mail à l'utilisateur
		$message = \Swift_Message::newInstance()
		->setSubject('Confirmation de commande')
		->setFrom($this->container->getParameter('witty.mail.order_expediteur'))
		->setTo($user->getEmail())
		->setBody('
Bonjour et merci de votre commande.
Votre commande a bien été prise en compte.
Vous la recevrez sous 3 jours maximum.
			
Ludiquement,
La Witty Team
			
P.S: Ceci est un envoi automatique. Merci de ne pas répondre à cet email, il ne serait pas traité.');
		
		$this->get('mailer')->send($message);
			
			//Mail à la boîte contact
				//Construction du corps du message
		$messageCommande = 
			"Commande de ".$user->getLabel()." d'un montant de ".$order->getTtcAmount()."
Détail de la commande :

";
		foreach($order->getOrderProducts() as $orderProduct)
		{
			$messageCommande.= '- '.$orderProduct->getQuantity().' x '.$orderProduct->getProduct()->getTitle().' ('.$orderProduct->getProduct()->getUnitPrice().' € HT) = '.($orderProduct->getQuantity() * $orderProduct->getProduct()->getUnitPrice()).' € HT
';
		}

			//Mail à Atlankit
		/*
		$message = \Swift_Message::newInstance()
		->setSubject('Commande de '.$user->getLabel())
		->setFrom($this->container->getParameter('witty.mail.order_expediteur'))
		->setTo($this->container->getParameter('witty.mail.logisticien'))
		->setBody('Commande de '.$user->getLabel().' d\'un montant de '.$order->getTtcAmount());
		*/
		$this->get('mailer')->send($message);
		

	
        return array();
    }
}
