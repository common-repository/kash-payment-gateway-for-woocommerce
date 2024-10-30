<?php

namespace Kash\Utils;

use DateTime;
use DateTimeZone;

class Signature {

    /**
     * Verifies the received signature is correct
     *
     * @param $payload
     * @param $secret
     * @return boolean
     */
    public static function verify($payload, $secret) {
        $signature = $payload['x_signature'];
        $expected_signature = self::compute($payload, $secret);
        return ($signature === $expected_signature);
    }

    /**
     * Gateway signing mechanism
     *
     * @param $payload
     * @param $secret
     * @return string
     */
    public static function compute(array $payload, $hmac_key) {
        $params = array();
        foreach ($payload as $key => $val) {
            // We only care about calculating signature on keys starting with 'x_' prefix
            if (substr_compare($key, 'x_', 0, 2) === 0) {
                $params[$key] = $val;
            }
        }

        ksort($params);

        $message = '';
        // Form the 'message' by concatenating all key and value pairs
        // without any spaces in between
        foreach ($params as $key => $val) {
            if (strcmp($key, 'x_signature') !== 0) {
                $message .= $key . $val;
            }
        }

        $signature = hash_hmac('sha256', $message, $hmac_key);

        return $signature;
    }
}
