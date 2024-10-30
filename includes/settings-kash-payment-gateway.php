<?php


/**
 * Settings for Kash Payment Gateway
 **/
return array(
    'enabled' => array(
        'title'       => __('Enable', 'woocommerce'),
        'type'        => 'checkbox',
        'label'       => __('Enable Kash', 'woocommerce'),
        'default'     => 'yes'
    ),
    'title' => array(
        'title'       => __('Title', 'woocommerce'),
        'type'        => 'text',
        'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
        'default'     => __('Credit/Direct Debit 1-5% Instant Savings', 'woocommerce'),
        'desc_tip'    => true
    ),
    'description' => array(
        'title'       => __('Description', 'woocommerce'),
        'type'        => 'text',
        'desc_tip'    => true,
        'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
        'default'     => __('Secure payment that saves you money and helps us avoid credit card fees and fraud.', 'woocommerce')
    ),
    'explanation' => array(
        'title'       => __('Enable Direct Debit Explanation', 'woocommerce'),
        'type'        => 'checkbox',
        'desc_tip'    => true,
        'description' => __('Shows an in-page popup explanation of what direct debit is to help users understand their payment options.', 'woocommerce'),
        'default'     => 'yes'
    ),
    'account_id' => array(
        'title'       => __('Kash Account ID', 'woocommerce'),
        'type'        => 'text',
        'description' => __('This is the account ID you get from your Kash account', 'woocommerce'),
        'default'     => '',
        'desc_tip'    => true
    ),
    'server_key' => array(
        'title'       => __('Kash Server Key', 'woocommerce'),
        'type'        => 'text',
        'description' => __('This is the server key you get from your Kash account', 'woocommerce'),
        'default'     => '',
        'desc_tip'    => true
    ),
    'gateway_url' => array(
        'title'       => __('Gateway URL', 'woocommerce'),
        'type'        => 'text',
        'default'     => 'https://gateway.withkash.com/'
    ),
    'test_mode' => array(
        'title'       => __('Test Mode', 'woocommerce'),
        'type'        => 'checkbox',
        'label'       => __('Enable Test Mode.', 'woocommerce'),
        'default'     => 'no',
        'description' => __('Please use with caution. Enabling Test Mode will allow you to use any bank login to complete a simulated Direct Debit authorization. '.
                            'This will not capture or record the transaction to your Kash account. ' .
                            'Orders will still be created normally on WooCommerce and will need to be canceled after doing a test.', 'woocommerce'),
    ),
    'debug' => array(
        'title'       => __('Debug Log', 'woocommerce'),
        'type'        => 'checkbox',
        'label'       => __('Enable logging', 'woocommerce'),
        'default'     => 'no',
        'description' => sprintf(__('Log events in <code>%s</code>', 'woocommerce'), wc_get_log_file_path('kash'))
    )
);
