<?php

namespace EPOS_PAYMENT\Includes\Gateways;

defined('ABSPATH') || exit;

use EPOS_PAYMENT\Includes\Gateways\Antom\Antom_Gateway;

class Gateways_Init
{

  /**
   * The single instance of the class.
   *
   * @var Gateways_Init
   */
  protected static $_instance = null;

  /**
   * @return Gateways_Init
   */
  public static function get_instance()
  {
    if (is_null(self::$_instance)) {
      self::$_instance = new self();
    }
    return self::$_instance;
  }

  public function __construct()
  {
    add_filter('woocommerce_payment_gateways', [$this, 'add_gateway']);

    add_action('before_woocommerce_init', function () {
      if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', EPOS_PAYMENT_DIR_PATH, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_block_editor', EPOS_PAYMENT_DIR_PATH, false);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', EPOS_PAYMENT_DIR_PATH, false);
      }
    });
  }

  /**
   * Register payment gateways.
   *
   * @param array $gateways
   * @return array
   */
  public function add_gateway($gateways)
  {
    $gateways[] = Antom_Gateway::class;
    return $gateways;
  }

}
