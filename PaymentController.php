<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Enterprise License (PEL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PEL
 */

namespace App\Controller;

use App\Ecommerce\CheckoutManager\Confirm;
use App\Website\Navigation\BreadcrumbHelperService;
use Pimcore\Bundle\EcommerceFrameworkBundle\CheckoutManager\V7\CheckoutManagerInterface;
use Pimcore\Bundle\EcommerceFrameworkBundle\Factory;
use Pimcore\Bundle\EcommerceFrameworkBundle\Model\AbstractOrder;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\Payment\Heidelpay;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\V7\Payment\StartPaymentRequest\HeidelpayRequest;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\V7\Payment\StartPaymentResponse\UrlResponse;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\V7\Payment\StartPaymentResponse\JsonResponse;
use Pimcore\Controller\FrontendController;
use Pimcore\Model\DataObject\OnlineShopOrder;
use Pimcore\Translation\Translator;
use Psr\Log\LoggerInterface;
use Pimcore\Log\ApplicationLogger;
//use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use App\Ecommerce\PaymentManager\Payment\StripePayment;
use App\Ecommerce\PaymentManager\Payment\StartPaymentRequest\StripePaymentRequest;
use Pimcore\Bundle\EcommerceFrameworkBundle\PaymentManager\V7\Payment\StartPaymentRequest\AbstractRequest;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;

use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Fieldcollection\Data\MembershipStatus;
use \Pimcore\Model\DataObject\Objectbrick\Data\ActiveMemberships;
use Carbon\Carbon;
use DateTime;
use DateInterval;

class PaymentController extends FrontendController
{
    /**
     * @inheritDoc
     */
    public function onKernelController(FilterControllerEvent $event)
    {
        // enable view auto-rendering
        $this->setViewAutoRender($event->getRequest(), true, 'twig');
    }

    /**
     * @Route("/checkout-payment", name="shop-checkout-payment")
     *
     * @param Factory $factory
     * @param BreadcrumbHelperService $breadcrumbHelperService
     *
     * @return array
     */
    public function checkoutPaymentAction(
        Request $request,
        Factory $factory, 
        BreadcrumbHelperService $breadcrumbHelperService
    )
    {
        
        $cartManager = $factory->getCartManager();
        
        $breadcrumbHelperService->enrichCheckoutPage();

        $customer = (string)$request->get('customer');
        // var_dump(strval($customer));
        $cart = $cartManager->getOrCreateCartByName($customer);
        // $cart = $cartManager->getCartByName($customer);
        $checkoutManager = $factory->getCheckoutManager($cart);
        $paymentProvider = $checkoutManager->getPayment();

        $accessKey = '';
        $secret_key = '';
        $form = '';
        if ($paymentProvider instanceof Heidelpay) {
            $accessKey = $paymentProvider->getPublicAccessKey();
        } elseif ($paymentProvider instanceof StripePayment) {
            $public_key = $paymentProvider->getPublicKey();
        }

        $form = $this->get('form.factory')
                    ->createNamedBuilder('payment-form')
                    ->add('token', HiddenType::class, [
                        'constraints' => [new NotBlank()],
                    ])
                    ->add('submit', SubmitType::class, [
                        'attr' => [
                            'class' => 'btn btn-primary mt-3',
                        ]
                    ])
                    ->getForm();

        if ($request->isMethod('POST')) {
            $form->handleRequest($request);

            if ($form->isValid()) {
            // TODO: charge the card
            // StripePayment::startPayment();
            }
        }

        $trackingManager = $factory->getTrackingManager();
        $trackingManager->trackCheckoutStep(new Confirm($cart), $cart, 2);
        return $this->render('payment/checkout_payment.html.twig',[
            'cart' => $cart,
            'form' => $form->createView(),
            'accessKey' => $accessKey,
            'public_key' => $public_key
        ]);
    }

    /**
     * @Route("/checkout-start-payment", name="shop-checkout-start-payment")
     *
     * @param Request $request
     * @param Factory $factory
     *
     * @return RedirectResponse
     */
    public function startPaymentAction(Request $request, Factory $factory, LoggerInterface $logger)
    {
        try {
            $cartManager = $factory->getCartManager();
            $customer = (string)$request->get('customer');
            $cart = $cartManager->getOrCreateCartByName($customer);

            $checkoutManager = $factory->getCheckoutManager($cart);
            $paymentInfo = $checkoutManager->initOrderPayment();
            $order = $paymentInfo->getObject();

            $paymentConfig = new StripePaymentRequest();
            $paymentConfig->setInternalPaymentId($paymentInfo->getInternalPaymentId());
            // $paymentConfig->setPaymentReference($request->get('paymentId'));
            $paymentConfig->setReturnUrl($this->generateUrl('shop-commit-order', ['order' => $order->getOrdernumber()], UrlGeneratorInterface::ABSOLUTE_URL));
            $paymentConfig->setErrorUrl($this->generateUrl('shop-checkout-payment-error', [], UrlGeneratorInterface::ABSOLUTE_URL));            
            
            $response = $checkoutManager->startOrderPaymentWithPaymentProvider($paymentConfig);
            // echo '<pre>';
            // print_r($response);
            // die();
            echo json_encode(['response' => $this->generateUrl('shop-commit-order', ['order' => $order->getOrdernumber()], UrlGeneratorInterface::ABSOLUTE_URL)]);
            exit();
            if ($response instanceof UrlResponse) {
                return new RedirectResponse($response->getUrl());
            } elseif ($response instanceof JsonResponse) {
                echo json_encode(['response' => $this->generateUrl('shop-commit-order', ['order' => $order->getOrdernumber()], UrlGeneratorInterface::ABSOLUTE_URL)]);
                exit();
                // return new RedirectResponse($response->getJsonString());
            }
        } catch (\Exception $e) {
            $this->addFlash('danger', $e->getMessage());
            $logger->error($e->getMessage());

            return $this->redirectToRoute('shop-checkout-payment', ['customer' => $customer]);
        }
    }

    /**
     * @Route("/payment-error", name = "shop-checkout-payment-error" )
     */
    public function paymentErrorAction(Request $request, LoggerInterface $logger)
    {
        $logger->error('payment error: ' . $request->get('merchantMessage'));

        if ($clientMessage = $request->get('clientMessage')) {
            $this->addFlash('danger', $clientMessage);
        }

        return $this->redirectToRoute('shop-checkout-payment', ['customer' => $customer]);
    }

    
    
    /**
     * @Route("/payment-commit-order", name="shop-commit-order")
     *
     * @param Request $request
     * @param Factory $factory
     * @param LoggerInterface $logger
     * @param Translator $translator
     * @param SessionInterface $session
     *
     * @return RedirectResponse
     *
     * @throws \Pimcore\Bundle\EcommerceFrameworkBundle\Exception\UnsupportedException
     */
    public function commitOrderAction(Request $request, Factory $factory, LoggerInterface $logger, Translator $translator, SessionInterface $session, ApplicationLogger $appLogger)
    {
        $orderNum = OnlineShopOrder::getByOrdernumber($request->query->get('order'), 1);
        $customer_id = $orderNum->getCustomer()->getId();
        
        $cartManager = $factory->getCartManager();  
        $cart = $cartManager->getOrCreateCartByName((string)$customer_id);        

        $checkoutManager = $factory->getCheckoutManager($cart);
        
        // if ($customer_id = 1131 || $customer_id = 1405) {
        //     if (str_contains($orderNum->getPaymentInfo()->getItems()[0]->getProviderData(), '"stripe_responseStatus":"succeeded"')) {
        //         echo "FOUND YOU!!!";
        //     }
        //     exit();
        // }
        
        
        $params = array_merge($request->query->all(), $request->request->all());
        
        try {
            // $order = $checkoutManager->handlePaymentResponseAndCommitOrderPayment([
            //     'order' => $orderNum
            // ]);
            // $order = $checkoutManager->handlePaymentResponseAndCommitOrderPayment($params);
            if (str_contains($orderNum->getPaymentInfo()->getItems()[0]->getProviderData(), '"stripe_responseStatus":"succeeded"')) {
                $order = $checkoutManager->commitOrder();
            }

        } catch (\Exception $e) {
            $appLogger->error($e->getMessage());
            $logger->error($e->getMessage());
        }
            
        
        
    
        if(!$order || $order->getOrderState() !== AbstractOrder::ORDER_STATE_COMMITTED) {
            
            $this->addFlash('danger', $translator->trans('checkout.payment-failed'));
            return $this->redirectToRoute('shop-checkout-payment', ['customer' => $customer]);
        }
    
        if (!$session->isStarted()) {
            $session->start();
        }
    
        $session->set("last_order_id", $order->getId());

        // Add Membership details to Customer
        $customer = $order->getCustomer();
        $orderItems = $order->getItems();
        $orderDate = $order->getOrderdate();

        // Memberships
        $platinumArray = array();
        $founderArray = array();
        $goldArray = array();
        $silverArray = array();

        $platinumDays = 0;
        $founderDays = 0;
        $goldDays = 0;
        $silverDays = 0;
        
        // Add-Ons
        $announcementsArray = array();
        $picturesArray = array();
        $videoArray = array();

        $announcementsDays = 0;
        $picturesDays = 0;
        $videoDays = 0;

        foreach ($orderItems as $item) {            
            if (strpos($item->getProductName(), 'Platinum') !== false) {
                array_push($platinumArray, $item);
                $platinumDays = new DateInterval('P'. count($platinumArray) .'M');
            }
            if (strpos($item->getProductName(), 'Founder') !== false) {
                array_push($founderArray, $item);
                $founderDays = new DateInterval('P'. count($founderArray) .'M');
            }
            if (strpos($item->getProductName(), 'Gold') !== false) {
                array_push($goldArray, $item);
                $goldDays = new DateInterval('P'. count($goldArray) .'M');
            }
            if (strpos($item->getProductName(), 'Silver') !== false) {
                array_push($silverArray, $item);
                $silverDays = new DateInterval('P'. count($silverArray) .'M');
            }
            if (strpos($item->getProductName(), 'Announcements') !== false) {
                array_push($announcementsArray, $item);
                $announcementsDays = new DateInterval('P'. count($announcementsArray) .'M');
            }
            if (strpos($item->getProductName(), 'Pictures') !== false) {
                array_push($picturesArray, $item);
                $picturesDays = new DateInterval('P'. count($picturesArray) .'M');
            }
            if (strpos($item->getProductName(), 'Video') !== false) {
                array_push($videoArray, $item);
                $videoDays = new DateInterval('P'. count($videoArray) .'M');
            }            
        }

        // Create the MembershipStatus Fieldcollection
        $membershipStatus = new ActiveMemberships($customer);
        $membershipStatus->setFieldName('membershipStatus');

        if (empty($customer->getMembershipStatus()->getItems())) {
            // memberships
            if (count($platinumArray) > 0) {
                $membershipStatus->setExpiryPlatinum($orderDate->add($platinumDays));
            }
            if (count($founderArray) > 0) {
                $membershipStatus->setExpiryFounder($orderDate->add($platinumDays)->add($founderDays));
            }
            if (count($goldArray) > 0) {
                $membershipStatus->setExpiryGold($orderDate->add($platinumDays)->add($founderDays)->add($goldDays));
            }
            if (count($silverArray) > 0) {
                $membershipStatus->setExpirySilver($orderDate->add($platinumDays)->add($founderDays)->add($goldDays)->add($silverDays));
            }
            // add-ons
            if (count($announcementsArray) > 0) {
                $membershipStatus->setExpiryAnnouncements($orderDate->add($announcementsDays));
            }
            if (count($picturesArray) > 0) {
                $membershipStatus->setExpiryPictures($orderDate->add($picturesDays));
            }
            if (count($videoArray) > 0) {
                $membershipStatus->setExpiryVideo($orderDate->add($videoDays));
            }
        } else {
            $platinumExpires = ['membership' => 'Platinum', 'dateTime' => $customer->getMembershipStatus()->getItems()[0]->getExpiryPlatinum()];
            $founderExpires = ['membership' => 'Founder', 'dateTime' => $customer->getMembershipStatus()->getItems()[0]->getExpiryFounder()];
            $goldExpires = ['membership' => 'Gold', 'dateTime' => $customer->getMembershipStatus()->getItems()[0]->getExpiryGold()];
            $silverExpires = ['membership' => 'Silver', 'dateTime' => $customer->getMembershipStatus()->getItems()[0]->getExpirySilver()];

            $membershipArray = [$platinumExpires, $founderExpires, $goldExpires, $silverExpires];

            $announcementsExpires = $customer->getMembershipStatus()->getItems()[0]->getExpiryAnnouncements();
            $picturesExpires = $customer->getMembershipStatus()->getItems()[0]->getExpiryPictures();
            $videoExpires = $customer->getMembershipStatus()->getItems()[0]->getExpiryVideo();

            
            // memberships
            $ord = [];
            foreach($membershipArray as $key => $value) {
                $ord[] = $value['dateTime'];
            }

            array_multisort($ord, SORT_ASC, $membershipArray);

           // $now = new DateTime('now');
           $now =  Carbon::now();

            foreach ($membershipArray as $dateKey => $dateValue) {
                if (in_array(null, $dateValue) || $dateValue['dateTime'] < $now) {
                    unset($membershipArray[$dateKey]);
                }
            }

            $firstMembership = $membershipArray[array_key_first($membershipArray)];
            $timeDiff = $orderDate->diff($firstMembership['dateTime']);


            // Come back to this
            if (count($platinumArray) > 0 && $firstMembership['membership'] == 'Platinum') {
                $membershipStatus->setExpiryPlatinum($orderDate->add($platinumDays));
            } elseif (count($platinumArray) > 0 && $firstMembership['membership'] !== 'Platinum') {
                $membershipStatus->setExpiryPlatinum($orderDate->add($platinumDays)->add($timeDiff));
            } else {
                $membershipStatus->setExpiryPlatinum($customer->getMembershipStatus()->getItems()[0]->getExpiryPlatinum());
            }

            if (count($founderArray) > 0 && $firstMembership['membership'] == 'Founder') {
                $membershipStatus->setExpiryFounder($orderDate->add($founderDays));
            } elseif (count($founderArray) > 0 && $firstMembership['membership'] !== 'Founder') {
                $membershipStatus->setExpiryFounder($orderDate->add($founderDays)->add($timeDiff));
            } else {
                $membershipStatus->setExpiryFounder($customer->getMembershipStatus()->getItems()[0]->getExpiryFounder());
            }

            if (count($goldArray) > 0 && $firstMembership['membership'] == 'Gold') {
                $membershipStatus->setExpiryGold($orderDate->add($goldDays));
            } elseif (count($goldArray) > 0 && $firstMembership['membership'] !== 'Gold') {
                $membershipStatus->setExpiryGold($orderDate->add($goldDays)->add($timeDiff));
            } else {
                $membershipStatus->setExpiryGold($customer->getMembershipStatus()->getItems()[0]->getExpiryGold());
            }

            if (count($silverArray) > 0 && $firstMembership['membership'] == 'Silver') {
                $membershipStatus->setExpirySilver($orderDate->add($silverDays));
            } elseif (count($silverArray) > 0 && $firstMembership['membership'] !== 'Silver') {
                $membershipStatus->setExpirySilver($orderDate->add($silverDays)->add($timeDiff));
            } else {
                $membershipStatus->setExpirySilver($customer->getMembershipStatus()->getItems()[0]->getExpirySilver());
            }
            
            
            // add-ons
            if (count($announcementsArray) > 0) {
                $membershipStatus->setExpiryAnnouncements($orderDate->add($announcementsDays));
            } else {
                $membershipStatus->setExpiryAnnouncements($announcementsExpires);
            }
            if (count($picturesArray) > 0) {
                $membershipStatus->setExpiryPictures($orderDate->add($picturesDays));
            } else {
                $membershipStatus->setExpiryPictures($picturesExpires);
            }
            if (count($videoArray) > 0) {
                $membershipStatus->setExpiryVideo($orderDate->add($videoDays));
            } else {
                $membershipStatus->setExpiryVideo($videoExpires);
            }
        }



        $membershipStatus->save($customer, 'membershipStatus');
        $customer->getBricks()->setMembershipStatus($membershipStatus);
        $customer->save();
    
        return $this->redirectToRoute('shop-checkout-completed');
    }
}
