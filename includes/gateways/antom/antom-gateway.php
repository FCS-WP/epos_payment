<?php

namespace EPOS_PAYMENT\Includes\Gateways\Antom;

defined('ABSPATH') || exit;

use EPOS_PAYMENT\Includes\Logs\Zippy_Pay_Logger;

class Antom_Gateway extends \WC_Payment_Gateway
{

  public function __construct()
  {
    $this->id                 = 'antom';
    $this->method_title       = __('Antom', 'epos-payment');
    $this->method_description = __('Accept payments via Antom payment gateway.', 'epos-payment');
    $this->has_fields         = false;
    $this->supports           = ['products'];

    // Load the settings.
    $this->init_form_fields();
    $this->init_settings();

    $this->title       = $this->get_option('title');
    $this->description = $this->get_option('description');
    $this->enabled     = $this->get_option('enabled');

    // Save settings in admin.
    add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

    // Register webhook handler.
    add_action('woocommerce_api_antom_payment', [$this, 'handle_webhook']);

    // Register inquiry payment background handler.
    add_action(ANTOM_INQUIRY_HOOK, [$this, 'handle_inquiry_payment']);
  }

  /**
   * Admin settings fields.
   */
  public function init_form_fields()
  {
    $this->form_fields = [
      'enabled' => [
        'title'   => __('Enable/Disable', 'epos-payment'),
        'type'    => 'checkbox',
        'label'   => __('Enable Antom Payment', 'epos-payment'),
        'default' => 'no',
      ],
      'title' => [
        'title'       => __('Title', 'epos-payment'),
        'type'        => 'text',
        'description' => __('Payment method title that the customer will see during checkout.', 'epos-payment'),
        'default'     => __('Antom', 'epos-payment'),
        'desc_tip'    => true,
      ],
      'description' => [
        'title'       => __('Description', 'epos-payment'),
        'type'        => 'textarea',
        'description' => __('Payment method description that the customer will see during checkout.', 'epos-payment'),
        'default'     => __('Pay via Antom payment gateway.', 'epos-payment'),
        'desc_tip'    => true,
      ],
      'client_id' => [
        'title'       => __('Client ID', 'epos-payment'),
        'type'        => 'text',
        'description' => __('Your Antom Client ID. Use your sandbox Client ID (starts with SANDBOX_) for testing.', 'epos-payment'),
        'desc_tip'    => true,
      ],
      'private_key' => [
        'title'       => __('Merchant Private Key', 'epos-payment'),
        'type'        => 'textarea',
        'description' => __('Your RSA private key for signing API requests.', 'epos-payment'),
        'desc_tip'    => true,
      ],
      'alipay_public_key' => [
        'title'       => __('Antom Public Key', 'epos-payment'),
        'type'        => 'textarea',
        'description' => __('Antom RSA public key for verifying API responses.', 'epos-payment'),
        'desc_tip'    => true,
      ],
    ];
  }

  /**
   * Process the payment.
   *
   * @param int $order_id
   * @return array
   */
  public function process_payment($order_id)
  {
    $order = wc_get_order($order_id);

    $service = new Antom_Service(
      ANTOM_GATEWAY_URL,
      $this->get_option('client_id'),
      $this->get_option('private_key'),
      $this->get_option('alipay_public_key')
    );

    $result = $service->create_session(
      $order,
      $this->get_return_url($order),
      WC()->api_request_url('antom_payment')
    );

    if (is_wp_error($result)) {
      wc_add_notice($result->get_error_message(), 'error');
      return ['result' => 'failure'];
    }

    $order->update_status('pending', __('Awaiting Antom payment.', 'epos-payment'));

    if (!empty($result['paymentSessionId'])) {
      $order->update_meta_data('_antom_payment_session_id', $result['paymentSessionId']);
      $order->save();
    }

    // Schedule background inquiry as webhook fallback (5 minutes delay).
    as_schedule_single_action(time() + 300, ANTOM_INQUIRY_HOOK, ['order_id' => $order_id]);

    return [
      'result'   => 'success',
      'redirect' => $result['normalUrl'] ?? $order->get_checkout_payment_url(),
    ];
  }

