<?php

/* Set constant url to the plugin directory. */

if (!defined('EPOS_CRM_URL')) {
  define('EPOS_CRM_URL', plugin_dir_url(__FILE__));
}

/* Antom Payment Gateway URL */

if (!defined('ANTOM_GATEWAY_URL')) {
  define('ANTOM_GATEWAY_URL', 'https://open-sea-global.alipay.com');
}
