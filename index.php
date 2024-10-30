<?php
/**
 * Plugin Name: Kash Payment Gateway for WooCommerce
 * Plugin URI: http://www.withkash.com
 * Description: Kash Payment Gateway for WooCommerce
 * Version: 1.2.15
 * Author: Kash Corp.
 * Author URI: http://www.withkash.com
 * Requires at least: 4.1
 * Tested up to: 4.6.1
 */


$plugin = plugin_basename(__FILE__);
add_filter("plugin_action_links_$plugin", 'kash_woocommerce_settings');
add_filter('woocommerce_payment_gateways', 'add_kash_payment_gateway');

add_action('plugins_loaded', 'init_kash_wc_payment_gateway');
add_action('woocommerce_recorded_sales', 'handle_recorded_sales');


register_deactivation_hook(   __FILE__, 'deactivation_hook' );

function deactivation_hook() {
    if ( !class_exists('WC_Gateway_Kash') ) {
        return;
    }

    $kash_gateway = new WC_Gateway_Kash();
    $kash_gateway->record_plugin_event('deactivation');
}


function handle_recorded_sales($order_id) {
    if ( !class_exists('WC_Gateway_Kash') ) {
        return;
    }

    $kash_gateway = new WC_Gateway_Kash();
    $kash_gateway->record_sales_metrics($order_id);
}

function kash_woocommerce_settings($links) {
    $settings_link = '<a href="'
        . admin_url('admin.php?page=wc-settings&tab=checkout&section=wc_gateway_kash')
        . '">Settings</a>';
    
    array_unshift($links, $settings_link);
    return $links;
}

function add_kash_payment_gateway($methods) {
    $methods[] = 'WC_Gateway_Kash';
    return $methods;
}

function init_kash_wc_payment_gateway() {
    // Don't do anything if WooCommerce is not installed
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    require_once('utils/logger.php');
    require_once('utils/signature.php');
    require_once('utils/utils.php');
    require_once('WC_Gateway_Kash.php');
}


?>
