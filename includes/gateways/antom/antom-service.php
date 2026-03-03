<?php

namespace EPOS_PAYMENT\Includes\Gateways\Antom;

defined('ABSPATH') || exit;

use EPOS_PAYMENT\Includes\Logs\Zippy_Pay_Logger;
use Client\DefaultAlipayClient;
use Request\pay\AlipayPaymentSessionRequest;
use Model\Amount;
use Model\Order;
use Model\Buyer;
use Model\Env;
use Model\TerminalType;
use Model\ProductCodeType;

class Antom_Service
{

  /**
   * @var DefaultAlipayClient
   */
  private $client;

  /**
   * @param string $gateway_url
   * @param string $client_id
   * @param string $merchant_private_key
   * @param string $alipay_public_key
   */
  public function __construct($gateway_url, $client_id, $merchant_private_key, $alipay_public_key)
  {
    $this->client = new DefaultAlipayClient(
      $gateway_url,
      $this->clean_key($merchant_private_key),
      $this->clean_key($alipay_public_key),
      $client_id
    );
  }

  /**
   * Create a payment session (hosted checkout mode).
   *
   * @param \WC_Order $order
   * @param string    $return_url
   * @param string    $notify_url
   * @return array|\WP_Error
   */
  public function create_session($order, $return_url, $notify_url)
  {
    try {
      $request_id   = $this->generate_request_id($order->get_id());
      $currency     = $order->get_currency();
      $amount_value = (string) intval(round($order->get_total() * 100));

      // Order amount
      $order_amount = new Amount();
      $order_amount->setCurrency($currency);
      $order_amount->setValue($amount_value);

      // Buyer
      $buyer = new Buyer();
      $buyer->setReferenceBuyerId((string) ($order->get_customer_id() ?: $order->get_billing_email()));

      // Environment
      $env = new Env();
      $env->setTerminalType(TerminalType::WEB);

      // Order
      $antom_order = new Order();
      $antom_order->setReferenceOrderId((string) $order->get_id());
      $antom_order->setOrderDescription(sprintf('Order #%s', $order->get_order_number()));
      $antom_order->setOrderAmount($order_amount);
      $antom_order->setBuyer($buyer);
      $antom_order->setEnv($env);

      // Payment amount
      $payment_amount = new Amount();
      $payment_amount->setCurrency($currency);
      $payment_amount->setValue($amount_value);

      // Build request
      $request = new AlipayPaymentSessionRequest();
      $request->setPaymentRequestId($request_id);
      $request->setOrder($antom_order);
      $request->setPaymentAmount($payment_amount);
      $request->setPaymentRedirectUrl($return_url);
      $request->setPaymentNotifyUrl($notify_url);
      $request->setProductCode(ProductCodeType::CASHIER_PAYMENT);

      Zippy_Pay_Logger::info('Antom createPaymentSession request', [
        'paymentRequestId' => $request_id,
        'orderId'          => $order->get_id(),
        'currency'         => $currency,
        'amount'           => $amount_value,
      ]);

      $response = $this->client->execute($request);

      Zippy_Pay_Logger::info('Antom createPaymentSession response', [
        'response' => $response,
      ]);

      $result       = $response->result ?? null;
      $result_code  = $result->resultCode ?? null;
      $result_msg   = $result->resultMessage ?? '';

      if (!$result || $result_code !== 'SUCCESS') {
        Zippy_Pay_Logger::error('Antom payment session failed', [
          'resultCode'    => $result_code ?? 'UNKNOWN',
          'resultMessage' => $result_msg,
        ]);

        return new \WP_Error('antom_payment_error', $result_msg ?: 'Payment request failed.');
      }

      return [
        'paymentSessionId'   => $response->paymentSessionId ?? '',
        'paymentSessionData' => $response->paymentSessionData ?? '',
        'normalUrl'          => $response->normalUrl ?? '',
      ];
    } catch (\Exception $e) {
      Zippy_Pay_Logger::error('Antom createPaymentSession exception', [
        'message' => $e->getMessage(),
      ]);

      return new \WP_Error('antom_api_error', $e->getMessage());
    }
  }

  /**
   * Clean a key string - strip PEM headers and whitespace to get raw base64.
   *
   * @param string $key
   * @return string
   */
  private function clean_key($key)
  {
    $key = str_replace('\n', "\n", $key);
    $key = preg_replace('/-----(BEGIN|END)[A-Z ]+-----/', '', $key);
    $key = preg_replace('/\s+/', '', $key);
    return $key;
  }

  /**
   * Generate a unique payment request ID.
   *
   * @param int $order_id
   * @return string
   */
  private function generate_request_id($order_id)
  {
    return sprintf('%d_%s_%s', $order_id, time(), wp_generate_password(8, false));
  }
}
