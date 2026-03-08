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
        'goods'            => $this->build_goods($order, $currency),
        'shipping'         => $this->build_shipping($order),
      ],
      'paymentRedirectUrl' => $return_url,
      'paymentNotifyUrl'   => $notify_url,
      'availablePaymentMethod' => [
        'paymentMethodTypeList' => $this->get_payment_methods_by_currency($currency),
      ],
    ];

    $order->update_meta_data(ANTOM_META_PAYMENT_REQUEST_ID, $request_id);
    $order->save();

    $path = $this->get_api_path('/payments/createPaymentSession');

    return $this->request($path, $body);
  }

  /**
   * Inquiry payment status from Antom.
   *
   * @param string $payment_request_id
   * @return array|\WP_Error
   */
  public function inquiry_payment($payment_request_id)
  {
    $body = [
      'paymentRequestId' => $payment_request_id,
    ];

    $path = $this->get_api_path('/payments/inquiryPayment');

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
   * Build goods array from WooCommerce order items.
   *
   * @param \WC_Order $order
   * @param string    $currency
   * @return array
   */
  private function build_goods($order, $currency)
  {
    $goods      = [];
    $goods_total = 0;

    foreach ($order->get_items() as $item) {
      $product    = $item->get_product();
      $unit_value = intval(round(($item->get_total() / $item->get_quantity()) * 100));
      $goods_total += $unit_value * $item->get_quantity();

      $good = [
        'referenceGoodsId' => (string) ($product ? $product->get_sku() ?: $product->get_id() : $item->get_product_id()),
        'goodsName'        => $item->get_name(),
        'goodsSkuName'    => $product->get_sku() ?: '',
        'goodsQuantity'    => (string) $item->get_quantity(),
        'goodsUnitAmount'  => [
          'currency' => $currency,
          'value'    => (string) $unit_value,
        ],
      ];

      if ($product) {
        $image_id  = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

        if (!empty($image_url)) {
          $good['goodsImageUrl'] = $image_url;
        }

        if ($product->get_short_description()) {
          $good['goodsCategory'] = wp_strip_all_tags(substr($product->get_short_description(), 0, 256));
        }
      }

      $goods[] = $good;
    }

    // Shipping fee
    $shipping_total = intval(round($order->get_shipping_total() * 100));
    if ($shipping_total > 0) {
      $goods_total += $shipping_total;
      $goods[] = [
        'referenceGoodsId' => 'shipping-fee',
        'goodsName'        => 'Shipping Fee',
        'goodsQuantity'    => '1',
        'goodsUnitAmount'  => [
          'currency' => $currency,
          'value'    => (string) $shipping_total,
        ],
      ];
    }

    // Tax with label
    $tax_total = intval(round($order->get_total_tax() * 100));
    if ($tax_total > 0) {
      $tax_label = 'Tax';
      $tax_items = $order->get_items('tax');
      if (!empty($tax_items)) {
        $first_tax = reset($tax_items);
        $tax_label = $first_tax->get_label() ?: 'Tax';
      }

      $goods_total += $tax_total;
      $goods[] = [
        'referenceGoodsId' => 'tax',
        'goodsName'        => $tax_label,
        'goodsQuantity'    => '1',
        'goodsUnitAmount'  => [
          'currency' => $currency,
          'value'    => (string) $tax_total,
        ],
      ];
    }

    // Other fees (coupons, surcharges, etc.)
    foreach ($order->get_items('fee') as $fee_item) {
      $fee_value = intval(round($fee_item->get_total() * 100));
      if ($fee_value > 0) {
        $goods_total += $fee_value;
        $goods[] = [
          'referenceGoodsId' => 'fee-' . $fee_item->get_id(),
          'goodsName'        => $fee_item->get_name() ?: 'Fee',
          'goodsUnitAmount'  => [
            'currency' => $currency,
            'value'    => (string) $fee_value,
          ],
        ];
      }
    }

    // Catch any remaining difference (rounding, discounts adjustments)
    $order_total   = intval(round($order->get_total() * 100));
    $remaining     = $order_total - $goods_total;

    if ($remaining > 0) {
      $goods[] = [
        'referenceGoodsId' => 'adjustment',
        'goodsName'        => 'Adjustment',
        'goodsQuantity'    => '1',
        'goodsUnitAmount'  => [
          'currency' => $currency,
          'value'    => (string) $remaining,
        ],
      ];
    }

    return $goods;
  }

  /**
   * Build shipping info from WooCommerce order.
   *
   * @param \WC_Order $order
   * @return array
   */
  private function build_shipping($order)
  {
    $shipping = [
      'shippingName'    => [
        'firstName' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
        'lastName'  => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
      ],
      'shippingAddress' => [
        'address1' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
        'address2' => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
        'city'     => $order->get_shipping_city() ?: $order->get_billing_city(),
        'state'    => $order->get_shipping_state() ?: $order->get_billing_state(),
        'zipCode'  => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
        'region'   => $order->get_shipping_country() ?: $order->get_billing_country(),
      ],
    ];

    $phone = $order->get_billing_phone();
    if (!empty($phone)) {
      $shipping['shippingPhoneNo'] = $phone;
    }

    return $shipping;
  }

  /**
   * Get available payment methods based on currency.
   *
   * @param string $currency
   * @return array
   */
  private function get_payment_methods_by_currency($currency)
  {
    $methods = [
      'SGD' => [
        ['paymentMethodType' => 'CARD'],
        ['paymentMethodType' => 'PAYNOW'],
      ],
      'MYR' => [
        ['paymentMethodType' => 'CARD'],
        ['paymentMethodType' => 'TNG'],
      ],
    ];

    return $methods[$currency] ?? [['paymentMethodType' => 'CARD']];
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
