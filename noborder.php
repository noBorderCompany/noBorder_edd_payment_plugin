<?php

/*
* Plugin Name: noBorder.tech payment gateway for Easy Digital Downloads
* description: <a href="https://noborder.tech">noBorder.tech</a> secure payment gateway for Easy Digital Downloads.
* Version: 1.1
* Author: noBorder.tech
* Author URI: https://noborder.tech
* Author Email: info@noborder.tech
* Text Domain: noborder_gf_payment_plugin
* Tested version up to: 6.1
* copyright (C) 2020 noborder
* license http://www.gnu.org/licenses/gpl-3.0.html GPLv3 or later
*/

if (!defined('ABSPATH')) exit;

class EDD_noBorder_Gateway {
	
	//
	public $keyname;
	
	//
	public function __construct() {
		
		$this->keyname = 'noborder';
		
		add_filter('edd_payment_gateways', array($this, 'add'));
		add_action($this->format('edd_{key}_cc_form'), array($this, 'cc_form'));
		add_action($this->format('edd_gateway_{key}'), array($this, 'process'));
		add_action($this->format('edd_verify_{key}'), array($this, 'verify'));
		add_filter('edd_settings_gateways', array($this, 'settings'));
		add_action('init', array($this, 'listen'));
	}
	
	//
	public function add($gateways) {
		global $edd_options;
		$gateways[$this->keyname] = array(
			'checkout_label' =>	isset($edd_options['noborder_label'])?$edd_options['noborder_label']:'Crypto payment with noBorder',
			'admin_label' => 'noBorder.tech'
		);
		return $gateways;
	}
	
	//
	public function cc_form() {
		return;
	}
	
	//
	public function process($purchase) {
		global $edd_options;
		@session_start();
		$payment = $this->insert_payment($purchase);
		
		if ($payment) {
			
			$api_key = (isset($edd_options[$this->keyname . '_api_key'])?$edd_options[$this->keyname . '_api_key']:'');
			$pay_currency = (isset($edd_options[$this->keyname . '_pay_currency'])?$edd_options[$this->keyname . '_pay_currency']:'');
			$desc = 'Payment #' . $payment . ' | ' . $purchase['user_info']['first_name'] . ' ' . $purchase['user_info']['last_name'];
			$callback = add_query_arg('verify_' . $this->keyname, '1', get_permalink($edd_options['success_page']));

			$amount = intval($purchase['price']);
			$currency = edd_get_currency();
			
			if (strtolower($currency) == 'rial') {
				$amount /= 10;
				$currency = 'irt';
			}
			
			$params = array(
				'api_key' => $api_key,
				'amount_value' => $amount,
				'amount_currency' => $currency,
				'pay_currency' => $pay_currency,
				'order_id' => $payment,
				'desc' => $desc,
				'respond_type' => 'link',
				'callback' => $callback,
			);
			
			$ch = curl_init('https://noborder.tech/action/ws/request_create');
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

			$response = curl_exec($ch);
			$err = curl_error($ch);
			
			if ($err) {
				edd_insert_payment_note($payment, 'Gateway encountered error: ' . $err);
				edd_update_payment_status($payment, 'failed');
				edd_set_error('noborder_connect_error', 'Gateway encountered error.');
				edd_send_back_to_checkout();
				return false;
			}

			$result = json_decode($response);
			curl_close($ch);

			if ($result->status == 'success') {
				edd_insert_payment_note($payment, 'noBorder request ID : ' . $result->request_id);
				edd_update_payment_meta($payment, 'noborder_request_id', $result->request_id);
				$_SESSION['noborder_payment'] = $payment;
				edd_set_payment_transaction_id($payment->ID, $request_id);
				wp_redirect($result->respond);
			
			} else {
				edd_insert_payment_note($payment, 'noBorder request ID: ' . $result->request_id);
				edd_insert_payment_note($payment, 'Gateway respond: ' . $result->respond);
				edd_update_payment_status($payment, 'failed');

				edd_set_error('noborder_connect_error', 'Gateway encountered error. Gateway respond: ' . $result->respond);
				edd_send_back_to_checkout();
			}
			
		} else {
			edd_send_back_to_checkout('?payment-mode=' . $purchase['post_data']['edd-gateway']);
		}
	}
	
	//
	public function verify() {
		
		global $edd_options;
		
		@session_start();
		$payment = edd_get_payment($_SESSION['noborder_payment']);
		unset($_SESSION['noborder_payment']);
		
		if (!$payment) wp_die('Server encountered error. Payment lost!');
		if ($payment->status == 'complete') return false;
		
		$request_id = edd_get_payment_meta($payment->ID, 'noborder_request_id');		
		$api_key = (isset($edd_options[$this->keyname . '_api_key']) ? $edd_options[$this->keyname . '_api_key'] : '');
		
		$params = array(
			'api_key' => $api_key,
			'order_id' => $order_id,
			'request_id' => $request_id,
		);
		
		$ch = curl_init('https://noborder.tech/action/ws/request_status');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		curl_close($ch);
		$result = json_decode($response);

		edd_empty_cart();

		if ($result->status == 'success') {
			edd_update_payment_status($payment->ID, 'publish');
			edd_send_to_success_page();
			
		} else {
			edd_update_payment_status($payment->ID, 'failed');
			wp_redirect(get_permalink($edd_options['failure_page']));
			exit;
		}
	}
	
	//
	public function settings($settings) {
		return array_merge($settings, array(
			$this->keyname . '_label' => array(
				'id' => $this->keyname . '_label',
				'name' => 'Display title',
				'type' => 'text',
				'size' => 'regular',
				'desc' => '<small> By default, the phrase "Crypto payment with noBorder" is displayed on the customer\'s payment page. If you want another phrase to be displayed, you can use this option. </small>',
			),
			$this->keyname . '_api_key' => array(
				'id' => $this->keyname . '_api_key',
				'name' => 'API Key',
				'type' => 'text',
				'size' => 'regular',
				'desc' => '<small> Enter the API key of noBorder in the above field to activate the payment gateway. You can get this key after registering your website in <a href="https://noborder.tech" target="_blank"> noBorder.tech </a></small>'
			),
			$this->keyname . '_pay_currency' => array(
				'id' => $this->keyname . '_pay_currency',
				'name' => 'Selectable currencies',
				'type' => 'text',
				'size' => 'regular',
				'desc' => '<small> By default, customers can pay through all <a href="https://noborder.tech/sub/process/cryptolist" target="_blank"> active crypto currencies in noBorder.tech </a>, but if you want to limit customers to pay through one or more specific crypto currencies, you can declare the name of the currency or currencies through this field. If you want to declare more than one currency, separate them with a dash. Example: bitcoin-dogecoin </small>',
			)
		));
	}
	
	//
	private function format($string) {
		return str_replace('{key}', $this->keyname, $string);
	}
	
	//
	private function insert_payment($purchase) {
		global $edd_options;
		$payment_data = array(
			'price' => $purchase['price'],
			'date' => $purchase['date'],
			'user_email' => $purchase['user_email'],
			'purchase_key' => $purchase['purchase_key'],
			'currency' => $edd_options['currency'],
			'downloads' => $purchase['downloads'],
			'user_info' => $purchase['user_info'],
			'cart_details' => $purchase['cart_details'],
			'status' => 'pending'
		);
		
		$payment = edd_insert_payment($payment_data);
		return $payment;
	}
	
	//
	public function listen() {
		if (isset($_GET['verify_' . $this->keyname]) && $_GET['verify_' . $this->keyname]) {
			do_action('edd_verify_' . $this->keyname);
		}
	}
}

new EDD_noBorder_Gateway;
