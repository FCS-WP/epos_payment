<?php

namespace EPOS_PAYMENT\Includes\Gateways\Antom;

defined('ABSPATH') || exit;

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

    return [
      'result'   => 'success',
      'redirect' => $result['normalUrl'] ?? $this->get_return_url($order),
    ];
  }
}
