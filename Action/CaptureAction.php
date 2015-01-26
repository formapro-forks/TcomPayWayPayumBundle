<?php

namespace Locastic\TcomPaywayPayumBundle\Action;

use Locastic\TcomPayWay\Handlers\TcomPayWayPaymentProcessHandler;
use Locastic\TcomPayWay\Model\Card;
use Locastic\TcomPayWay\Model\Customer\Customer;
use Locastic\TcomPayWay\Model\Customer\CustomersClient;
use Locastic\TcomPayWay\Model\Payment;
use Locastic\TcomPayWay\Model\Shop;
use Locastic\TcomPayWay\Model\Transaction;
use Payum\Core\Action\PaymentAwareAction;
use Payum\Core\ApiAwareInterface;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Request\Capture;
use Payum\Core\Exception\UnsupportedApiException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\Request\RenderTemplate;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Exception\LogicException;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\ObtainCreditCard;
use Payum\Core\Security\SensitiveValue;


class CaptureAction extends PaymentAwareAction implements ApiAwareInterface
{
    /**
     * @var TcomPayWayPaymentProcessHandler
     */
    protected $api;

    /**
     * {@inheritDoc}
     */
    public function setApi($api)
    {
        if (false == $api instanceof TcomPayWayPaymentProcessHandler) {
            throw new UnsupportedApiException('Expected instance of TcomPayWayPaymentProcessHandler object.');
        }

        $this->api = $api;
    }

    /**
     * {@inheritDoc}
     */
    public function execute($request)
    {
        /** @var $request SecuredCapture */
        if (false == $this->supports($request)) {
            throw RequestNotSupportedException::createActionNotSupported($this, $request);
        }

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (false == $model['httpUserAgent']) {
            $this->payment->execute($httpRequest = new GetHttpRequest());
            $model['httpUserAgent'] = $httpRequest->userAgent;
        }

        if (false == $model['originIP']) {
            $this->payment->execute($httpRequest = new GetHttpRequest());
            $model['originIP'] = $httpRequest->clientIp;
        }

        if (false == $model['httpAccept']) {
            $this->payment->execute($httpRequest = new GetHttpRequest());
            $model['httpAccept'] = $httpRequest->headers['accept'];
        }

        $cardFields = array('card_number', 'card_expiration_date', 'card_cvd');
        if (false == $model->validateNotEmpty($cardFields, false)) {
            try {
                $this->payment->execute($creditCardRequest = new ObtainCreditCard());
                $card = $creditCardRequest->obtain();
                $model['card_expiration_date'] = new SensitiveValue($card->getExpireAt()->format('m-y'));
                $model['card_number'] = $card->getNumber();
//                $model['CARDFULLNAME'] = $card->getHolder();
                $model['card_cvd'] = $card->getSecurityCode();
            } catch (RequestNotSupportedException $e) {
                throw new LogicException('Credit card details has to be set explicitly or there has to be an action that supports ObtainCreditCard request.');
            }
        }

        $shop = new Shop();
        $customersClient = new CustomersClient($model['httpAccept'], $model['httpUserAgent'], $model['originIP']);

        $customer = new Customer(
            $model['firstName'],
            $model['lastName'],
            $model['address'],
            $model['city'],
            $model['zipCode'],
            $model['country'],
            $model['email'],
            $model['phoneNumber'],
            $customersClient
        );

        $card = new Card(
            $model['card_number'],
            $model['card_expiration_date']['date'],
            $model['card_cvd']
        );

        $payment = new Payment(
            $model['shoppingCartId'],
            $model['amount'],
            $model['numOfInstallments'],
            $model['paymentMode']
        );

        $transaction = new Transaction($shop, $customer, $card, $payment);

        if (isset($_POST['PaRes'])) {
            $transaction->setSecure3dpares($_POST['PaRes']);
        }

        $response = $this->api->process($transaction);

        $model['paymentStatus'] = $response['status'];

        if ($response['status'] == 'secure3d') {
            $model['ASCUrl'] = $response['ASCUrl'];
            $model['PaReq'] = $response['PaReq'];
            $model['TermUrl'] = $request->getToken()->getTargetUrl();

            $secure3dTmpl = new RenderTemplate(
                'LocasticTcomPaywayPayumBundle:TcomPayWay:secure3d.html.twig', array(
                    'ASCUrl' => $response['ASCUrl'],
                    'PaReq' => $response['PaReq'],
                    'TermUrl' => $request->getToken()->getTargetUrl(),
                )
            );

            $this->payment->execute($secure3dTmpl);
            throw new HttpResponse($secure3dTmpl->getResult());
        } // TODO test this, breaks when user enters invalid card expiration date
        elseif($response['status'] == 'error') {
            $model['tcomData'] = $response['authResponse'];
        } else {
            $model['tcomData'] = $response['authResponse'];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof Capture &&
            $request->getModel() instanceof \ArrayAccess;
    }
}