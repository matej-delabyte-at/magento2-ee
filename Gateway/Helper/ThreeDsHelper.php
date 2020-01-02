<?php
/**
 * Shop System Plugins:
 * - Terms of Use can be found under:
 * https://github.com/wirecard/magento2-ee/blob/master/_TERMS_OF_USE
 * - License can be found under:
 * https://github.com/wirecard/magento2-ee/blob/master/LICENSE
 */

namespace Wirecard\ElasticEngine\Gateway\Helper;

use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Quote\Model\Quote;
use Wirecard\ElasticEngine\Gateway\Request\AccountHolderFactory;
use Wirecard\ElasticEngine\Gateway\Request\AccountInfoFactory;
use Wirecard\ElasticEngine\Gateway\Validator\ValidatorFactory;
use Wirecard\ElasticEngine\Observer\CreditCardDataAssignObserver;
use Wirecard\PaymentSdk\Constant\IsoTransactionType;
use Wirecard\PaymentSdk\Entity\AccountHolder;
use Wirecard\PaymentSdk\Transaction\Transaction;

/**
 * Class ThreeDsHelper
 * @package Wirecard\ElasticEngine\Gateway\Helper
 */
class ThreeDsHelper
{
    /** @var AccountInfoFactory */
    private $accountInfoFactory;

    /** @var AccountHolderFactory */
    private $accountHolderFactory;

    /** @var ValidatorFactory */
    private $validatorFactory;

    /**
     * ThreeDsHelper constructor.
     * @param AccountInfoFactory $accountInfoFactory
     * @param AccountHolderFactory $accountHolderFactory
     * @param ValidatorFactory $validatorFactory
     *
     * @since 2.2.1 added QuoteAddressValidator
     */
    public function __construct(
        AccountInfoFactory $accountInfoFactory,
        AccountHolderFactory $accountHolderFactory,
        ValidatorFactory $validatorFactory
    ) {
        $this->accountInfoFactory = $accountInfoFactory;
        $this->accountHolderFactory = $accountHolderFactory;
        $this->validatorFactory = $validatorFactory;
    }

    /**
     * Create 3D Secure parameters based on OrderDto or PaymentDataObjectInterface
     * and adds them to existing transaction
     *
     * @param string $challengeIndicator
     * @param Transaction $transaction
     * @param OrderDto|PaymentDataObjectInterface $dataObject
     * @return Transaction
     * @since 2.1.0
     */
    public function getThreeDsTransaction($challengeIndicator, $transaction, $dataObject)
    {
        $accountInfo = $this->accountInfoFactory->create(
            $challengeIndicator,
            $this->getTokenForPaymentObject($dataObject)
        );
        $accountHolder = $this->createAccountHolder($dataObject);
        $shipping = $this->createShipping($dataObject);

        if (!empty($accountHolder)) {
            $accountHolder->setAccountInfo($accountInfo);
            $transaction->setAccountHolder($accountHolder);
        }
        if (!empty($shipping)) {
            $transaction->setShipping($shipping);
        }
        $transaction->setIsoTransactionType(IsoTransactionType::GOODS_SERVICE_PURCHASE);

        return $transaction;
    }

    /**
     * @param OrderDto|PaymentDataObjectInterface $dataContainer
     * @return AccountHolder
     * @since 3.0.0
     */
    private function createAccountHolder($dataContainer)
    {
        /** @var Quote|OrderAdapterInterface $magentoDataObject */
        $magentoDataObject = $this->getDataObjectForQuoteOrOrder($dataContainer);

        $billingAddress = $magentoDataObject->getBillingAddress();
        $accountHolder = $this->accountHolderFactory->create($billingAddress);
        if ($magentoDataObject->getCustomerId()) {
            $accountHolder->setCrmId((string)$magentoDataObject->getCustomerId());
        }

        return $accountHolder;
    }

    /**
     * @param OrderDto|PaymentDataObjectInterface $dataContainer
     * @return AccountHolder
     * @since 3.0.0
     */
    private function createShipping($dataContainer)
    {
        /** @var Quote|OrderAdapterInterface $magentoDataObject */
        $magentoDataObject = $this->getDataObjectForQuoteOrOrder($dataContainer);

        $shippingAddress = $magentoDataObject->getShippingAddress();
        if (empty($shippingAddress)) {
            return null;
        }

        return $this->accountHolderFactory->create($shippingAddress);
    }

    /**
     * @param OrderDto|PaymentDataObjectInterface $dataContainer
     * @return mixed
     * @since 3.0.0
     */
    private function getTokenForPaymentObject($dataContainer)
    {
        if ($dataContainer instanceof PaymentDataObjectInterface) {
            return $dataContainer->getPayment()->getAdditionalInformation(CreditCardDataAssignObserver::TOKEN_ID);
        }
        return null;
    }

    /**
     * @param OrderDto|PaymentDataObjectInterface $dataContainer
     * @return Quote|OrderAdapterInterface
     * @since 3.0.0
     */
    private function getDataObjectForQuoteOrOrder($dataContainer)
    {
        if ($dataContainer instanceof PaymentDataObjectInterface) {
            return $dataContainer->getOrder();
        }
        return $dataContainer->quote;
    }
}
