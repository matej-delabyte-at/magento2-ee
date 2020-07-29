<?php
/**
 * Shop System Plugins:
 * - Terms of Use can be found under:
 * https://github.com/wirecard/magento2-ee/blob/master/_TERMS_OF_USE
 * - License can be found under:
 * https://github.com/wirecard/magento2-ee/blob/master/LICENSE
 */

namespace Wirecard\ElasticEngine\Test\Unit\Gateway\Model;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Transaction;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterface;
use Magento\Sales\Api\Data\OrderPaymentExtensionInterfaceFactory;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Vault\Api\Data\PaymentTokenFactoryInterface;
use Magento\Vault\Api\Data\PaymentTokenInterface;
use Magento\Vault\Api\PaymentTokenManagementInterface;
use Magento\Vault\Model\PaymentToken;
use Magento\Vault\Model\ResourceModel\PaymentToken as PaymentTokenResourceModel;
use PHPUnit_Framework_MockObject_MockObject;
use Psr\Log\LoggerInterface;
use Wirecard\ElasticEngine\Gateway\Helper\TransactionTypeMapper;
use Wirecard\ElasticEngine\Gateway\Model\Notify;
use Wirecard\ElasticEngine\Gateway\Service\TransactionServiceFactory;
use Wirecard\ElasticEngine\Observer\CreditCardDataAssignObserver;
use Wirecard\PaymentSdk\Entity\Card;
use Wirecard\PaymentSdk\Entity\CustomFieldCollection;
use Wirecard\PaymentSdk\Entity\Status;
use Wirecard\PaymentSdk\Entity\StatusCollection;
use Wirecard\PaymentSdk\Exception\MalformedResponseException;
use Wirecard\PaymentSdk\Response\FailureResponse;
use Wirecard\PaymentSdk\Response\InteractionResponse;
use Wirecard\PaymentSdk\Response\Response;
use Wirecard\PaymentSdk\Response\SuccessResponse;
use Wirecard\PaymentSdk\TransactionService;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;

class NotifyUTest extends \PHPUnit_Framework_TestCase
{
    const HANDLE_NOTIFICATION = 'handleNotification';
    const GET_CUSTOM_FIELDS = 'getCustomFields';
    const GET_PROVIDER_TRANSACTION_ID = 'getProviderTransactionId';
    const GET_DATA = 'getData';

    /**
     * @var Notify
     */
    private $notify;

    /**
     * @var TransactionService|PHPUnit_Framework_MockObject_MockObject
     */
    private $transactionService;

    /**
     * @var CustomFieldCollection|PHPUnit_Framework_MockObject_MockObject
     */
    private $customFields;

    /**
     * @var OrderInterface|PHPUnit_Framework_MockObject_MockObject
     */
    private $order;

    /**
     * @var Payment|PHPUnit_Framework_MockObject_MockObject
     */
    private $payment;

    /**
     * @var OrderSearchResultInterface|PHPUnit_Framework_MockObject_MockObject
     */
    private $orderSearchResult;

    /**
     * @var OrderRepositoryInterface|PHPUnit_Framework_MockObject_MockObject
     */
    private $orderRepository;

    /**
     * @var LoggerInterface|PHPUnit_Framework_MockObject_MockObject
     */
    private $logger;

    /**
     * @var InvoiceService|PHPUnit_Framework_MockObject_MockObject
     */
    private $invoiceService;

    /**
     * @var \Magento\Framework\DB\Transaction|PHPUnit_Framework_MockObject_MockObject
     */
    private $transaction;

    /**
     * @var array
     */
    private $paymentData;

    /**
     * @var PaymentTokenFactoryInterface
     */
    private $paymentTokenFactory;

    /**
     * @var PaymentTokenInterface|PHPUnit_Framework_MockObject_MockObject
     */
    private $paymentToken;

    /**
     * @var OrderPaymentExtensionInterfaceFactory
     */
    private $paymentExtensionFactory;

    /**
     * @var PaymentTokenManagementInterface|PHPUnit_Framework_MockObject_MockObject
     */
    private $paymentTokenManagement;

    /**
     * @var PaymentTokenResourceModel|PHPUnit_Framework_MockObject_MockObject
     */
    private $paymentTokenResourceModel;

    /**
     * @var AdapterInterface|PHPUnit_Framework_MockObject_MockObject
     */
    private $paymentTokenResourceModelDbAdapter;

    /**
     * @var EncryptorInterface|PHPUnit_Framework_MockObject_MockObject
     */
    private $encryptor;

    /**
     * @var \Wirecard\ElasticEngine\Gateway\Helper\Order|PHPUnit_Framework_MockObject_MockObject
     */
    private $orderHelper;

