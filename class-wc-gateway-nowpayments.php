<?php
if (!defined('ABSPATH')) {
    exit;
}
// Exit if accessed directly

/**
 * Plugin Name: WooCommerce nowpayments.io Gateway
 * Plugin URI: https://www.nowpayments.io/
 * Description:  Provides a nowpayments.io Payment Gateway.
 * Author: nowpayments.io
 * Author URI: https://www.nowpayments.io/
 * Version: 1.3.1
 */

/**
 * nowpayments.io Gateway
 * Based on the PayPal Standard Payment Gateway
 *
 * Provides a nowpayments.io Payment Gateway.
 *
 * @class         WC_nowpayments
 * @extends        WC_Gateway_nowpayments
 * @version        1.3.1
 * @package        WooCommerce/Classes/Payment
 * @author         nowpayments.io based on PayPal module by WooThemes
 */
 
if (version_compare(phpversion(), '7.1', '>=')) {
	ini_set('precision', 10);
	ini_set('serialize_precision', 10);
}

add_action('plugins_loaded', 'nowpayments_gateway_load', 0);
function nowpayments_gateway_load()
{

    if (!class_exists('WC_Payment_Gateway')) {
        // oops!
        return;
    }

    /**
     * Add the gateway to WooCommerce.
     */
    add_filter('woocommerce_payment_gateways', 'wcnowpayments_add_gateway');

    function wcnowpayments_add_gateway($methods)
    {
        if (!in_array('WC_Gateway_nowpayments', $methods)) {
            $methods[] = 'WC_Gateway_nowpayments';
        }
        return $methods;
    }

    class WC_Gateway_nowpayments extends WC_Payment_Gateway
    {
        var $ipn_url;

        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct()
        {
            global $woocommerce;

            $this->id = 'nowpayments';
            $this->icon = apply_filters('woocommerce_nowpayments_icon', plugins_url() . '/nowpayments-payment-gateway-for-woocommerce/assets/images/icons/nowpayments.png');
            $this->has_fields = false;
            $this->method_title = __('nowpayments.io', 'woocommerce');
            $this->ipn_url = add_query_arg('wc-api', 'WC_Gateway_nowpayments', home_url('/'));

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->ipn_secret = $this->get_option('ipn_secret');
            $this->api_key = $this->get_option('api_key');
            $this->debug_email = $this->get_option('debug_email');
            $this->allow_zero_confirm = $this->get_option('allow_zero_confirm') == 'yes' ? true : false;
            $this->form_submission_method = $this->get_option('form_submission_method') == 'yes' ? true : false;
            $this->invoice_prefix = $this->get_option('invoice_prefix', 'WC-');
            $this->simple_total = $this->get_option('simple_total') == 'yes' ? true : false;

            // Logs
            $this->log = new WC_Logger();

            // Actions
            add_action('woocommerce_receipt_nowpayments', array($this, 'receipt_page'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            add_action('woocommerce_api_wc_gateway_nowpayments', array($this, 'check_ipn_response'));

            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }

        }

        /**
         * Check if this gateway is enabled and available in the user's country
         *
         * @access public
         * @return bool
         */
        function is_valid_for_use()
        {
            //if ( ! in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_nowpayments_supported_currencies', array( 'AUD', 'CAD', 'USD', 'EUR', 'JPY', 'GBP', 'CZK', 'BTC', 'LTC' ) ) ) ) return false;
            // ^- instead of trying to maintain this list just let it always work
            return true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 1.0.0
         */
        public function admin_options()
        {

            ?>
		<h3><?php _e('nowpayments.io', 'woocommerce');?></h3>
		<p><?php _e('Completes checkout via nowpayments.io', 'woocommerce');?></p>

    	<?php if ($this->is_valid_for_use()): ?>

			<table class="form-table">
			<?php
// Generate the HTML For the settings form.
            $this->generate_settings_html();
            ?>
			</table><!--/.form-table-->

		<?php else: ?>
            <div class="inline error"><p><strong><?php _e('Gateway Disabled', 'woocommerce');?></strong>: <?php _e('nowpayments.io does not support your store currency.', 'woocommerce');?></p></div>
		<?php
endif;
        }

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields()
        {

            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable nowpayments.io', 'woocommerce'),
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('NOWPayments', 'woocommerce'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Expand your payment options with NOWPayments! BTC, ETH, LTC and many more: pay with anything you like!', 'woocommerce'),
                ),
                'ipn_secret' => array(
                    'title' => __('IPN Secret', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Please enter your Nowpayments.io IPN Secret.', 'woocommerce'),
                    'default' => '',
                ),
                'api_key' => array(
                    'title' => __('Api Key', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Please enter your nowpayments.io Api Key.', 'woocommerce'),
                    'default' => '',
                ),
                'simple_total' => array(
                    'title' => __('Compatibility Mode', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __("This may be needed for compatibility with certain addons if the order total isn't correct.", 'woocommerce'),
                    'default' => '',
                ),
                'invoice_prefix' => array(
                    'title' => __('Invoice Prefix', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Please enter a prefix for your invoice numbers. If you use your nowpayments.io account for multiple stores ensure this prefix is unique.', 'woocommerce'),
                    'default' => 'WC-',
                    'desc_tip' => true,
                ),
                'debug_email' => array(
                    'title' => __( 'Debug Email', 'woocommerce' ),
                    'type' => 'email',
                    'default' => '',
                    'description' => __( 'Send copies of invalid IPNs to this email address.', 'woocommerce' ),
                )
            );

        }

        /**
         * Get nowpayments.io Args
         *
         * @access public
         * @param mixed $order
         * @return array
         */
        function get_nowpayments_args($order)
        {
            global $woocommerce;

            $order_id = $order->id;

            if (in_array($order->billing_country, array('US', 'CA'))) {
                $order->billing_phone = str_replace(array('( ', '-', ' ', ' )', '.'), '', $order->billing_phone);
            }

            // nowpayments.io Args
            $nowpayments_args = array(
                // Get the currency from the order, not the active currency
                // NOTE: for backward compatibility with WC 2.6 and earlier,
                // $order->get_order_currency() should be used instead
                'dataSource' => "woocommerce",
                'ipnURL' => $this->ipn_url,
                'paymentCurrency' => $order->get_currency(),
                'successURL' => $this->get_return_url($order),
                'cancelURL' => esc_url_raw($order->get_cancel_order_url_raw()),

                // Order key + ID
                'orderID' => $this->invoice_prefix . $order->get_order_number(),
                'apiKey' => $this->api_key,

                // Billing Address info
                'customerName' => $order->billing_first_name,
                'customerEmail' => $order->billing_email,
            );

            if ($this->simple_total) {
                $nowpayments_args['paymentAmount'] = number_format($order->get_total(), 8, '.', '');
                $nowpayments_args['tax'] = 0.00;
                $nowpayments_args['shipping'] = 0.00;
            } else if (wc_tax_enabled() && wc_prices_include_tax()) {
                $nowpayments_args['paymentAmount'] = number_format($order->get_total(), 8, '.', '');
                $nowpayments_args['shipping'] = number_format($order->get_total_shipping() + $order->get_shipping_tax(), 8, '.', '');
                $nowpayments_args['tax'] = 0.00;
            } else {
                $nowpayments_args['paymentAmount'] = number_format($order->get_total(), 8, '.', '');
                $nowpayments_args['shipping'] = number_format($order->get_total_shipping(), 8, '.', '');
                $nowpayments_args['tax'] = $order->get_total_tax();
            }
            $order_cur = wc_get_order($order_id);
            $items_cur = $order_cur->get_items();
            $items = [];
            foreach ($items_cur as $item_id => $item) {
                $items[] = $item->get_data();
            }
            $nowpayments_args["products"] = $items;
            $nowpayments_args = apply_filters('woocommerce_nowpayments_args', $nowpayments_args);

            return $nowpayments_args;
        }

        /**
         * Generate the nowpayments button link
         *
         * @access public
         * @param mixed $order_id
         * @return string
         */
        function generate_nowpayments_url($order)
        {
            global $woocommerce;

            if ($order->status != 'completed' && get_post_meta($order->id, 'nowpayments payment complete', true) != 'Yes') {
                //$order->update_status('on-hold', 'Customer is being redirected to nowpayments...');
                $order->update_status('pending', 'Customer is being redirected to nowpayments...');
            }

            $nowpayments_adr = "https://nowpayments.io/payment?data=";
            $nowpayments_args = $this->get_nowpayments_args($order);
            $nowpayments_adr .= urlencode(json_encode($nowpayments_args));
            return $nowpayments_adr;
        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            return array(
                'result' => 'success',
                'redirect' => $this->generate_nowpayments_url($order));

        }

        /**
         * Output for the order received page.
         *
         * @access public
         * @return void
         */
        function receipt_page($order)
        {
            echo '<p>' . __('Thank you for your order, please click the button below to pay with nowpayments.io.', 'woocommerce') . '</p>';

            echo $this->generate_nowpayments_form($order);
        }

        /**
         * Check Nowpayments.io IPN validity
         **/
        function check_ipn_request_is_valid()
        {
            global $woocommerce;

            $order = false;
            $error_msg = "Unknown error";
            $auth_ok = false;
            $request_data = null;
            

            if (isset($_SERVER['HTTP_X_NOWPAYMENTS_SIG']) && !empty($_SERVER['HTTP_X_NOWPAYMENTS_SIG'])) {
                $recived_hmac = $_SERVER['HTTP_X_NOWPAYMENTS_SIG'];

                $request_json = file_get_contents('php://input');
                $request_data = json_decode($request_json, true);
                ksort($request_data);
                $sorted_request_json = json_encode($request_data);


                if ($request_json !== false && !empty($request_json)) {
                    $hmac = hash_hmac("sha512", $sorted_request_json, trim($this->ipn_secret));

                    if ($hmac == $recived_hmac) {
                        $auth_ok = true;
                    } else {
                        $error_msg = 'HMAC signature does not match';
                    }
                } else {
                    $error_msg = 'Error reading POST data';
                }
            } else {
                $error_msg = 'No HMAC signature sent.';
            }

            if ($auth_ok) {
                $valid_order_id = str_replace("WC-", "", $request_data["order_id"]);
                $order = new WC_Order($valid_order_id);

                if ($order !== false) {                   
                    // Get the currency from the order, not the active currency
					// NOTE: for backward compsatibility with WC 2.6 and earlier,
					$payment_currency = strtoupper($request_data["pay_currency"]);
                    if ($payment_currency == ($order->get_currency() || $payment_currency)) {
                        if ($request_data["price_amount"] >= $order->get_total()) {
                            print "IPN check OK\n";
                            return true;
                        } else {
                            $error_msg = "Amount received is less than the total!";
                        }
                    } else {
                        $error_msg = "Original currency doesn't match!";
                    }
                } else {
                    $error_msg = "Could not find order info for order ";
                }
            }

            $report = "Error Message: ".$error_msg."\n\n";

            if ($order) {
                $order->update_status('on-hold', sprintf( __( 'NOWPayments.io IPN Error: %s', 'woocommerce' ), $error_msg ) );
            }

            if (!empty($this->debug_email)) { mail($this->debug_email, "Report", $report); };
            die('Error: '.$error_msg);
            return false;
        }

        /**
         * Successful Payment!
         *
         * @access public
         * @param array $posted
         * @return void
         */
        function successful_request()
        {
            global $woocommerce;
            $request_json = file_get_contents('php://input');
            $request_data = json_decode($request_json, true);
            $valid_order_id = str_replace("WC-", "", $request_data["order_id"]);
            $order = new WC_Order($valid_order_id);


            if ($request_data["payment_status"] == "finished") {
                $order->update_status('processing', 'Order has been paid.');
                $order->payment_complete();
            } else if ($request_data["payment_status"] == "partially_paid") {
                $order->update_status('on-hold', 'Order is holded.');
                $order->add_order_note('Your payment is partially paid. Please contact support@nowpayments.io Amount received: ' . $request_data["actually_paid"]);
            } else if ($request_data["payment_status"] == "confirming") {
                $order->update_status('processing', 'Order is processing.');
            } else if ($request_data["payment_status"] == "confirmed") {
                $order->update_status('processing', 'Order is processing.');
            } else if ($request_data["payment_status"] == "sending") {
                $order->update_status('processing', 'Order is processing.');
            } else if ($request_data["payment_status"] == "failed") {
                $order->update_status('on-hold', 'Order is failed. Please contact support@nowpayments.io');
            }

            $order->add_order_note('nowpayments.io Payment Status: ' . $request_data["payment_status"]);
        }

        /**
         * Check for NOWPayments IPN Response
         *
         * @access public
         * @return void
         */
        function check_ipn_response()
        {
            @ob_clean();
            if ($this->check_ipn_request_is_valid()) {
                $this->successful_request($_POST);
            } else {
                wp_die("NOWPayments.io IPN Request Failure");
            }
        }

    }

    class WC_nowpayments extends WC_Gateway_nowpayments
    {
        public function __construct()
        {
            _deprecated_function('WC_nowpayments', '1.4', 'WC_Gateway_nowpayments');
            parent::__construct();
        }
    }
}
