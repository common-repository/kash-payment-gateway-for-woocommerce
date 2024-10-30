<?php

require_once('KashPaymentGateway.php');

use Kash\KashWCPaymentGateway;

/**
 * Some version of WooCommerce or WordPress can't handle having the namespace
 * when loading settings. Use this as a proxy.
 */
class WC_Gateway_Kash extends KashWCPaymentGateway {
}
?>
