<?php

namespace Ako\Gateway\Parsian;

use Illuminate\Support\Facades\Input;
use SoapClient;
use Ako\Gateway\PortAbstract;
use Ako\Gateway\PortInterface;

class Parsian extends PortAbstract implements PortInterface
{
	/**
	 * Url of parsian gateway web service
	 *
	 * @var string $server_url Url for initializing payment request
	 * @var string $confirm_url Url for confirming transaction
	 * @var string additionla_data
	 *
	 */
	protected $server_url = 'https://pec.shaparak.ir/NewIPGServices/Sale/SaleService.asmx?WSDL';
	protected $confirm_url = 'https://pec.shaparak.ir/NewIPGServices/Confirm/ConfirmService.asmx?WSDL';
	protected $additionla_data;

	/**
	 * Address of gate for redirect
	 *
	 * @var string
	 */
	protected $gateUrl = 'https://pec.shaparak.ir/NewIPG/?Token=';

	/**
	 * {@inheritdoc}
	 */
	public function set($amount)
	{
		$this->amount = intval($amount);
		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ready()
	{
		$this->sendPayRequest();

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function redirect()
	{
		$url = $this->gateUrl . $this->refId();
		return \View::make('gateway::parsian-redirector')->with(compact('url'));
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify($transaction)
	{
		parent::verify($transaction);

		$this->verifyPayment();

		return $this;
	}

	/**
	 * Sets callback url
	 * @param $url
	 */
	function setCallback($url)
	{
		$this->callbackUrl = $url;
		return $this;
	}

	/**
	 * Gets callback url
	 * @return string
	 */
	function getCallback()
	{
		if (!$this->callbackUrl)
			$this->callbackUrl = $this->config['parsian']['callback-url'];

		return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
	}

	/**
	 * Set additionla data on request
	 *
	 * @param string $data 
	 *
	 * @return void
	 */
	function setAdditionalData ($data)
	{
		$this->additionla_data = $data;
	}

	/**
	 * Get additionla data of request
	 *
	 * @return string 
	 */
	function getAdditionalData ()
	{
		return $this->additionla_data;
	}

	/**
	 * Send pay request to parsian gateway
	 *
	 * @return bool
	 *
	 * @throws ParsianErrorException
	 */
	protected function sendPayRequest()
	{
		$this->newTransaction();

		$params = array(
			'LoginAccount' => $this->config['parsian']['pin'],
			'Amount' => $this->amount,
			'OrderId' => $this->transactionId(),
			'CallBackUrl' => $this->getCallback(),
			'AdditionalData' => $this->getAdditionalData()
		);

		try {
			$soap = new SoapClient($this->server_url);
			$response = $soap->SalePaymentRequest(['requestData' => $params]);

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}
		if ($response !== false) {
			$authority = $response->SalePaymentRequestResult->Token ?? null;
			$status = $response->SalePaymentRequestResult->Status ?? null;
			
			if ($authority && $status == 0) {
				$this->refId = $authority;
				$this->transactionSetRefId();
				return true;
			}

			$errorMessage = ParsianResult::errorMessage($status);
			$this->transactionFailed();
			$this->newLog($status, $errorMessage);
			throw new ParsianErrorException($errorMessage, $status);

		} else {
			$this->transactionFailed();
			$this->newLog(-1, 'خطا در اتصال به درگاه پارسیان');
			throw new ParsianErrorException('خطا در اتصال به درگاه پارسیان', -1);
		}
	}

	/**
	 * Verify payment
	 *
	 * @throws ParsianErrorException
	 */
	protected function verifyPayment()
	{
		if (!Input::has("Token") || !Input::has("status") || !Input::has("RRN")) 
			throw new ParsianErrorException('درخواست غیر معتبر', -1);

		$RRN 	= Input::get("RRN");
		$status = Input::get("status");
		$token = Input::get('Token');

		if ($status != 0 || !$RRN) {
			$errorMessage = ParsianResult::errorMessage($status);
			$this->newLog($status, $errorMessage);
			throw new ParsianErrorException($errorMessage, $status);
		}

		if ($this->refId != $token)
			throw new ParsianErrorException('تراکنشی یافت نشد', -1);

		$params = array(
			'LoginAccount' => $this->config['parsian']['pin'],
			'Token' => $token
		);

		try {
			$soap = new SoapClient($this->confirm_url);
			$result = $soap->ConfirmPayment(['requestData' => $params]);

		} catch (\SoapFault $e) {
			throw new ParsianErrorException($e->getMessage(), -1);
		}

		if ($result === false || !isset($result->ConfirmPaymentResult->Status))
			throw new ParsianErrorException('پاسخ دریافتی از بانک نامعتبر است.', -1);
		
		$status = $result->ConfirmPaymentResult->Status;
		
		if ($status != 0) {
			$errorMessage = ParsianResult::errorMessage($status);
			$this->transactionFailed();
			$this->newLog($status, $errorMessage);
			throw new ParsianErrorException($errorMessage, $status);
		}

		$this->trackingCode = $token;
		$this->transactionSucceed();
		$this->newLog($status, ParsianResult::errorMessage($status));
	}
}
