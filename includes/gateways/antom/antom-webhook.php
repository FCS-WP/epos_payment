<?php

namespace EPOS_PAYMENT\Includes\Gateways\Antom;

defined('ABSPATH') || exit;

use EPOS_PAYMENT\Includes\Logs\Zippy_Pay_Logger;

class Antom_Webhook
{

  /**
   * @var string PEM-formatted public key
   */
  private $alipay_public_key;

  /**
   * @var string
   */
  private $client_id;

  /**
   * @param string $client_id
   * @param string $alipay_public_key
   */
  public function __construct($client_id, $alipay_public_key)
  {
    $this->client_id        = $client_id;
    $this->alipay_public_key = Antom_Signature::format_public_key($alipay_public_key);
  }

  /**
   * Handle incoming webhook notification from Antom.
   */
  public function handle()
  {
    $raw_body = file_get_contents('php://input');

    Zippy_Pay_Logger::info('Antom webhook received', [
      'body' => $raw_body,
    ]);

    if (empty($raw_body)) {
      Zippy_Pay_Logger::error('Antom webhook: empty request body.');
      $this->send_response('FAIL', 'Empty request body.');
      return;
    }

    $headers = $this->extract_headers();

    if (empty($headers['request_time']) || empty($headers['signature'])) {
      Zippy_Pay_Logger::error('Antom webhook: missing required headers.', $headers);
      $this->send_response('FAIL', 'Missing required headers.');
      return;
    }

    $signature_value = $this->parse_signature_value($headers['signature']);

    if (empty($signature_value)) {
      Zippy_Pay_Logger::error('Antom webhook: failed to parse signature header.', [
        'signature_header' => $headers['signature'],
      ]);
      $this->send_response('FAIL', 'Invalid signature header.');
      return;
    }

    $path = $this->get_notify_path();

    $is_valid = Antom_Signature::verify(
      'POST',
      $path,
      $this->client_id,
      $headers['request_time'],
      $raw_body,
      $signature_value,
      $this->alipay_public_key
    );

    if (!$is_valid) {
      Zippy_Pay_Logger::error('Antom webhook: signature verification failed.');
      $this->send_response('FAIL', 'Signature verification failed.');
      return;
    }

    Zippy_Pay_Logger::info('Antom webhook: signature verified successfully.');

    $data = json_decode($raw_body, true);

    if (empty($data)) {
      Zippy_Pay_Logger::error('Antom webhook: failed to decode JSON body.');
      $this->send_response('FAIL', 'Invalid JSON body.');
      return;
    }

    $notify_type = $data['notifyType'] ?? '';

    if ($notify_type !== 'PAYMENT_RESULT') {
      Zippy_Pay_Logger::info('Antom webhook: ignoring notification type.', [
        'notifyType' => $notify_type,
      ]);
      $this->send_response('SUCCESS', 'success');
      return;
    }

    $payment_request_id  = $data['paymentRequestId'] ?? '';
    $result_code         = $data['result']['resultCode'] ?? '';
    $payment_id          = $data['paymentId'] ?? '';
    $payment_method_type = $data['paymentMethodType'] ?? '';

    if (empty($payment_request_id)) {
      Zippy_Pay_Logger::error('Antom webhook: missing paymentRequestId.');
      $this->send_response('FAIL', 'Missing paymentRequestId.');
      return;
    }

    $order = $this->find_order_by_payment_request_id($payment_request_id);

    if (!$order) {
      Zippy_Pay_Logger::error('Antom webhook: order not found.', [
        'paymentRequestId' => $payment_request_id,
      ]);
      $this->send_response('FAIL', 'Order not found.');
      return;
    }

    Zippy_Pay_Logger::info('Antom webhook: processing order.', [
      'order_id'         => $order->get_id(),
      'resultCode'       => $result_code,
      'paymentId'        => $payment_id,
      'paymentRequestId' => $payment_request_id,
    ]);

    $this->update_order($order, $result_code, $payment_id, $payment_method_type);

    $this->send_response('SUCCESS', 'success');
  }

  /**
   * Extract required headers from the request.
   *
   * @return array
   */
  private function extract_headers()
  {
    return [
      'client_id'    => $_SERVER['HTTP_CLIENT_ID'] ?? '',
      'request_time' => $_SERVER['HTTP_REQUEST_TIME'] ?? '',
      'signature'    => $_SERVER['HTTP_SIGNATURE'] ?? '',
    ];
  }

  /**
   * Parse the signature value from the Signature header.
   * Format: "algorithm=RSA256, keyVersion=1, signature={value}"
   *
   * @param string $header
   * @return string
   */
  private function parse_signature_value($header)
  {
    if (preg_match('/signature=(.+)$/', $header, $matches)) {
      return trim($matches[1]);
    }

    return '';
  }

  /**
   * Get the notification path for signature verification.
   * Antom signs notifications using the merchant's notify URL path.
   *
   * @return string
   */
  private function get_notify_path()
  {
    return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
  }

  /**
   * Find a WooCommerce order by Antom payment request ID.
   * Uses wc_get_orders() for HPOS compatibility.
   *
   * @param string $payment_request_id
   * @return \WC_Order|null
   */
  private function find_order_by_payment_request_id($payment_request_id)
  {
    $orders = wc_get_orders([
      'meta_key'   => ANTOM_META_PAYMENT_REQUEST_ID,
      'meta_value' => $payment_request_id,
      'limit'      => 1,
    ]);

    return !empty($orders) ? $orders[0] : null;
  }

  /**
   * Update WooCommerce order status based on Antom result.
   *
   * @param \WC_Order $order
   * @param string    $result_code
   * @param string    $payment_id
   */
  private function update_order($order, $result_code, $payment_id, $payment_method_type = '')
  {
    if ($order->is_paid()) {
      Zippy_Pay_Logger::info('Antom webhook: order already paid, skipping.', [
        'order_id' => $order->get_id(),
      ]);
      return;
    }

    if (!empty($payment_method_type)) {
      $order->set_payment_method_title('Antom - ' . $payment_method_type);
      $order->save();
    }

    switch ($result_code) {
      case 'SUCCESS':
        $order->payment_complete($payment_id);
        $order->add_order_note(
          sprintf(__('Antom payment completed via %s. Payment ID: %s', 'epos-payment'), $payment_method_type ?: 'unknown', $payment_id)
        );
        break;

      case 'FAIL':
        $order->update_status('failed', __('Antom payment failed.', 'epos-payment'));
        break;

      case 'CANCELLED':
        $order->update_status('cancelled', __('Antom payment cancelled.', 'epos-payment'));
        break;

      default:
        Zippy_Pay_Logger::error('Antom webhook: unknown result code.', [
          'resultCode' => $result_code,
          'order_id'   => $order->get_id(),
        ]);
        break;
    }
  }

  /**
   * Send JSON response to Antom and exit.
   *
   * @param string $result_code
   * @param string $result_message
   */
  private function send_response($result_code, $result_message)
  {
    status_header(200);
    header('Content-Type: application/json');

    echo wp_json_encode([
      'result' => [
        'resultCode'    => $result_code,
        'resultMessage' => $result_message,
      ],
    ]);

    exit;
  }
}
