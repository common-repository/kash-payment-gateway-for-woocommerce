<?php
namespace Kash;

use DateTime;
use DateTimeZone;
use Exception;
use WP_Error;
use Kash\Utils\Signature;
use Kash\Utils\DebugLogger;
use Kash\Utils\EventLogger;


class KashWCPaymentGateway extends \WC_Payment_Gateway {

    private $logger = null;

    private static $api_url_prod = 'https://api.withkash.com/v1';

    // NOTE: DO NOT CHANGE THE STRING or else need to upgrade the value for all orders
    private static $META_PAYMENT_TYPE = 'x_payment_type';
    private static $META_GATEWAY_REFERENCE = 'x_gateway_reference';
    private static $META_KASH_DISCOUNT = 'x_kash_discount';

    function __construct($orderFactory = null, $signature = null) {
        $this->id = 'kash_wc_payment_gateway';
        $this->has_fields = false;

        // This is shown next to the Checkout Options under WooCommerce's Checkout tab
        $this->method_title = 'Kash';
        // This is shown as the description on the Kash settings page
        $this->method_description = 'Accept direct debit payments';

        $this->supports = array('products', 'refunds');

        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title = $this->get_option('title'); // This is the title shown during checkout
        $this->description = $this->get_option('description'); // This is the description shown during checkout
        $this->debug = 'yes' === $this->get_option('debug', 'no'); // Debug mode controls whether logging is enabled

        $this->debugLogger = new DebugLogger();
        $this->debugLogger->setEnabled($this->debug);
        $this->eventLogger = new EventLogger($this->get_option('gateway_url'), $this->get_option('account_id'));

        $this->signature = ($signature !== null) ? $signature : new Signature();
        $this->orderFactory = ($orderFactory !== null) ? $orderFactory : new \WC_Order_Factory();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_wc_gateway_kash', array($this, 'check_kash_response'));

        if (!$this->is_valid_for_use()) {
            $this->enabled = 'no';
        }
    }

