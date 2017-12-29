<?php
use Mage_Payment_Model_Method_Abstract as ModelMethodAbstract;
use \PagarMe\Sdk\PagarMe as PagarMeSdk;
use PagarMe_CreditCard_Model_Exception_InvalidInstallments as InvalidInstallmentsException;
use PagarMe_CreditCard_Model_Exception_GenerateCard as GenerateCardException;

class PagarMe_CreditCard_Model_Creditcard extends ModelMethodAbstract
{
    protected $_code = 'pagarme_creditcard';
    protected $_formBlockType = 'pagarme_creditcard/form_creditcard';
    protected $_infoBlockType = 'pagarme_creditcard/info_creditcard';
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canUseForMultishipping = true;
    protected $_canManageRecurringProfiles = true;

    /**
     * @var \PagarMe\Sdk\PagarMe
     */
    protected $sdk;

    /**
     * @var PagarMe\Sdk\Transaction\CreditCardTransaction
     */
    protected $transaction;

    const PAGARME_MAX_INSTALLMENTS = 12;

    public function __construct(PagarMeSdk $sdk = null)
    {
        if (is_null($sdk)) {
            $this->sdk = Mage::getModel('pagarme_core/sdk_adapter')
                 ->getPagarMeSdk();
        }
        parent::__construct();
    }

    /**
     * @param \PagarMe\Sdk\PagarMe $sdk
     * @return \PagarMe_CreditCard_Model_Creditcard
     *
     * @codeCoverageIgnore
     */
    public function setSdk(PagarMeSdk $sdk)
    {
        $this->sdk = $sdk;

        return $this;
    }

    /**
     * @param type $quote
     *
     * @return bool
     */
    public function isAvailable($quote = null)
    {
        if (!parent::isAvailable($quote)) {
            return false;
        }

        return (bool) Mage::getStoreConfig(
            'payment/pagarme_configurations/transparent_active'
        );
    }

   /**
    * Retrieve payment method title
    *
    * @return string
    */
    public function getTitle()
    {
        return Mage::getStoreConfig(
            'payment/pagarme_configurations/creditcard_title'
        );
    }

    /**
     * @param array $data
     *
     * @return $this
     */
    public function assignData($data)
    {
        $additionalInfoData = [
            'card_hash' => $data['card_hash'],
            'installments' => $data['installments']
        ];

        $this->getInfoInstance()
            ->setAdditionalInformation($additionalInfoData);

        return $this;
    }

    /**
     * Returns max installments defined on admin
     *
     * @return int
     */
    public function getMaxInstallments()
    {
        return (int) Mage::getStoreConfig(
            'payment/pagarme_configurations/creditcard_max_installments'
        );
    }

    /**
     * Check if installments is between 1 and the defined max installments
     *
     * @param int $installments
     *
     * @throws InvalidInstallmentsException
     *
     * @return void
     */
    public function isInstallmentsValid($installments)
    {
        if ($installments <= 0) {
            throw new InvalidInstallmentsException(
                'Installments number should be greater than zero'
            );
        }

        if ($installments > self::PAGARME_MAX_INSTALLMENTS) {
            throw new InvalidInstallmentsException(
                'Installments number should be lower than twelve'
            );
        }

        if ($installments > $this->getMaxInstallments()) {
            $message = sprintf(
                'Installments number should be greater than zero',
                $this->getMaxInstallments()
            );
            throw new InvalidInstallmentsException(
                $message
            );
        }
    }

    /**
     * @param string $cardHash
     *
     * @return PagarMe\Sdk\Card\Card
     * @throws GenerateCardException
     */
    public function generateCard($cardHash)
    {
        try {
            $card = $this->sdk
                ->card()
                ->createFromHash($cardHash);
            return $card;
        } catch (\Exception $exception) {
            $error = json_decode($exception->getMessage());
            $error = json_decode($error);

            $response = array_reduce($error->errors, function ($carry, $item) {
                return is_null($carry) ? $item->message : $carry."\n".$item->message;
            });

            throw new GenerateCardException(
                $response
            );
        }
    }

    public function authorize(Varien_Object $payment, $amount)
    {
        try {
            $infoInstance = $this->getInfoInstance();
            $cardHash = $infoInstance->getAdditionalInformation('card_hash');
            $installments = (int)$infoInstance->getAdditionalInformation(
                'installments'
            );

            $quote = Mage::getSingleton('checkout/session')->getQuote();
            $helper = Mage::helper('pagarme_core');

            $billingAddress = $quote->getBillingAddress();

            $this->isInstallmentsValid($installments);
            $card = $this->generateCard($cardHash);

            if ($billingAddress == false) {
                return false;
            }

            $telephone = $billingAddress->getTelephone();

            $customer = $helper->prepareCustomerData([
                'pagarme_modal_customer_document_number' => $quote->getCustomerTaxvat(),
                'pagarme_modal_customer_document_type' => $helper->getDocumentType($quote),
                'pagarme_modal_customer_name' => $helper->getCustomerNameFromQuote($quote),
                'pagarme_modal_customer_email' => $quote->getCustomerEmail(),
                'pagarme_modal_customer_born_at' => $quote->getDob(),
                'pagarme_modal_customer_address_street_1' => $billingAddress->getStreet(1),
                'pagarme_modal_customer_address_street_2' => $billingAddress->getStreet(2),
                'pagarme_modal_customer_address_street_3' => $billingAddress->getStreet(3),
                'pagarme_modal_customer_address_street_4' => $billingAddress->getStreet(4),
                'pagarme_modal_customer_address_city' => $billingAddress->getCity(),
                'pagarme_modal_customer_address_state' => $billingAddress->getRegion(),
                'pagarme_modal_customer_address_zipcode' => $billingAddress->getPostcode(),
                'pagarme_modal_customer_address_country' => $billingAddress->getCountry(),
                'pagarme_modal_customer_phone_ddd' => $helper->getDddFromPhoneNumber($telephone),
                'pagarme_modal_customer_phone_number' => $helper->getPhoneWithoutDdd($telephone),
                'pagarme_modal_customer_gender' => $quote->getGender()
            ]);

            $customerPagarMe = $helper->buildCustomer($customer);

            $order = $payment->getOrder();

            $this->transaction = $this->sdk
                ->transaction()
                ->creditCardTransaction(
                    $helper->parseAmountToInteger($quote->getGrandTotal()),
                    $card,
                    $customerPagarMe,
                    $installments,
                    false
                );

            Mage::getModel('pagarme_core/transaction')
                ->saveTransactionInformation(
                    $order,
                    $this->transaction,
                    $infoInstance
                );
        } catch (GenerateCardException $exception) {
            Mage::throwException($exception->getMessage());
        } catch (InvalidInstallmentsException $exception) {
            Mage::throwException($exception->getMessage());
        } catch (\Exception $exception) {
            $json = json_decode($exception->getMessage());
            $json = json_decode($json);

            $response = array_reduce($json->errors, function ($carry, $item) {
                return is_null($carry)
                    ? $item->message : $carry."\n".$item->message;
            });

            Mage::throwException($response);
        }

        return $this;
    }

    public function capture(Varien_Object $payment, $amount)
    {
        $this->transaction = $this->sdk
            ->transaction()
            ->capture($this->transaction);
    }
}
