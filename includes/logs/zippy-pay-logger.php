<?php

namespace EPOS_PAYMENT\Includes\Logs;

defined('ABSPATH') || exit;

class Zippy_Pay_Logger
{

  const LOG_FILENAME = 'epos_payment';

  /**
   * Log an informational message.
   *
   * @param string     $message
   * @param array|null $data
   */
  public static function info($message, $data = null)
  {
    self::write('info', $message, $data);
  }

  /**
   * Log an error message.
   *
   * @param string     $message
   * @param array|null $data
   */
  public static function error($message, $data = null)
  {
    self::write('error', $message, $data);
  }

  /**
   * Log a debug message.
   *
   * @param string     $message
   * @param array|null $data
   */
  public static function debug($message, $data = null)
  {
    self::write('debug', $message, $data);
  }

  /**
   * Write a log entry using WooCommerce logger.
   *
   * @param string     $level   One of: debug, info, notice, warning, error, critical, alert, emergency.
   * @param string     $message
   * @param array|null $data
   */
  private static function write($level, $message, $data = null)
  {
    if (!class_exists('WC_Logger')) {
      return;
    }

    $logger = wc_get_logger();

    $log_entry = sprintf('[%s] %s', strtoupper($level), $message);

    if (!empty($data)) {
      $log_entry .= "\n" . wc_print_r($data, true);
    }

    $logger->log($level, $log_entry, ['source' => self::LOG_FILENAME]);
  }

}
