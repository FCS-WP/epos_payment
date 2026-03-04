<?php

namespace EPOS_PAYMENT\Includes\Gateways\Antom;

defined('ABSPATH') || exit;

use EPOS_PAYMENT\Includes\Logs\Zippy_Pay_Logger;

class Antom_Service
{

  /**
   * @var string
   */
  private $gateway_url;

  /**
   * @var string
   */
  private $client_id;

  /**
   * @var string
   */
  private $merchant_private_key;

  /**
   * @var string
   */
  private $alipay_public_key;

  /**
   * @param string $gateway_url
   * @param string $client_id
   * @param string $merchant_private_key
   * @param string $alipay_public_key
   */
  public function __construct($gateway_url, $client_id, $merchant_private_key, $alipay_public_key)
  {
    $this->gateway_url          = rtrim($gateway_url, '/');
    $this->client_id            = $client_id;
    $this->merchant_private_key = Antom_Signature::format_private_key($merchant_private_key);
    $this->alipay_public_key    = Antom_Signature::format_public_key($alipay_public_key);
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
    $request_id   = $this->generate_request_id($order->get_id());
    $currency     = $order->get_currency();
    $amount_value = (string) intval(round($order->get_total() * 100));

    $body = [
      'env'              => [
        'clientIp' =>  $_SERVER['REMOTE_ADDR'] ?? '',
        'terminalType' => 'WEB',

      ],
      'settlementStrategy' => ["settlementCurrency" => $currency],
      'paymentRequestId'   => $request_id,
      'productCode'        => 'CASHIER_PAYMENT',
      'productScene'        => 'CHECKOUT_PAYMENT',
      'paymentAmount'      => [
        'currency' => $currency,
        'value'    => $amount_value,
      ],
      'order'              => [
        'referenceOrderId' => (string) $order->get_id(),
        'orderDescription' => sprintf('Order #%s', $order->get_order_number()),
        'orderAmount'      => [
          'currency' => $currency,
          'value'    => $amount_value,
        ],
        'buyer'            => [
          'referenceBuyerId' => (string) ($order->get_customer_id() ?: $order->get_billing_email()),
        ],
      ],
      'paymentRedirectUrl' => $return_url,
      'paymentNotifyUrl'   => $notify_url,
      'availablePaymentMethod' => [
        "paymentMethodTypeList" => [
          ["paymentMethodType" => "PAYNOW"],
          ["paymentMethodType" => "CARD"],
          // ["paymentMethodType" => "TNG"],
        ],
      ],
    ];

    $path = $this->get_api_path('/payments/createPaymentSession');

    return $this->request($path, $body);
  }

  /**
   * Send a signed request to the Antom API.
   *
   * @param string $path
   * @param array  $body
   * @return array|\WP_Error
   */
  private function request($path, $body)
  {
    $url       = $this->gateway_url . $path;
    $req_time  = date(DATE_ISO8601);
    $json_body = wp_json_encode($body);

    $signature = Antom_Signature::sign('POST', $path, $this->client_id, $req_time, $json_body, $this->merchant_private_key);

    if (empty($signature)) {
      Zippy_Pay_Logger::error('Antom: Failed to generate signature.');
      return new \WP_Error('antom_sign_error', 'Failed to generate request signature.');
    }

    Zippy_Pay_Logger::info('Antom API request', [
      'url'  => $url,
      'body' => $body,
    ]);

    $response = wp_remote_post($url, [
      'timeout' => 30,
      'headers' => [
        'Content-Type' => 'application/json; charset=UTF-8',
        'client-id'    => $this->client_id,
        'Request-Time' => $req_time,
        'Signature'    => 'algorithm=RSA256, keyVersion=1, signature=' . $signature,
      ],
      'body' => $json_body,
    ]);

    if (is_wp_error($response)) {
      Zippy_Pay_Logger::error('Antom API connection error', [
        'message' => $response->get_error_message(),
      ]);
      return $response;
    }

    $status_code   = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);

    Zippy_Pay_Logger::info('Antom API response', [
      'status_code' => $status_code,
      'body'        => $response_body,
    ]);

    if ($status_code !== 200 || empty($response_body)) {
      Zippy_Pay_Logger::error('Antom API returned unexpected status', [
        'status_code' => $status_code,
        'body'        => $response_body,
      ]);
      return new \WP_Error(
        'antom_api_error',
        sprintf('Antom API returned status %d', $status_code),
        $response_body
      );
    }

    $result_code = $response_body['result']['resultCode'] ?? null;

    if ($result_code !== 'SUCCESS') {
      $result_msg = $response_body['result']['resultMessage'] ?? 'Payment request failed.';
      Zippy_Pay_Logger::error('Antom payment failed', [
        'resultCode'    => $result_code,
        'resultMessage' => $result_msg,
      ]);
      return new \WP_Error('antom_payment_error', $result_msg, $response_body);
    }

    return $response_body;
  }

  /**
   * Get the API path, adjusting for sandbox mode.
   *
   * @param string $endpoint
   * @return string
   */
  private function get_api_path($endpoint)
  {
    $base = '/ams/api/v1';

    if (strpos($this->client_id, 'SANDBOX_') === 0) {
      $base = '/ams/sandbox/api/v1';
    }

    return $base . $endpoint;
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
