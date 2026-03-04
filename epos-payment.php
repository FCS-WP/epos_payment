<?php
/*
Plugin Name: EPOS PAYMENT
Plugin URI: https://epos.com
Description: EPOS Payment is a plugin that allows you to accept payments on your WordPress site.
Version: 1.0
Required PHP Version: 7.4
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 6.8
Author: EPOS Website Team
Author URI: https://epos.com
License: GNU General Public License v3.0
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Domain Path: /languages
Text Domain: epos-payment
*/

namespace EPOS_PAYMENT;

defined('ABSPATH') || exit;

if (!defined('EPOS_PAYMENT_DIR_PATH')) {
  define('EPOS_PAYMENT_DIR_PATH', plugin_dir_path(__FILE__));
}

// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);


// Include the autoloader.
require_once EPOS_PAYMENT_DIR_PATH . 'includes/autoload.php';

// Include the constances file.
require_once EPOS_PAYMENT_DIR_PATH . 'includes/constances.php';

use EPOS_PAYMENT\Includes\Gateways\Gateways_Init;

Gateways_Init::get_instance();
