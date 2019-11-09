<?php

namespace Ako\Gateway\Pasargad;

use Illuminate\Support\Facades\Input;
use Ako\Gateway\Enum;
use SoapClient;
use Ako\Gateway\PortAbstract;
use Ako\Gateway\PortInterface;
use Symfony\Component\VarDumper\Dumper\DataDumperInterface;

class Pasargad extends PortAbstract implements PortInterface
{
	/**
	 * Url of parsian gateway web service
	 *
	 * @var string
	 */

	protected $checkTransactionUrl = 'https://pep.shaparak.ir/CheckTransactionResult.aspx';
	protected $verifyUrl = 'https://pep.shaparak.ir/VerifyPayment.aspx';
	protected $refundUrl = 'https://pep.shaparak.ir/doRefund.aspx';

	/**
	 * Address of gate for redirect
	 *
	 * @var string
	 */
	protected $gateUrl = 'https://pep.shaparak.ir/gateway.aspx';

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

		$processor = new RSAProcessor($this->config['pasargad']['certificate-path'],RSAKeyType::XMLFile);

		$url = $this->gateUrl;
		$redirectUrl = $this->getCallback();
		$invoiceNumber = $this->transactionId();
		$amount = $this->amount;
		$terminalCode = $this->config['pasargad']['terminalId'];
		$merchantCode = $this->config['pasargad']['merchantId'];
		$timeStamp = date("Y/m/d H:i:s");
		$invoiceDate = date("Y/m/d H:i:s");
		$action = 1003;
		$data = "#". $merchantCode ."#". $terminalCode ."#". $invoiceNumber ."#". $invoiceDate ."#". $amount ."#". $redirectUrl ."#". $action ."#". $timeStamp ."#";
		$data = sha1($data,true);
		$data =  $processor->sign($data); // امضاي ديجيتال
		$sign =  base64_encode($data); // base64_encode

		return \View::make('gateway::pasargad-redirector')->with(compact('url','redirectUrl','invoiceNumber','invoiceDate','amount','terminalCode','merchantCode','timeStamp','action','sign'));
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
			$this->callbackUrl = $this->config['pasargad']['callback-url'];

		return $this->callbackUrl;
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
	}

	/**
	 * Verify payment
	 *
	 * @throws ParsianErrorException
	 */
	protected function verifyPayment()
	{
		$processor = new RSAProcessor($this->config['pasargad']['certificate-path'],RSAKeyType::XMLFile);

		if (!Input::has('tref'))
			throw new PasargadErrorException('درخواست غیر معتبر', -1);

		$fields = array('invoiceUID' => Input::get('tref'));
		$result = Parser::post2https($fields,'https://pep.shaparak.ir/CheckTransactionResult.aspx');
		$check_array = Parser::makeXMLTree($result);

		if ($check_array['resultObj']['result'] != "True") {
		    $this->newLog(-1, Enum::TRANSACTION_FAILED_TEXT);
		    $this->transactionFailed();
		    throw new PasargadErrorException(Enum::TRANSACTION_FAILED_TEXT, -1);
		}
		
		$fields = array(
			'MerchantCode' => $this->config['pasargad']['merchantId'],
			'TerminalCode' => $this->config['pasargad']['terminalId'],
			'InvoiceNumber' => $check_array['resultObj']['invoiceNumber'],
			'InvoiceDate' => Input::get('iD'),
			'amount' => $check_array['resultObj']['amount'],
			'TimeStamp' => date("Y/m/d H:i:s"),
			'sign' => '',
		);

		$data = "#" . $fields['MerchantCode'] . "#" . $fields['TerminalCode'] . "#" . $fields['InvoiceNumber'] ."#" . $fields['InvoiceDate'] . "#" . $fields['amount'] . "#" . $fields['TimeStamp'] ."#";
		$data = sha1($data, true);
		$data = $processor->sign($data);
		$fields['sign'] = base64_encode($data);
		$result = Parser::post2https($fields,"https://pep.shaparak.ir/VerifyPayment.aspx");
		$array = Parser::makeXMLTree($result);
		if ($array['actionResult']['result'] != "True") {
			$this->newLog(-1, Enum::TRANSACTION_FAILED_TEXT);
			$this->transactionFailed();
			throw new PasargadErrorException(Enum::TRANSACTION_FAILED_TEXT, -1);
		}
		$this->refId = $check_array['resultObj']['referenceNumber'];
		$this->transactionSetRefId();
		$this->trackingCode = Input::get('tref');
		$this->transactionSucceed();
		$this->newLog(0, Enum::TRANSACTION_SUCCEED_TEXT);
	}
}
