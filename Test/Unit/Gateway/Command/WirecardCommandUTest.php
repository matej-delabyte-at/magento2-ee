<?php
/**
 * Shop System Plugins - Terms of Use
 *
 * The plugins offered are provided free of charge by Wirecard Central Eastern Europe GmbH
 * (abbreviated to Wirecard CEE) and are explicitly not part of the Wirecard CEE range of
 * products and services.
 *
 * They have been tested and approved for full functionality in the standard configuration
 * (status on delivery) of the corresponding shop system. They are under General Public
 * License Version 3 (GPLv3) and can be used, developed and passed on to third parties under
 * the same terms.
 *
 * However, Wirecard CEE does not provide any guarantee or accept any liability for any errors
 * occurring when used in an enhanced, customized shop system configuration.
 *
 * Operation in an enhanced, customized configuration is at your own risk and requires a
 * comprehensive test phase by the user of the plugin.
 *
 * Customers use the plugins at their own risk. Wirecard CEE does not guarantee their full
 * functionality neither does Wirecard CEE assume liability for any disadvantages related to
 * the use of the plugins. Additionally, Wirecard CEE does not guarantee the full functionality
 * for customized shop systems or installed plugins of other vendors of plugins within the same
 * shop system.
 *
 * Customers are responsible for testing the plugin's functionality before starting productive
 * operation.
 *
 * By installing the plugin into the shop system the customer agrees to these terms of use.
 * Please do not use the plugin if you do not agree to these terms of use!
 */

namespace Wirecard\ElasticEngine\Test\Unit\Gateway\Command;

use Magento\Payment\Gateway\ConfigInterface;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Psr\Log\LoggerInterface;
use Wirecard\ElasticEngine\Gateway\Command\WirecardCommand;
use Wirecard\ElasticEngine\Gateway\Request\TransactionFactory;
use Wirecard\ElasticEngine\Gateway\Service\TransactionServiceFactory;
use Wirecard\PaymentSdk\Response\Response;
use Wirecard\PaymentSdk\Response\SuccessResponse;
use Wirecard\PaymentSdk\Transaction\Operation;
use Wirecard\PaymentSdk\Transaction\PayPalTransaction;
use Wirecard\PaymentSdk\Transaction\Transaction;
use Wirecard\PaymentSdk\TransactionService;

class WirecardCommandUTest extends \PHPUnit_Framework_TestCase
{
    const METHOD_CREATE='create';
    const COMMAND_PARAMETER='commandSubject';
    const METHOD_PROCESS='process';

    /**
     * @var TransactionFactory
     */
    private $transactionFactory;

    /**
     * @var TransactionServiceFactory
     */
    private $transactionServiceFactory;

    /**
     * @var TransactionService
     */
    private $transactionService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var HandlerInterface
     */
    private $handler;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var ConfigInterface
     */
    private $methodConfig;

    public function setUp()
    {
        $this->response = $this->getMockBuilder(SuccessResponse::class)
            ->disableOriginalConstructor()->getMock();
        $this->transactionService = $this->getMockBuilder(TransactionService::class)
            ->disableOriginalConstructor()->getMock();
        $this->transactionService->method(self::METHOD_PROCESS)->willReturn($this->response);

        $transaction = $this->getMock(PayPalTransaction::class);

        $this->transactionFactory = $this->getMockBuilder(TransactionFactory::class)
            ->disableOriginalConstructor()->getMock();
        $this->transactionFactory->method(self::METHOD_CREATE)->willReturn($transaction);

        $this->transactionServiceFactory = $this->getMockBuilder(TransactionServiceFactory::class)
            ->disableOriginalConstructor()->getMock();
        $this->transactionServiceFactory->method(self::METHOD_CREATE)->willReturn($this->transactionService);

        $this->logger = $this->getMock(LoggerInterface::class);
        $this->handler = $this->getMock(HandlerInterface::class);

        $this->methodConfig = $this->getMock(ConfigInterface::class);
        $this->methodConfig->method('getValue')->willReturn('authorize');
    }

    public function testExecuteCreatesTransactionService()
    {
        $testTransactionServiceFactory = $this->getMockBuilder(TransactionServiceFactory::class)
            ->disableOriginalConstructor()->getMock();
        $testTransactionServiceFactory->method(self::METHOD_CREATE)->willReturn($this->transactionService);

        // Test if the transactionService is created with the correct values
        $testTransactionServiceFactory->expects($this->Once())->method(self::METHOD_CREATE)
            ->with($this->equalTo(PayPalTransaction::NAME));

        /** @var TransactionServiceFactory $testTransactionServiceFactory */
        $command = new WirecardCommand(
            $this->transactionFactory,
            $testTransactionServiceFactory,
            $this->logger,
            $this->handler,
            $this->methodConfig
        );

        $command->execute([]);
    }