    /**
     * record_sales_metrics function
     */
    public function record_sales_metrics($order_id) {
        $server_key = $this->get_option('server_key');
        $gateway_url = $this->get_option('gateway_url');
        $account_id = $this->get_option('account_id');

        $order = $this->orderFactory->get_order( $order_id );

        $paymentMethod = $order->payment_method;

        if ($paymentMethod == $this->id) {
            $paymentMethod = get_post_meta($order->id, self::$META_PAYMENT_TYPE, true);
        }

        $payload = array(
            'x_account_id' => $account_id,
            'x_amount' => $order->order_total,
            'x_payment' => $paymentMethod
        );

        $payload['x_signature'] = $this->signature->compute($payload, $server_key);
        $response = wp_remote_post($gateway_url . 'reporting', array(
            'method' => 'POST',
            'redirection' => 0,
            'body' => $payload
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->debugLogger->log('Recording metric error for order#' . $order_id . ': ' . $error_message);
            $this->eventLogger->log('MetricEvent',
                    'Recording metric error for order#' . $order_id,
                    json_encode($response));
            return;
        }
        else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 200) {
                $this->debugLogger->log('Metric recorded' . $order_id);
                return true;
            }
            else {
                $this->debugLogger->log('Metric error for order#' . $order_id . ': Got return code: ' . $response_code);
                $this->eventLogger->log('MetricEvent',
                        'Recording metric error for order#' . $order_id,
                        json_encode($response));
                return new WP_Error('error', 'Metric logging error: Got status code: ' . $response_code);
            }
        }
    }

    public function record_plugin_event($type) {

        $server_key = $this->get_option('server_key');
        $gateway_url = $this->get_option('gateway_url');
        $account_id = $this->get_option('account_id');

        $payload = array(
            'x_account_id' => $account_id,
            'x_event' => $type,
        );

        $payload['x_signature'] = $this->signature->compute($payload, $server_key);

        $response = wp_remote_post($gateway_url . 'woocommerce/metrics', array(
            'method' => 'POST',
            'redirection' => 0,
            'body' => $payload
        ));

    }


    /**
     * get_icon function.
     *
     * @return string
     */
    public function get_icon() {

        $gateway_html = "";
        $explanation_html = "";
        $logo_url = '//cdn.withkash.com/wc-assets/';

        if (preg_match('/iPad|iPhone|iPod|Android/i', $_SERVER['HTTP_USER_AGENT'])) {
            $logo_url = $logo_url . 'mobilelogo.png';
        }
        else {
            $logo_url = $logo_url . 'gatewaylogo.png';
        }

        if ($this->get_option('explanation') == 'yes') {
            $explanation_html = "<a style='display:inline-block;float:right;border:none;font-size:.83em;line-height:53px;' href='//withkash.com/what-is-direct-debit' onclick=\"javascript:window.open('//withkash.com/what-is-direct-debit','WithKash','toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, width=900, height=600'); return false;\" title='What is Direct Debit?'>What is Direct Debit?</a>";
        }

        $gateway_html = "<img src='".$logo_url."' alt='Credit/Direct Debit Logo'>" . $explanation_html;
        return apply_filters('woocommerce_gateway_icon', $gateway_html, $this->id);
    }

    // Only allow this gateway to be used for US dollars
    function is_valid_for_use() {
        return in_array(get_woocommerce_currency(), array('USD'));
    }

    /**
     * Process a refund
     * @param  int $order_id
     * @param  string $amount
     * @param  string $reason
     * @return  boolean True or false based on success, or a WP_Error object
     */
    public function process_refund($order_id, $amount = null, $reason = '') {

        //cast amount to a number and use loose equality so both 0 and 0.00 are included
        if ($amount * 1 == 0) {
            return new WP_Error('error', 'Please specify an amount to issue a refund.');
        }

        $order = $this->orderFactory->get_order($order_id);
        $order_number = $order->get_order_number();

        $server_key = $this->get_option('server_key');
        $headers = array(
            'Authorization' => 'Basic ' . base64_encode($server_key . ':')
        );

        $kash_discount = get_post_meta( $order->id, self::$META_KASH_DISCOUNT, true );

        // $refunded_amount includes the current $amount provided to this function
        $refunded_amount = '0.00';
        if ($refunds = $order->get_refunds()) {
            foreach ($refunds as $id => $refund) {
                $refunded_amount = $refunded_amount + $refund->get_refund_amount();
            }
        }

        if (empty($kash_discount) || $refunded_amount != $order->get_total()) {
            $refundAmount = $amount;
        }
        else {
            // Include the kash discount amount so that this refund will be
            // seen as a completing a refund for the remaining amount.
            $refundAmount = $amount + $kash_discount;
        }

        $payload = array(
            'amount' => $refundAmount * 100,
            'x_reference' => $order_number
        );

        $gateway_url = $this->get_option('gateway_url');
        $url = self::$api_url_prod;

        if (strpos($gateway_url, 'februalia') !== false) {
            $url = str_replace('withkash', 'februalia', $url);
        }
        else if ($gateway_url === 'http://kash-gateway') {
            $url = 'http://kash-api:8080';
        }

        $this->debugLogger->log('Processing refund with ' . $url . '/refunds');
        $response = wp_remote_post($url . '/refunds', array(
            'method' => 'POST',
            'headers' => $headers,
            'body' => $payload
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->debugLogger->log('Refund error for order#' . $order_number . ': ' . $error_message);
            $this->eventLogger->log('RefundEvent',
                    'Refund error for order#' . $order_number,
                    json_encode($response));
            return false;
        }
        else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 200) {
                $this->debugLogger->log('Refund success for order#' . $order_number);
                return true;
            }
            else {
                $error_body = json_decode($response['body']);
                $this->debugLogger->log('Refund error for order#' . $order_number . ': Got return code: ' . $response_code . ' with body: ' . print_r($error_body, true));
                $this->eventLogger->log('RefundEvent',
                        'Refund error for order#' . $order_number,
                        json_encode($response));
                return new WP_Error('error', 'Refund Failed: ' . $error_body->message);
            }
        }
    }

    function update_discount_total($order, $kash_discount) {
        if (!is_null($kash_discount)) {
            update_post_meta($order->id, self::$META_KASH_DISCOUNT, $kash_discount);
            $order->set_total(wc_format_decimal($order->get_total_discount() + $kash_discount), 'cart_discount');
            // Subtract the discount from the total amount.
            $order->set_total(wc_format_decimal($order->get_total() - $kash_discount), 'total');
        }
        return true;
    }

    function check_kash_response() {
        global $woocommerce;

        $server_key = $this->get_option('server_key');

        if ($this->signature->verify($_REQUEST, $server_key)) {
            $order_number = $_REQUEST['x_reference'];
            $payment_type = $_REQUEST['x_transaction_type'];
            $gateway_reference = $_REQUEST['x_gateway_reference'];
            $kash_discount = $_REQUEST['x_discount'];
            $amount = $_REQUEST['x_amount'];
            $result = $_REQUEST['x_result'];

            $this->debugLogger->log('Kash Callback with: ' . print_r($_REQUEST, true));


            if ($result !== 'completed' || $gateway_reference === NULL) {
                 $this->debugLogger->log('Kash callback with invalid parameters [OrderID: ' . $order_number
                        . ' Result: ' . $result . ' Reference: ' . $gateway_reference . ']');
                 $this->eventLogger->log('PaymentCallback',
                         'Kash callback with invalid parameters',
                         json_encode($_REQUEST));
                 return;
            }

            $order = $this->orderFactory->get_order(absint($order_number));

            $paymentSaveResult = add_post_meta($order->id, self::$META_PAYMENT_TYPE, $payment_type);
            if ($paymentSaveResult === false) {
                $this->debugLogger->log('Could not save payment type.');
            }

            $paymentSaveResult = add_post_meta($order->id, self::$META_GATEWAY_REFERENCE, $gateway_reference);
            if ($paymentSaveResult === false) {
                $this->debugLogger->log('Could not save gateway reference.');
            }

            try {
                if ($kash_discount > 0) {
                    $this->update_discount_total($order, $kash_discount);
                }
                // Mark order as complete
                $order->payment_complete($gateway_reference);

                // Remove cart
                $woocommerce->cart->empty_cart();

                $this->debugLogger->log('Completed order for order#' . $order_number);

                wp_redirect($this->get_return_url($order));
            } catch (Exception $e) {
                $this->debugLogger->log('Unable to complete transaction: ' . $e->getMessage());

                $this->eventLogger->log('PaymentCallback',
                                         'Unable to complete transaction from WooCommerce plugin: ' . $e->getMessage(),
                                         json_encode(array(
                                            'request' => $_REQUEST,
                                            'error' => $e)));

            }

        }
        else {
            $this->debugLogger->log('Invalid signature: ' . print_r($_REQUEST, true));
            $this->eventLogger->log('PaymentCallback',
                                     'Invalid signature ',
                                     json_encode($_REQUEST));
        }
    }

    // Initialize Settings form fields
    function init_form_fields() {
        $this->form_fields = include( 'includes/settings-kash-payment-gateway.php' );
    }

    function process_payment($order_id) {
        $order = $this->orderFactory->get_order($order_id);
        $order_number = $order->get_order_number();

        $is_test_mode = $this->get_option('test_mode', 'no') === 'yes' ? 'true' : 'false';

        $payload = array(
            'x_account_id' => $this->get_option('account_id'),
            'x_currency' => $order->get_order_currency(),
            'x_amount' => $order->get_total(),
            'x_amount_shipping' => $order->get_total_shipping(),
            'x_amount_tax' => $order->get_total_tax(),
            'x_reference' => $order_number,
            'x_shop_country' => 'US',
            'x_test' => $is_test_mode,
            'x_customer_first_name' => $order->billing_first_name,
            'x_customer_last_name' => $order->billing_last_name,
            'x_customer_email' => $order->billing_email,
            'x_customer_phone' => $order->billing_phone,
            'x_customer_shipping_city' => $order->shipping_city,
            'x_customer_shipping_company' => $order->shipping_company,
            'x_customer_shipping_address1' => $order->shipping_address_1,
            'x_customer_shipping_address2' => $order->shipping_address_2,
            'x_customer_shipping_state' => $order->shipping_state,
            'x_customer_shipping_zip' => $order->shipping_postcode,
            'x_customer_shipping_country' => $order->shipping_country,
            'x_url_complete' => $this->get_return_url($order),
            'x_url_callback' => add_query_arg('wc-api', 'WC_Gateway_Kash', home_url('/')),
            'x_url_cancel' => str_replace("&#038;", "&", $order->get_cancel_order_url()),
            'x_timestamp' => get_timestamp(),
            'x_plugin' => 'woocommerce',
            'x_version' => '3'
        );

        $server_key = $this->get_option('server_key');
        $payload['x_signature'] = $this->signature->compute($payload, $server_key);

        $this->debugLogger->log('Talk to gateway for order#' . $order_number . ': ' . print_r($payload, true));
        $gateway_url = $this->get_option('gateway_url');
        $response = wp_remote_post($gateway_url, array(
            'method' => 'POST',
            'redirection' => 0,
            'body' => $payload
        ));

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->debugLogger->log('Payment error for order#' . $order_number . ': ' . $error_message);
            $this->eventLogger->log('ProcessPayment',
                                     'Payment error for order#' . $order_number,
                                     json_encode($response));
            wc_add_notice(__('Payment error:', 'woothemes') . ' ' . $error_message, 'error');
            return;
        }
        else {
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code === 303) {
                $location = wp_remote_retrieve_header($response, 'location');

                // If gateway decided to use x_url_cancel to come back, then something was wrong.
                if ($location === $payload['x_url_cancel']) {
                    $error_message = "Error communicating with gateway";
                    $log_message = 'Payment error for order#' . $order_number . ': Error communicating with gateway';
                    $this->debugLogger->log($log_message);
                    $this->eventLogger->log('ProcessPayment',
                                             $log_message,
                                             json_encode($error_message));
                    wc_add_notice(__('Payment error:', 'woothemes') . ' ' . $error_message, 'error');
                    return;
                }
                else {
                    $this->debugLogger->log('Gateway redirect success for order#' . $order_number);
                    return array(
                        'result' => 'success',
                        'redirect' => $location
                    );
                }
            }
            else {
                $error_message = 'Got return code: ' . $response_code;
                $this->debugLogger->log('Payment error for order#' . $order_number . ': Got return code: ' . $response_code);
                $this->eventLogger->log('ProcessPayment',
                                         'Payment error for order#' . $order_number . ': Got return code: ' . $response_code,
                                         json_encode($error_message));

                wc_add_notice(__('Payment error:', 'woothemes') . ' ' . $error_message, 'error');
                return;
            }
        }
     }
}
