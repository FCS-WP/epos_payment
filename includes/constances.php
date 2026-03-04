<?php

/* Set constant url to the plugin directory. */

if (!defined('EPOS_CRM_URL')) {
  define('EPOS_CRM_URL', plugin_dir_url(__FILE__));
}

/* Antom Payment Gateway URL */

if (!defined('ANTOM_GATEWAY_URL')) {
  define('ANTOM_GATEWAY_URL', 'https://open-sea-global.alipay.com');
}

/* Antom Order Meta Keys */

if (!defined('ANTOM_META_PAYMENT_REQUEST_ID')) {
  define('ANTOM_META_PAYMENT_REQUEST_ID', '_antom_payment_request_id');
}