    public function testExecuteReservesReservableTransaction()
    {
        $transactionServiceMock = $this->getMockBuilder(TransactionService::class)
            ->disableOriginalConstructor()->getMock();
        $transactionServiceMock->method(self::METHOD_PROCESS)->willReturn($this->response);

        // Test if transactionService->process(...) is called with the correct parameters
        $transactionServiceMock->expects($this->Once())->method(self::METHOD_PROCESS)->with(
            $this->equalTo($this->getMock(PayPalTransaction::class)), $this->equalTo(Operation::RESERVE)
        );

        $transactionServiceFactoryMock = $this->getMockBuilder(TransactionServiceFactory::class)
            ->disableOriginalConstructor()->getMock();
        $transactionServiceFactoryMock->method(self::METHOD_CREATE)->willReturn($transactionServiceMock);

        /** @var TransactionServiceFactory $transactionServiceFactoryMock */
        $command = new WirecardCommand(
            $this->transactionFactory,
            $transactionServiceFactoryMock,
            $this->logger,
            $this->handler,
            $this->methodConfig
        );

        $command->execute([self::COMMAND_PARAMETER]);
    }

    public function testExecuteLogsExceptionForReservableTransaction()
    {
        $exception = new \Exception('Testing the exception');

        $transactionServiceMock = $this->getMockBuilder(TransactionService::class)
            ->disableOriginalConstructor()->getMock();
        $transactionServiceMock->method(self::METHOD_PROCESS)->willThrowException($exception);

        $transaction = $this->getMock(PayPalTransaction::class);

        $transactionFactoryMock = $this->getMockBuilder(TransactionFactory::class)
            ->disableOriginalConstructor()->getMock();
        $transactionFactoryMock->method(self::METHOD_CREATE)->willReturn($transaction);

        $transactionServiceFactoryMock = $this->getMockBuilder(TransactionServiceFactory::class)
            ->disableOriginalConstructor()->getMock();
        $transactionServiceFactoryMock->method(self::METHOD_CREATE)->willReturn($transactionServiceMock);

        // Test if the logger gets the exception message
        $loggerMock = $this->getMock(LoggerInterface::class);
        $loggerMock->expects($this->exactly(1))->method('error')->with($this->equalTo($exception->getMessage()));

        /** @var TransactionServiceFactory $transactionServiceFactoryMock */
        $command = new WirecardCommand(
            $this->transactionFactory,
            $transactionServiceFactoryMock,
            $loggerMock,
            $this->handler,
            $this->methodConfig
        );

        $command->execute([self::COMMAND_PARAMETER]);
    }

    public function testExecutePaysNonreservableTransaction()
    {
        $exception = new \Exception('Testing the exception');

        $transactionServiceMock = $this->getMockBuilder(TransactionService::class)
            ->disableOriginalConstructor()->getMock();
        $transactionServiceMock->method(self::METHOD_PROCESS)->willReturn($exception);

        // Test if transactionService->process(...) is called with the correct parameters
        $transactionServiceMock->expects($this->exactly(1))->method(self::METHOD_PROCESS)->with(
            $this->isInstanceOf(Transaction::class), $this->equalTo(Operation::PAY)
        );

        $testTransactionServiceFactory = $this->getMockBuilder(TransactionServiceFactory::class)
            ->disableOriginalConstructor()->getMock();
        $testTransactionServiceFactory->method(self::METHOD_CREATE)->willReturn($transactionServiceMock);

        $transactionFactoryMock = $this->getMockBuilder(TransactionFactory::class)
                ->disableOriginalConstructor()->getMock();
        $transactionFactoryMock->method(self::METHOD_CREATE)->willReturn($this->getMock(Transaction::class));

        /**
         * @var TransactionServiceFactory $testTransactionServiceFactory
         * @var TransactionFactory $transactionFactoryMock
         */
        $command = new WirecardCommand(
            $transactionFactoryMock,
            $testTransactionServiceFactory,
            $this->logger,
            $this->handler,
            $this->methodConfig
        );

        $command->execute([self::COMMAND_PARAMETER]);
    }

    public function testExecutePaysReservableTransactionForConfigCapture()
    {
        $transactionServiceMock = $this->getMockBuilder(TransactionService::class)
            ->disableOriginalConstructor()->getMock();
        $transactionServiceMock->method(self::METHOD_PROCESS)->willReturn($this->response);

        // Test if transactionService->process(...) is called with the correct parameters
        $transactionServiceMock->expects($this->Once())->method(self::METHOD_PROCESS)->with(
            $this->equalTo($this->getMock(PayPalTransaction::class)), $this->equalTo(Operation::PAY)
        );

        $transactionServiceFactoryMock = $this->getMockBuilder(TransactionServiceFactory::class)
            ->disableOriginalConstructor()->getMock();
        $transactionServiceFactoryMock->method(self::METHOD_CREATE)->willReturn($transactionServiceMock);

        $methodConfigMock = $this->getMock(ConfigInterface::class);
        $methodConfigMock->method('getValue')->willReturn('authorize_capture');

        /** @var TransactionServiceFactory $transactionServiceFactoryMock */
        $command = new WirecardCommand(
            $this->transactionFactory,
            $transactionServiceFactoryMock,
            $this->logger,
            $this->handler,
            $methodConfigMock
        );

        $command->execute([self::COMMAND_PARAMETER]);
    }

    public function testExecuteCallsHandler()
    {
        $handlerMock = $this->getMock(HandlerInterface::class);

        $handlerMock->expects($this->Once())->method('handle')
            ->with($this->equalTo([self::COMMAND_PARAMETER]), $this->equalTo(['paymentSDK-php' => $this->response]));

        $command = new WirecardCommand(
            $this->transactionFactory,
            $this->transactionServiceFactory,
            $this->logger,
            $handlerMock,
            $this->methodConfig
        );
        $command->execute([self::COMMAND_PARAMETER]);
    }
}
