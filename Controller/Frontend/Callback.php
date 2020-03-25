<?php
/**
 * Shop System Plugins:
 * - Terms of Use can be found under:
 * https://github.com/wirecard/magento2-ee/blob/master/_TERMS_OF_USE
 * - License can be found under:
 * https://github.com/wirecard/magento2-ee/blob/master/LICENSE
 */

namespace Wirecard\ElasticEngine\Controller\Frontend;

use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;
use Wirecard\ElasticEngine\Gateway\Helper;
use Wirecard\ElasticEngine\Gateway\Service\TransactionServiceFactory;
use Wirecard\PaymentSdk\Response\FormInteractionResponse;
use Wirecard\PaymentSdk\Response\InteractionResponse;
use Wirecard\PaymentSdk\Response\Response;
use Wirecard\PaymentSdk\Response\SuccessResponse;
use Wirecard\PaymentSdk\Transaction\CreditCardTransaction;
use Wirecard\PaymentSdk\TransactionService;

/**
 * Class Callback
 * @package Wirecard\ElasticEngine\Controller\Frontend
 * @method Http getRequest()
 */
class Callback extends Action
{
    const REDIRECT_URL = 'redirect-url';

    /**
     * @var Session
     */
    private $session;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var TransactionServiceFactory
     */
    private $transactionServiceFactory;

    /**
     * @var Helper\Payment
     */
    private $paymentHelper;

    /**
     * Callback constructor.
     *
     * @param Context $context
     * @param Session $session
     * @param LoggerInterface $logger
     * @param TransactionServiceFactory $transactionServiceFactory
     * @param Helper\Payment $paymentHelper
     */
    public function __construct(
        Context $context,
        Session $session,
        LoggerInterface $logger,
        TransactionServiceFactory $transactionServiceFactory,
        Helper\Payment $paymentHelper
    ) {
        parent::__construct($context);
        $this->session = $session;
        $this->baseUrl = $context->getUrl()->getRouteUrl('wirecard_elasticengine');
        $this->logger = $logger;
        $this->transactionServiceFactory = $transactionServiceFactory;
        $this->paymentHelper = $paymentHelper;
    }

    /**
     * @return \Magento\Framework\Controller\ResultInterface
     * @throws LocalizedException
     * @throws \Http\Client\Exception
     */
    public function execute()
    {
        /** Non redirect payment methods */
        $result = $this->createRedirectResult($this->baseUrl . 'frontend/redirect');

        /** Credit Card three d payments */
        if ($this->isCreditCardThreeD()) {
            $result = $this->handleThreeDTransactions(
                $this->getRequest()->getPost()->toArray()
            );
            return $result;
        }

        /** Redirect payment methods */
        if ($this->session->hasRedirectUrl()) {
            $result = $this->createRedirectResult($this->session->getRedirectUrl());
            $this->session->unsRedirectUrl();
        }

        return $result;
    }

    /**
     * Handle callback with acs url - jsresponse
     *
     * @param $response
     *
     * @return mixed
     * @throws LocalizedException
     * @throws \Http\Client\Exception
     */
    private function handleThreeDTransactions($response)
    {
        try {
            /** @var TransactionService $transactionService */
            $transactionService = $this->transactionServiceFactory->create(CreditCardTransaction::NAME);
            /** @var Response $response */
            $response = $transactionService->handleResponse($response['jsresponse']);
        } catch (\Throwable $exception) {
            $this->logger->error($exception->getMessage());
        }

        /** @var SuccessResponse|InteractionResponse|FormInteractionResponse $response */
        $order = $this->session->getLastRealOrder();
        $this->paymentHelper->addTransaction($order->getPayment(), $response, true);

        // create html response
        if ($response instanceof FormInteractionResponse) {
            $data['form-url'] = html_entity_decode($response->getUrl());
            $data['form-method'] = $response->getMethod();
            foreach ($response->getFormFields() as $key => $value) {
                $data[$key] = html_entity_decode($value);
            }
        }

        return $data;
    }

    /**
     * @return bool
     */
    private function isCreditCardThreeD()
    {
        if ($this->getRequest()->getParam('jsresponse')) {
            return true;
        }

        return false;
    }

    /**
     * @param string $redirectUrl
     * @return Json
     */
    private function createRedirectResult($redirectUrl)
    {
        $data[self::REDIRECT_URL] = $redirectUrl;

        /** @var Json $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setHttpResponseCode('200');
        $result->setData([
            'status' => 'OK',
            'data' => $data
        ]);

        return $result;
    }
}
