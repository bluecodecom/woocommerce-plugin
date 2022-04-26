<?php
/**
 * bluecode payment plugin for WooCommerce
 *
 * @author      Michael Heumann, Comciencia SpA
 * @copyright   2021 Bluedode International AG
 * @license     GPL-2.0+
 *
 * @wordpress-plugin
 * Plugin Name: bluecode
 * Description: Plugin to integrate the Bluecode payment method into WooCommerce.
 * Version:     1.1.0
 * Author:      Bluecode International AG
 * Author URI:  http://bluecode.com
 * Text Domain: bluecode
 * License:     GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

/**
 * Add the Gateway to WooCommerce
 * */
function woocommerce_add_bluecode_gateway($methods) {
  $methods[] = 'bluecode';
  return $methods;
}

add_filter('woocommerce_payment_gateways', 'woocommerce_add_bluecode_gateway');

add_action('plugins_loaded', 'woocommerce_bluecode_init');

add_action('wp_ajax_nopriv_init_payment', array('bluecode', 'init_payment') );
add_action('wp_ajax_init_payment', array('bluecode', 'init_payment') );

add_action('wp_ajax_nopriv_load_oauth2_data', array('bluecode', 'load_oauth2_data'));
add_action('wp_ajax_load_oauth2_data', array('bluecode', 'load_oauth2_data'));

function woocommerce_bluecode_init() {
  if (!class_exists('WC_Payment_Gateway')) {
    return;
  }

  /**
   * Localization
   */
  load_plugin_textdomain('bluecode', false, dirname(plugin_basename(__FILE__)) . '/languages');

  /**
   * Gateway class
   */
  include_once dirname(__FILE__) . '/payments/Bluecode.php';
}
