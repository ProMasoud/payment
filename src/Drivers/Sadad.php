<?php

namespace Shetabit\Payment\Drivers;

use Shetabit\Payment\Abstracts\Driver;
use Shetabit\Payment\Exceptions\{InvalidPaymentException, PurchaseFailedException};
use Shetabit\Payment\{Invoice, Receipt};

class Sadad extends Driver
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
     * Sadad constructor.
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
        $response = $soap->bpPayRequest(
            $this->preparePurchaseData(),
            $this->settings->apiNamespaceUrl
        );

        // fault has happened in bank gateway
        if ($soap->fault) {
            throw new PurchaseFailedException('an error has happened');
        }

        // an error has happened
        if ($error = $soap->getError()) {
            throw new PurchaseFailedException($error);
        }

        $data = explode (',', $response);

        // purchase was not successful
        if ($data[0] != "0") {
            throw new PurchaseFailedException($response);
        }

        $this->invoice->transactionId($data[1]);

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
        $resCode =  request()->get('ResCode');
        if ($resCode != '0') {
            $message = $resCode ?? 'تراکنش نا موفق بوده است.';
            throw new InvalidPaymentException($message);
        }

        $data = $this->prepareVerificationData();
        $soap = new \SoapClient($this->settings->apiVerificationUrl);

        // step1: verify request
        $verifyResponse = $soap->bpVerifyRequest($data, $this->settings->apiNamespaceUrl);
        if ($verifyResponse != 0) {
            // rollback money and throw exception
            $soap->bpReversalRequest($data, $this->settings->apiNamespaceUrl);
            throw new InvalidPaymentException($verifyResponse ?? "خطا در عملیات وریفای تراکنش");
        }

        // step2: settle request
        $settleResponse = $soap->bpSettleRequest($data, $this->settings->apiNamespaceUrl);
        if ($settleResponse != 0) {
            // rollback money and throw exception
            $soap->bpReversalRequest($data, $this->settings->apiNamespaceUrl);
            throw new InvalidPaymentException($settleResponse ?? "خطا در ثبت درخواست واریز وجه");
        }

        return $this->createReceipt($data['saleReferenceId']);
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
        $receipt = new Receipt('sadad', $referenceId);

        return $receipt;
    }

    /**
     * Prepare data for payment verification
     *
     * @return array
     */
    public function prepareVerificationData()
    {
        $orderId = request()->get('SaleOrderId');
        $verifySaleOrderId = request()->get('SaleOrderId');
        $verifySaleReferenceId = request()->get('SaleReferenceId');

        return array(
            'terminalId' 		=> $this->settings->terminalId,
            'userName' 			=> $this->settings->username,
            'userPassword' 		=> $this->settings->password,
            'orderId' 			=> $orderId,
            'saleOrderId' 		=> $verifySaleOrderId,
            'saleReferenceId' 	=> $verifySaleReferenceId
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

        $payerId = $details['payerId'] ?? 0;

        return array(
            'terminalId' 		=> $this->settings->terminalId,
            'userName' 			=> $this->settings->username,
            'userPassword' 		=> $this->settings->password,
            'callBackUrl' 		=> $this->settings->callbackUrl,
            'amount' 			=> $this->invoice->getAmount() * 10, // convert to rial
            'localDate' 		=> now()->format('Ymd'),
            'localTime' 		=> now()->format('Gis'),
            'orderId' 			=> crc32($this->invoice->getUuid()),
            'additionalData' 	=> $description,
            'payerId' 			=> $payerId
        );
    }
}