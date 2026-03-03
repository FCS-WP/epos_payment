<?php

namespace EPOS_PAYMENT\Includes\Gateways\Antom;

defined('ABSPATH') || exit;

use EPOS_PAYMENT\Includes\Logs\Zippy_Pay_Logger;

class Antom_Signature
{

  /**
   * Generate a request signature.
   *
   * @param string $http_method
   * @param string $path
   * @param string $client_id
   * @param string $req_time
   * @param string $body
   * @param string $merchant_private_key PEM-formatted private key
   * @return string URL-encoded base64 signature, or empty string on failure
   */
  public static function sign($http_method, $path, $client_id, $req_time, $body, $merchant_private_key)
  {
    $content   = self::build_sign_content($http_method, $path, $client_id, $req_time, $body);
    $signature = self::sign_with_rsa($content, $merchant_private_key);

    return urlencode($signature);
  }

  /**
   * Verify a response signature.
   *
   * @param string $http_method
   * @param string $path
   * @param string $client_id
   * @param string $rsp_time
   * @param string $rsp_body
   * @param string $signature
   * @param string $alipay_public_key PEM-formatted public key
   * @return bool
   */
  public static function verify($http_method, $path, $client_id, $rsp_time, $rsp_body, $signature, $alipay_public_key)
  {
    $content = self::build_sign_content($http_method, $path, $client_id, $rsp_time, $rsp_body);
    return self::verify_with_rsa($content, $signature, $alipay_public_key);
  }

  /**
   * Build the content string to be signed.
   * Format: "{httpMethod} {path}\n{clientId}.{time}.{body}"
   *
   * @param string $http_method
   * @param string $path
   * @param string $client_id
   * @param string $time
   * @param string $body
   * @return string
   */
  private static function build_sign_content($http_method, $path, $client_id, $time, $body)
  {
    return $http_method . " " . $path . "\n" . $client_id . "." . $time . "." . $body;
  }

  /**
   * Sign content with RSA-SHA256.
   *
   * @param string $content
   * @param string $private_key_pem
   * @return string Base64-encoded signature, or empty string on failure
   */
  private static function sign_with_rsa($content, $private_key_pem)
  {
    $key = openssl_pkey_get_private($private_key_pem);
    if (!$key) {
      Zippy_Pay_Logger::error('Antom Signature: Failed to parse private key.', [
        'openssl_error' => openssl_error_string(),
      ]);
      return '';
    }

    $signature = '';
    if (!openssl_sign($content, $signature, $key, OPENSSL_ALGO_SHA256)) {
      Zippy_Pay_Logger::error('Antom Signature: openssl_sign failed.', [
        'openssl_error' => openssl_error_string(),
      ]);
      return '';
    }

    return base64_encode($signature);
  }

  /**
   * Verify content signature with RSA-SHA256.
   *
   * @param string $content
   * @param string $signature
   * @param string $public_key_pem
   * @return bool
   */
  private static function verify_with_rsa($content, $signature, $public_key_pem)
  {
    if (strstr($signature, '=') || strstr($signature, '+') || strstr($signature, '/')
      || $signature === base64_encode(base64_decode($signature))) {
      $decoded = base64_decode($signature);
    } else {
      $decoded = base64_decode(urldecode($signature));
    }

    return openssl_verify($content, $decoded, $public_key_pem, OPENSSL_ALGO_SHA256) === 1;
  }

  /**
   * Format a private key string into proper PEM.
   * Supports both PKCS#1 and PKCS#8 formats.
   *
   * @param string $key
   * @return string PEM-formatted key
   */
  public static function format_private_key($key)
  {
    $key = str_replace('\n', "\n", $key);
    $key = str_replace("\r\n", "\n", $key);

    if (strpos($key, '-----BEGIN') !== false) {
      return trim($key);
    }

    $raw = preg_replace('/\s+/', '', $key);

    $pkcs8 = "-----BEGIN PRIVATE KEY-----\n" . wordwrap($raw, 64, "\n", true) . "\n-----END PRIVATE KEY-----";
    if (@openssl_pkey_get_private($pkcs8)) {
      return $pkcs8;
    }

    $pkcs1 = "-----BEGIN RSA PRIVATE KEY-----\n" . wordwrap($raw, 64, "\n", true) . "\n-----END RSA PRIVATE KEY-----";
    if (@openssl_pkey_get_private($pkcs1)) {
      return $pkcs1;
    }

    return $pkcs8;
  }

  /**
   * Format a public key string into proper PEM.
   *
   * @param string $key
   * @return string PEM-formatted key
   */
  public static function format_public_key($key)
  {
    $key = str_replace('\n', "\n", $key);
    $key = str_replace("\r\n", "\n", $key);

    if (strpos($key, '-----BEGIN') !== false) {
      return trim($key);
    }

    $raw = preg_replace('/\s+/', '', $key);
    return "-----BEGIN PUBLIC KEY-----\n" . wordwrap($raw, 64, "\n", true) . "\n-----END PUBLIC KEY-----";
  }

}
