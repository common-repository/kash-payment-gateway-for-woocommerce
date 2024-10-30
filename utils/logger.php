<?php

namespace Kash\Utils;

use Exception;

class DebugLogger {
    /** @var boolean Whether or not logging is enabled */
    public $log_enabled = false;

    function __construct() {
        $this->logger = new \WC_Logger();
    }

    /**
     * Logging method
     * @param  string $message
     */
    public function log($message) {
        if ($this->log_enabled) {
            $this->logger->add('kash', $message);
        }
    }

    public function setEnabled($enabled) {
        $this->log_enabled = $enabled;
    }
}

/**
 * Log events to payment gateway
 **/
class EventLogger {

    function __construct($gateway_url, $account_id) {
        $this->gateway_log_url = $gateway_url . "log";
        $this->account_id = $account_id;
    }

    public function log($event, $message, $additionalData = null) {
        $payload = array(
            'client' => 'WC_Gateway_Kash: ' . $this->account_id,
            'event' => $event,
            'data' => array(
                'message' => $message,
                'data' => $additionalData
            )
        );

        try {
            $response = wp_remote_post($this->gateway_log_url, array(
                'method' => 'POST',
                'redirection' => 0,
                'body' => $payload
            ));
        } catch (Exception $e) {
            // Do nothing
        }
    }
}
