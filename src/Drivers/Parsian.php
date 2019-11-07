<?php

namespace Shetabit\Payment\Drivers;

use Shetabit\Payment\Abstracts\Driver;
use Shetabit\Payment\Exceptions\{InvalidPaymentException, PurchaseFailedException};
use Shetabit\Payment\{Invoice, Receipt};

class Parsian extends Driver
{
    /**
     * Invoice
     *
     * @var Invoice
     */
    protected $invoice;

    /**
     * Driver settings
     *
     * @var object
     */
    protected $settings;

    /**
     * Parsian constructor.
     * Construct the class with the relevant settings.
     *
     * @param Invoice $invoice
     * @param $settings
     */
    public function __construct(Invoice $invoice, $settings)
    {
        $this->invoice($invoice);
        $this->settings = (object)$settings;
    }

    /**
     * Purchase Invoice.
     *
     * @return string
     *
     * @throws PurchaseFailedException
     * @throws \SoapFault
     */
    public function purchase()
    {
        $soap = new \SoapClient($this->settings->apiPurchaseUrl);
        $response = $soap->SalePaymentRequest(
            $this->preparePurchaseData(),
            $this->settings->apiNamespaceUrl
        );

        // no response from bank
        if (empty($response['SalePaymentRequestResult'])) {
            throw new PurchaseFailedException('bank gateway not response');
        }

        $result = $response['SalePaymentRequestResult'];

        if (isset($result['Status']) && $result['Status'] == 0 && !empty($result['Token'])) {
            $this->invoice->transactionId($result['Token']);
        } else {
            // an error has happened
            throw new PurchaseFailedException($result['Status']);
        }

        // return the transaction's id
        return $this->invoice->getTransactionId();
    }

    /**
     * Pay the Invoice
     *
     * @return \Illuminate\Http\RedirectResponse|mixed
     */
    public function pay()
    {
        $payUrl = $this->settings->apiPaymentUrl;

        return $this->redirectWithForm(
            $payUrl,
            [
                'RefId' => $this->invoice->getTransactionId(),
            ],
            'POST'
        );
    }

    /**
     * Verify payment
     *
     * @return mixed|Receipt
     *
     * @throws InvalidPaymentException
     * @throws \SoapFault
     */
    public function verify()
    {
        $status = request()->get('status');
        $token = request()->get('Token');
        $rrn = request()->get('RRN');

        if ($status != 0 || empty($token)) {
            throw new InvalidPaymentException('تراکنش توسط کاربر کنسل شده است.');
        }

        $data = $this->prepareVerificationData();
        $soap = new \SoapClient($this->settings->apiVerificationUrl);

        $response = $soap->ConfirmPayment(['requestData' => $data]);

        if (empty($response['ConfirmPaymentResult'])) {
            throw new InvalidPaymentException('از سمت بانک پاسخی دریافت نشد.');
        }

        $result = $response['ConfirmPaymentResult'];

        if (!isset($result['Status']) || $result['Status'] != 0 || !isset($result['RRN']) || $result['RRN'] <= 0) {
            $message = 'خطا از سمت بانک با کد ' . $result['Status'] . ' رخ داده است.';
            throw new InvalidPaymentException($message);
        }

        $bankReference = (isset($result['RRN']) && $result['RRN'] > 0) ? $result['RRN'] : "";
        // $cardNumberMasked = !empty($result['CardNumberMasked']) ? $result['CardNumberMasked'] : "";

        return $this->createReceipt($bankReference);
    }

    /**
     * Generate the payment's receipt
     *
     * @param $referenceId
     *
     * @return Receipt
     */
    public function createReceipt($referenceId)
    {
        $receipt = new Receipt('parsian', $referenceId);

        return $receipt;
    }

    /**
     * Prepare data for payment verification
     *
     * @return array
     */
    public function prepareVerificationData()
    {
        $transactionId = $this->invoice->getTransactionId() ?? request()->get('Token');

        return array(
            'LoginAccount' 		=> $this->settings->loginAccount,
            'Token' 		=> $transactionId,
        );
    }

    /**
     * Prepare data for purchasing invoice
     *
     * @return array
     */
    protected function preparePurchaseData()
    {
        if (!empty($this->invoice->getDetails()['description'])) {
            $description = $this->invoice->getDetails()['description'];
        } else {
            $description = $this->settings->description;
        }

        return array(
            'LoginAccount' 		=> $this->settings->loginAccount,
            'Amount' 			=> $this->invoice->getAmount() * 10, // convert to rial
            'OrderId' 			=> crc32($this->invoice->getUuid()),
            'CallBackUrl' 		=> $this->settings->callbackUrl,
            'AdditionalData' 	=> $description,
        );
    }
}