  /**
   * Handle Antom webhook notification.
   */
  public function handle_webhook()
  {
    $webhook = new Antom_Webhook(
      $this->get_option('client_id'),
      $this->get_option('alipay_public_key')
    );

    $webhook->handle();
  }

  /**
   * Background inquiry payment status (webhook fallback).
   *
   * @param int $order_id
   */
  public function handle_inquiry_payment($order_id)
  {
    $order = wc_get_order($order_id);

    if (!$order) {
      Zippy_Pay_Logger::error('Antom inquiry: order not found.', ['order_id' => $order_id]);
      return;
    }

    if ($order->is_paid()) {
      Zippy_Pay_Logger::info('Antom inquiry: order already paid, skipping.', ['order_id' => $order_id]);
      return;
    }

    $payment_request_id = $order->get_meta(ANTOM_META_PAYMENT_REQUEST_ID);

    if (empty($payment_request_id)) {
      Zippy_Pay_Logger::error('Antom inquiry: missing payment request ID.', ['order_id' => $order_id]);
      return;
    }

    $service = new Antom_Service(
      ANTOM_GATEWAY_URL,
      $this->get_option('client_id'),
      $this->get_option('private_key'),
      $this->get_option('alipay_public_key')
    );

    $response = $service->inquiry_payment($payment_request_id);

    if (is_wp_error($response)) {
      Zippy_Pay_Logger::error('Antom inquiry: API error.', [
        'order_id' => $order_id,
        'message'  => $response->get_error_message(),
      ]);
      return;
    }

    $payment_status      = $response['paymentStatus'] ?? '';
    $payment_id          = $response['paymentId'] ?? '';
    $payment_method_type = $response['paymentMethodType'] ?? '';
    $result_code         = $response['result']['resultCode'] ?? '';

    Zippy_Pay_Logger::info('Antom inquiry: response received.', [
      'order_id'       => $order_id,
      'paymentStatus'  => $payment_status,
      'paymentId'      => $payment_id,
      'resultCode'     => $result_code,
    ]);

    if ($result_code !== 'SUCCESS') {
      Zippy_Pay_Logger::error('Antom inquiry: API returned non-success result.', [
        'order_id'   => $order_id,
        'resultCode' => $result_code,
      ]);
      return;
    }

    switch ($payment_status) {
      case 'SUCCESS':
        if (!empty($payment_method_type)) {
          $order->set_payment_method_title('Antom - ' . $payment_method_type);
        }
        $order->payment_complete($payment_id);
        $order->add_order_note(
          sprintf(__('Antom payment confirmed via inquiry. Payment ID: %s', 'epos-payment'), $payment_id)
        );
        break;

      case 'FAIL':
        $order->update_status('failed', __('Antom payment failed (confirmed via inquiry).', 'epos-payment'));
        break;

      case 'CANCELLED':
        $order->update_status('cancelled', __('Antom payment cancelled (confirmed via inquiry).', 'epos-payment'));
        break;

      case 'PROCESSING':
        $retry_count = (int) $order->get_meta('_antom_inquiry_retry_count');
        if ($retry_count < 1) {
          $order->update_meta_data('_antom_inquiry_retry_count', $retry_count + 1);
          $order->save();
          as_schedule_single_action(time() + 300, ANTOM_INQUIRY_HOOK, ['order_id' => $order_id]);
          Zippy_Pay_Logger::info('Antom inquiry: still processing, scheduled retry.', ['order_id' => $order_id]);
        } else {
          Zippy_Pay_Logger::info('Antom inquiry: still processing after max retries.', ['order_id' => $order_id]);
        }
        break;

      default:
        Zippy_Pay_Logger::error('Antom inquiry: unknown payment status.', [
          'order_id'      => $order_id,
          'paymentStatus' => $payment_status,
        ]);
        break;
    }
  }
}