    /**
     * @var TransactionTypeMapper
     */
    private $transactionTypeMapper;

    /**
     * @var InvoiceSender
     */
    private $invoiceSender;

    public function setUp()
    {
        /**
         * @var $context Context|PHPUnit_Framework_MockObject_MockObject
         */
        $context           = $this->getMockBuilder(Context::class)->disableOriginalConstructor()->getMock();
        $this->paymentData = [
            'providerTransactionId'          => 1234,
            'providerTransactionReferenceId' => 1234567,
            'requestId'                      => '1-2-3',
            'maskedAccountNumber'            => '5151***5485',
            'authorizationCode'              => '1515',
            'cardholderAuthenticationStatus' => 'Y',
            'creditCardToken'                => '0123456CARDTOKEN'
        ];

        /**
         * @var $httpRequest Http|PHPUnit_Framework_MockObject_MockObject
         */
        $httpRequest = $this->getMockBuilder(Http::class)->disableOriginalConstructor()->getMock();
        $httpRequest->method('getContent')->willReturn('PayLoad');
        $context->method('getRequest')->willReturn($httpRequest);

        /**
         * @var $transactionServiceFactory TransactionServiceFactory|PHPUnit_Framework_MockObject_MockObject
         */
        $transactionServiceFactory = $this->getMockBuilder(TransactionServiceFactory::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->transactionService = $this->getMockBuilder(TransactionService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->transaction = $this->getMockBuilder(Transaction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $transactionServiceFactory->method('create')
            ->willReturn($this->transactionService);

        $orderStatusHistoryInterface = $this->getMockBuilder(OrderStatusHistoryInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $orderStatusHistoryInterface->method('setIsCustomerNotified')
            ->willReturn($orderStatusHistoryInterface);

        $this->orderRepository = $this->getMockBuilder(OrderRepositoryInterface::class)
            ->getMock();

        $this->order   = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->order->method('getPayment')
            ->willReturn($this->payment);
        $this->order->method('addStatusHistoryComment')
            ->willReturn($orderStatusHistoryInterface);

        $invoice = $this->getMockBuilder(Invoice::class)
            ->disableOriginalConstructor()
            ->getMock();
        $invoice->method('getOrder')
            ->willReturn($this->order);
        $this->orderRepository->method('get')
            ->willReturn($this->order);
        $this->invoiceService = $this->getMockBuilder(InvoiceService::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->invoiceService->method('prepareInvoice')
            ->willReturn($invoice);

        $this->logger = $this->getMockBuilder(LoggerInterface::class)
            ->getMock();

        $this->customFields = $this->getMockBuilder(CustomFieldCollection::class)
            ->getMock();
        $this->customFields->method('get')->withConsecutive(['orderId'], ['vaultEnabler'])
            ->willReturnOnConsecutiveCalls(42, "true");

        $this->orderSearchResult = $this->getMockForAbstractClass(OrderSearchResultInterface::class);

        $searchCriteria = $this->getMockBuilder(SearchCriteria::class)->disableOriginalConstructor()
            ->getMock();

        $searchCriteriaBuilder = $this->getMockBuilder(SearchCriteriaBuilder::class)
            ->disableOriginalConstructor()
            ->getMock();
        $searchCriteriaBuilder->method('addFilter')
            ->willReturn($searchCriteriaBuilder);
        $searchCriteriaBuilder->method('create')
            ->willReturn($searchCriteria);

        $transaction = $this->getMockBuilder(Transaction::class)
            ->disableOriginalConstructor()
            ->getMock();
        $transaction->method('addObject')
            ->withAnyParameters()
            ->willReturn($transaction);

        $this->paymentToken = $this->getMockBuilder(PaymentToken::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->paymentToken->method('getCustomerId')
            ->willReturn(1);

        $extensionAttributesMock = $this->getMockBuilder(OrderPaymentExtensionInterface::class)
            ->setMethods(['setVaultPaymentToken'])
            ->getMock();
        $extensionAttributesMock->method('setVaultPaymentToken')
            ->willReturn($this->paymentToken);
        $this->paymentExtensionFactory = $this->getMockBuilder(OrderPaymentExtensionInterfaceFactory::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMock();
        $this->paymentExtensionFactory->method('create')
            ->willReturn($extensionAttributesMock);

        $this->paymentTokenFactory = $this->getMockBuilder(PaymentTokenFactoryInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['create'])
            ->getMockForAbstractClass();
        $this->paymentTokenFactory->method('create')
            ->willReturn($this->paymentToken);

        $this->paymentTokenManagement = $this->getMockBuilder(PaymentTokenManagementInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->paymentTokenResourceModel          = $this->getMockBuilder(PaymentTokenResourceModel::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->paymentTokenResourceModelDbAdapter = $this->getMockBuilder(AdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->paymentTokenResourceModel->method('getConnection')
            ->willReturn($this->paymentTokenResourceModelDbAdapter);

        $this->encryptor = $this->getMockBuilder(EncryptorInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->orderHelper = $this->getMockBuilder(\Wirecard\ElasticEngine\Gateway\Helper\Order::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->orderHelper->method('getOrderByIncrementId')
            ->willReturn($this->order);

        $this->transactionTypeMapper = $this->getMockBuilder(TransactionTypeMapper::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->transactionTypeMapper->method('getMappedTransactionType')
            ->willReturn(null);

        $this->invoiceSender = $this->getMockBuilder(Order\Email\Sender\InvoiceSender::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->notify = new \Wirecard\ElasticEngine\Test\Unit\Gateway\Model\MyNotify(
            $transactionServiceFactory,
            $this->orderRepository,
            $this->logger,
            $this->orderHelper,
            $this->invoiceService,
            $transaction,
            $this->paymentExtensionFactory,
            $this->paymentTokenFactory,
            $this->paymentTokenManagement,
            $this->paymentTokenResourceModel,
            $this->encryptor,
            $this->transactionTypeMapper,
            $this->invoiceSender
        );
    }

    public function testExecuteWithSuccessResponse()
    {
        $this->setDefaultOrder();

        /** @var SuccessResponse|PHPUnit_Framework_MockObject_MockObject $successResponse */
        $successResponse = $this->getMockBuilder(SuccessResponse::class)
            ->disableOriginalConstructor()
            ->getMock();
        $successResponse->method(self::GET_CUSTOM_FIELDS)
            ->willReturn($this->customFields);
        $successResponse->method(self::GET_DATA)
            ->willReturn($this->paymentData);
        $successResponse->method('isValidSignature')
            ->willReturn(true);

        $this->order->expects($this->once())->method('setStatus')
            ->with('processing');
        $this->order->expects($this->once())->method('setState')
            ->with('processing');
        $this->notify->process($successResponse);
    }

    public function testExecuteWithSuccessResponseAndCanCapture()
    {
        $this->setDefaultOrder();

        /** @var SuccessResponse|PHPUnit_Framework_MockObject_MockObject $successResponse */
        $successResponse = $this->getMockBuilder(SuccessResponse::class)
            ->disableOriginalConstructor()
            ->getMock();
        $successResponse->method(self::GET_CUSTOM_FIELDS)
            ->willReturn($this->customFields);
        $successResponse->method(self::GET_DATA)
            ->willReturn($this->paymentData);
        $successResponse->method('isValidSignature')
            ->willReturn(true);
        $successResponse->method('getTransactionType')
            ->willReturn('debit');

        $this->order->expects($this->once())->method('setStatus')
            ->with('processing');
        $this->order->expects($this->once())->method('setState')
            ->with('processing');
        $this->notify->process($successResponse);
    }

    //$this->transactionService->expects($this->once())->method(self::HANDLE_NOTIFICATION)->willReturn($successResponse);
    public function testExecuteWithSuccessResponseAndCheckPayerResponse()
    {
        $this->setDefaultOrder();

        /** @var SuccessResponse|PHPUnit_Framework_MockObject_MockObject $successResponse */
        $successResponse = $this->getMockBuilder(SuccessResponse::class)
            ->disableOriginalConstructor()
            ->getMock();
        $successResponse->method(self::GET_CUSTOM_FIELDS)
            ->willReturn($this->customFields);
        $successResponse->method(self::GET_DATA)
            ->willReturn($this->paymentData);
        $successResponse->method('isValidSignature')
            ->willReturn(true);
        $successResponse->method('getTransactionType')
            ->willReturn('check-payer-response');

        $this->order->expects($this->once())->method('setStatus')
            ->with('processing');
        $this->order->expects($this->once())->method('setState')
            ->with('processing');
        $this->notify->process($successResponse);
    }

    public function testExecuteWithSuccessResponseAndAuthorization()
    {
        $this->setDefaultOrder();

        /** @var SuccessResponse|PHPUnit_Framework_MockObject_MockObject $successResponse */
        $successResponse = $this->getMockBuilder(SuccessResponse::class)
            ->disableOriginalConstructor()
            ->getMock();
        $successResponse->method(self::GET_CUSTOM_FIELDS)
            ->willReturn($this->customFields);
        $successResponse->method(self::GET_DATA)
            ->willReturn($this->paymentData);
        $successResponse->method('isValidSignature')
            ->willReturn(true);
        $successResponse->method('getTransactionType')
            ->willReturn('authorization');

        $this->order->expects($this->once())->method('setStatus')
            ->with('processing');
        $this->order->expects($this->once())->method('setState')
            ->with('processing');
        $this->notify->process($successResponse);
    }

    private function setDefaultOrder()
    {
        $this->orderSearchResult->method('getItems')->willReturn([$this->order]);
        $this->orderRepository->method('getList')->willReturn($this->orderSearchResult);
    }

    /*public function testExecuteWithFraudResponse()
    {
        $this->setDefaultOrder();

        $successResponse = $this->getMockWithoutInvokingTheOriginalConstructor(SuccessResponse::class);
        $successResponse->method(self::GET_CUSTOM_FIELDS)->willReturn($this->customFields);
        $successResponse->method(self::GET_PROVIDER_TRANSACTION_ID)->willReturn(1234);
        $successResponse->method('isValidSignature')->willReturn(false);
        $this->transactionService->expects($this->once())->method(self::HANDLE_NOTIFICATION)->willReturn($successResponse);

        $this->order->expects($this->once())->method('setStatus')->with('fraud');
        $this->order->expects($this->once())->method('setState')->with('fraud');
        $this->notify->handle('');
    }*/

    public function testExecuteWithInvalidOrderNumber()
    {
        /** @var SuccessResponse|PHPUnit_Framework_MockObject_MockObject $successResponse */
        $successResponse = $this->getMockBuilder(SuccessResponse::class)
            ->disableOriginalConstructor()
            ->getMock();
        $successResponse->method(self::GET_CUSTOM_FIELDS)
            ->willReturn($this->customFields);

        $this->orderHelper->method('getOrderByIncrementId')
            ->willThrowException(new NoSuchEntityException());

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Order with orderID 42 not found.');
        $this->notify->process($successResponse);
    }

    public function testExecuteWithFailureResponse()
    {
        $this->setDefaultOrder();

        /** @var FailureResponse|PHPUnit_Framework_MockObject_MockObject $failureResponse */
        $failureResponse = $this->getMockBuilder(FailureResponse::class)
            ->disableOriginalConstructor()
            ->getMock();
        $failureResponse->method(self::GET_CUSTOM_FIELDS)
            ->willReturn($this->customFields);
        $statusCollection = $this->getMockBuilder(StatusCollection::class)
            ->getMock();
        $status           = $this->getMockBuilder(Status::class)
            ->disableOriginalConstructor()
            ->getMock();
        $iterator         = new \ArrayIterator([$status, $status]);
        $statusCollection->method('getIterator')
            ->willReturn($iterator);
        $failureResponse->expects($this->once())
            ->method('getStatusCollection')
            ->willReturn($statusCollection);

        $this->order->expects($this->once())
            ->method('cancel');
        $this->notify->process($failureResponse);
    }

    /**
     * @expectedException \Wirecard\PaymentSdk\Exception\MalformedResponseException
     */
    public function testExecuteWithMalformedPayload()
    {
        $this->transactionService
            ->expects($this->once())
            ->method(self::HANDLE_NOTIFICATION)
            ->willThrowException(new MalformedResponseException('Message'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Response is malformed: Message');

        $this->notify->fromXmlResponse('');
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testExecuteWithInvalidArgument()
    {
        $this->transactionService
            ->expects($this->once())
            ->method(self::HANDLE_NOTIFICATION)
            ->willThrowException(new \InvalidArgumentException('Message'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Invalid argument set: Message');

        $this->notify->fromXmlResponse('');
    }

    public function testExecuteWithUnexpecterResponseObject()
    {
        $this->setDefaultOrder();

        //this Response will never happen on notificy call
        /** @var Response|PHPUnit_Framework_MockObject_MockObject $unexpectedResponse */
        $unexpectedResponse = $this->getMockBuilder(InteractionResponse::class)
            ->disableOriginalConstructor()
            ->getMock();
        $unexpectedResponse->method(self::GET_CUSTOM_FIELDS)
            ->willReturn($this->customFields);

        $this->notify->process($unexpectedResponse);
    }

    public function testExecuteWillUpdatePayment()
    {
        $this->setDefaultOrder();

        /** @var SuccessResponse|PHPUnit_Framework_MockObject_MockObject $successResponse */
        $successResponse = $this->getMockBuilder(SuccessResponse::class)
            ->disableOriginalConstructor()
            ->getMock();
        $successResponse->method(self::GET_CUSTOM_FIELDS)
            ->willReturn($this->customFields);
        $successResponse->method(self::GET_DATA)
            ->willReturn($this->paymentData);
        $successResponse->method('getParentTransactionId')
            ->willReturn(999);

        $this->payment->expects($this->once())->method('setParentTransactionId')
            ->with(999);
        $this->payment->expects($this->once())->method('setTransactionAdditionalInfo')
            ->with(
                'raw_details_info',
                [
                    'providerTransactionId'          => 1234,
                    'providerTransactionReferenceId' => 1234567,
                    'requestId'                      => '1-2-3',
                    'maskedAccountNumber'            => '5151***5485',
                    'authorizationCode'              => '1515',
                    'cardholderAuthenticationStatus' => 'Y',
                    'creditCardToken'                => '0123456CARDTOKEN'
                ]
            );

        $this->notify->process($successResponse);
    }

    public function testHandleSuccess()
    {
        $this->setDefaultOrder();

        /** @var SuccessResponse|PHPUnit_Framework_MockObject_MockObject $successResponse */
        $successResponse = $this->getMockBuilder(SuccessResponse::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCard', self::GET_CUSTOM_FIELDS, self::GET_DATA])
            ->getMock();
        $successResponse->method(self::GET_CUSTOM_FIELDS)
            ->willReturn($this->customFields);
        $successResponse->method(self::GET_DATA)
            ->willReturn($this->paymentData);
        $card = $this->getMockBuilder(Card::class)
            ->disableOriginalConstructor()
            ->setMethods(['getExpirationMonth', 'getExpirationYear', 'getCardType'])
            ->getMock();
        $card->method('getExpirationMonth')
            ->willReturn('01');
        $card->method('getExpirationYear')
            ->willReturn('2023');
        $card->method('getCardType')
            ->willReturn('visa');

        $successResponse
            ->method('getCard')
            ->willReturn($card);

        $this->payment->method('getAdditionalInformation')
            ->with(CreditCardDataAssignObserver::VAULT_ENABLER)
            ->willReturn(true);

        $this->paymentTokenManagement
            ->method('saveTokenWithPaymentLink')
            ->willThrowException(new AlreadyExistsException(new Phrase('Unique constraint violation found')));

        $this->paymentTokenResourceModelDbAdapter->expects($this->once())
            ->method('delete');

        $this->notify->myHandleSuccess($this->order, $successResponse);
    }

    public function testMissingCCData()
    {
        $this->setDefaultOrder();

        /** @var SuccessResponse|PHPUnit_Framework_MockObject_MockObject $successResponse */
        $successResponse = $this->getMockBuilder(SuccessResponse::class)
            ->disableOriginalConstructor()
            ->setMethods(['getCard', self::GET_CUSTOM_FIELDS, self::GET_DATA])
            ->getMock();
        $successResponse->method(self::GET_CUSTOM_FIELDS)
            ->willReturn($this->customFields);
        $successResponse->method(self::GET_DATA)
            ->willReturn($this->paymentData);

        $card = $this->getMockBuilder(Card::class)
            ->disableOriginalConstructor()
            ->setMethods(['getExpirationMonth', 'getExpirationYear', 'getCardType'])
            ->getMock();
        $card->method('getExpirationMonth')
            ->willReturn(null);
        $card->method('getExpirationYear')
            ->willReturn(null);
        $card->method('getCardType')
            ->willReturn(null);
        $successResponse->method('getCard')
            ->willReturn($card);

        $this->payment->method('getAdditionalInformation')
            ->with(CreditCardDataAssignObserver::VAULT_ENABLER)
            ->willReturn(true);

        $this->paymentTokenManagement
            ->method('getByPublicHash')
            ->willReturn($this->paymentToken);

        $this->paymentTokenManagement->method('saveTokenWithPaymentLink')
            ->willReturn(true);

        $this->notify->myHandleSuccess($this->order, $successResponse);
    }

    public function testGeneratePublicHash()
    {
        $this->paymentToken->method('getGatewayToken')
            ->willReturn('4304509873471003');
        $this->paymentToken->method('getPaymentMethodCode')
            ->willReturn('wirecard_elasticengine_creditcard');
        $this->paymentToken->method('getType')
            ->willReturn('card');
        $this->paymentToken->method('getTokenDetails')
            ->willReturn('{"type":"","maskedCC":"1003","expirationDate":"xx-xxxx"}');

        $this->encryptor->method('getHash')
            ->will($this
                ->returnCallback(function ($arg) {
                    return hash('md5', $arg);
                }));

        $hash = $this->notify->myGeneratePublicHash($this->paymentToken);
        $this->assertEquals('98cb19a0753e9ae138466da73c4ead19', $hash);
    }
}
