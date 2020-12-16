<?php

	defined('_JEXEC') or die('Restricted access');

	if (!class_exists('vmPSPlugin')) {
		require JPATH_VM_PLUGINS . DS . 'vmpsplugin.php';
	}

	class plgVmPaymentMelli extends vmPSPlugin {
		public function __construct(&$subject, $config) {
			parent::__construct($subject, $config);
			$this->_loggable = true;
			$this->tableFields = array_keys($this->getTableSQLFields());
			$this->_tablepkey = 'id';
			$this->_tableId = 'id';
			$varsToPush = $this->getVarsToPush();
			$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
		}

		/**
		 * Create the table for this plugin if it does not yet exist.
		 */
		public function getVmPluginCreateTableSQL() {
			return $this->createTableSQL('Payment melli Table');
		}

		/**
		 * Fields to create the payment table.
		 *
		 * @return string SQL Fileds
		 */
		public function getTableSQLFields() {
			$SQLfields = [
					'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
					'virtuemart_order_id' => 'int(1) UNSIGNED',
					'order_number' => 'char(64)',
					'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
					'payment_name' => 'varchar(5000)',
					'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
					'payment_currency' => 'char(3)',
					'email_currency' => 'char(3)',
					'cost_per_transaction' => 'decimal(10,2)',
					'cost_percent_total' => 'decimal(10,2)',
					'tax_id' => 'smallint(1)',
			];

			return $SQLfields;
		}

		public function plgVmConfirmedOrder($cart, $order) {

			if (!($this->_currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
				return; // Another method was selected, do nothing
			}
			if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
				return false;
			}

			if (!class_exists('VirtueMartModelOrders')) {
				require VMPATH_ADMIN . DS . 'models' . DS . 'orders.php';
			}
			if (!class_exists('VirtueMartModelCurrency')) {
				require VMPATH_ADMIN . DS . 'models' . DS . 'currency.php';
			}

			$params = $this->_currentMethod;

			$new_status = '';

			$usrBT = $order['details']['BT'];
			$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

			$this->getPaymentCurrency($method);

			$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
			$db = &JFactory::getDBO();
			$db->setQuery($q);
			$currency_code_3 = $db->loadResult();

			$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
			$totalInPaymentCurrency = round($paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false), 2);
			$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);

			$dbValues['order_number'] = $order['details']['BT']->order_number;
			$dbValues['payment_name'] = $this->renderPluginName($method, $order);
			$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
			$dbValues['vnpassargad_custom'] = $return_context;
			$dbValues['cost_per_transaction'] = $method->cost_per_transaction;
			$dbValues['cost_percent_total'] = $method->cost_percent_total;
			$dbValues['payment_currency'] = $method->payment_currency;
			$dbValues['payment_order_total'] = $totalInPaymentCurrency;
			$dbValues['tax_id'] = $method->tax_id;
			$this->storePSPluginInternalData($dbValues);
			$mmm = $method->mmm;
			$transid = $order['details']['BT']->order_number;

			$onorder = rand(11111111111111, 999999999999);
			$amount = round($totalInPaymentCurrency); // مبلغ فاكتور
			$CallbackURL = '' . JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&onorder=' . $onorder . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id;



			//----------------saeed { --------------
			$amount = (int)$amount;

			$redirect = $CallbackURL;

			$terminal_id = trim($params->terminal_id);
			$merchant_id = trim($params->merchant_id);
			$terminal_key = trim($params->terminal_key);
			$order_id = rand(1000000000, 9999999999);
			$sign_data = $this->sadad_encrypt($terminal_id . ';' . $order_id . ';' . $amount, $terminal_key);

			$parameters = array(
					'MerchantID' => $merchant_id,
					'TerminalId' => $terminal_id,
					'Amount' => $amount,
					'OrderId' => $order_id,
					'LocalDateTime' => date('Ymdhis'),
					'ReturnUrl' => $redirect,
					'SignData' => $sign_data,
			);

			$error_flag = false;
			$error_msg = '';

			$result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Request/PaymentRequest', $parameters);

			if ($result != false) {
				if ($result->ResCode == 0) {
					header('Location: https://sadad.shaparak.ir/VPG/Purchase?Token=' . $result->Token);
					return;
				} else {
					//bank returned an error
					$error_flag = true;
					$error_msg = "خطا در انتقال به بانک! " . htmlentities($result->Description);
				}
			} else {
				// couldn't connect to bank
				$error_flag = true;
				$error_msg = 'خطا! برقراری ارتباط با بانک امکان پذیر نیست.';
			}

			if ($error_flag) {
				echo $error_msg;
			}

			return false;

			//----------------} saeed --------------


		}

		public function plgVmOnPaymentResponseReceived(&$html) {

			// the payment itself should send the parameter needed.
			$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
			$order_number = JRequest::getVar('on', 0);

			$vendorId = 0;
			if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
				return; // Another method was selected, do nothing
			}
			if (!$this->selectedThisElement($method->payment_element)) {
				return false;
			}
			if (!class_exists('VirtueMartCart')) {
				require JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php';
			}
			if (!class_exists('shopFunctionsF')) {
				require JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php';
			}
			if (!class_exists('VirtueMartModelOrders')) {
				require JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php';
			}
			$Vmpassargad_data = JRequest::getVar('on');
			$payment_name = $this->renderPluginName($method);
			$virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number);
			if ($virtuemart_order_id) {
				if (!class_exists('VirtueMartCart')) {
					$params = $this->_currentMethod;
				}
				$ons = $_GET['on'];

				if ($virtuemart_order_id && isset($_POST['token']) && isset($_POST['OrderId']) && isset($_POST['ResCode'])) {
					$token = $_POST['token'];

					//verify payment
					$parameters = array(
							'Token' => $token,
							'SignData' => $this->sadad_encrypt($token, trim($method->terminal_key))
					);

					$error_flag = false;
					$error_msg = '';

					$result = $this->sadad_call_api('https://sadad.shaparak.ir/VPG/api/v0/Advice/Verify', $parameters);
					if ($result != false) {
						if ($result->ResCode == 0) {
							$this->update_order_status($ons, $result->SystemTraceNo, $result->RetrivalRefNo);

							$cart = VirtueMartCart::getCart();
							$cart->emptyCart();

							$message = <<<tmpl
								<h3 class="success">پرداخت شما با موفقیت انجام شد.</h3>
								<table class="table">
								<tr>
									<td>مبلغ تراکنش</td>
									<td>{$result->Amount} ریال</td>
								</tr>
								<tr>
									<td>شماره مرجع</td>
									<td>{$result->RetrivalRefNo}</td>
								</tr>
								<tr>
									<td>شماره پیگیری</td>
									<td>{$result->SystemTraceNo}</td>
								</tr>
								</table>
tmpl;
							echo $message;
							return;
						} else {
							//couldn't verify the payment due to a back error
							$error_flag = true;
							$error_msg = 'خطا هنگام پرداخت! ' . htmlentities($result->Description);
						}
					} else {
						//couldn't verify the payment due to a connection failure to bank
						$error_flag = true;
						$error_msg = 'خطا! عدم امکان دریافت تاییدیه پرداخت از بانک';
					}

					$message = "پرداخت شما ناموفق بود" . PHP_EOL;
					$message .= 'خطا: ';
					$message .= $error_msg . PHP_EOL;
					$message .= "لطفا درصورتی که این مشکل دوباره تکرار شد به تیم پشتیبانی اطلاع دهید" . PHP_EOL;
					echo $message;

				}

			}
		}

		/*
		 * Keep backwards compatibility
		 * a new parameter has been added in the xml file
		 */
		public function getNewStatus($method) {
			if (isset($method->status_pending) and $method->status_pending != '') {
				return $method->status_pending;
			} else {
				return 'P';
			}
		}

		/**
		 * Display stored payment data for an order.
		 */
		public function plgVmOnShowOrderBEPayment($virtuemart_order_id, $virtuemart_payment_id) {
			if (!$this->selectedThisByMethodId($virtuemart_payment_id)) {
				return; // Another method was selected, do nothing
			}

			if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
				return;
			}
			VmConfig::loadJLang('com_virtuemart');

			$html = '<table class="adminlist table">' . "\n";
			$html .= $this->getHtmlHeaderBE();
			$html .= $this->getHtmlRowBE('COM_VIRTUEMART_PAYMENT_NAME', $paymentTable->payment_name);
			$html .= $this->getHtmlRowBE('melli_PAYMENT_TOTAL_CURRENCY', $paymentTable->payment_order_total . ' ' . $paymentTable->payment_currency);
			if ($paymentTable->email_currency) {
				$html .= $this->getHtmlRowBE('melli_EMAIL_CURRENCY', $paymentTable->email_currency);
			}
			$html .= '</table>' . "\n";

			return $html;
		}


		/**
		 * Check if the payment conditions are fulfilled for this payment method.
		 *
		 * @param $cart
		 * @param $method
		 * @param $cart_prices
		 * @return true: if the conditions are fulfilled, false otherwise
		 */
		protected function checkConditions($cart, $method, $cart_prices) {
			$this->convert_condition_amount($method);
			$amount = $this->getCartAmount($cart_prices);
			$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

			//vmdebug('melli checkConditions',  $amount, $cart_prices['salesPrice'],  $cart_prices['salesPriceCoupon']);
			$amount_cond = ($amount >= $method->min_amount and $amount <= $method->max_amount
					or
					($method->min_amount <= $amount and ($method->max_amount == 0)));
			if (!$amount_cond) {
				return false;
			}
			$countries = [];
			if (!empty($method->countries)) {
				if (!is_array($method->countries)) {
					$countries[0] = $method->countries;
				} else {
					$countries = $method->countries;
				}
			}

			// probably did not gave his BT:ST address
			if (!is_array($address)) {
				$address = [];
				$address['virtuemart_country_id'] = 0;
			}

			if (!isset($address['virtuemart_country_id'])) {
				$address['virtuemart_country_id'] = 0;
			}
			if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries)) {
				return true;
			}

			return false;
		}

		/**
		 * Create the table for this plugin if it does not yet exist.
		 * This functions checks if the called plugin is active one.
		 * When yes it is calling the melli method to create the tables.
		 *
		 */
		public function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
			return $this->onStoreInstallPluginTable($jplugin_id);
		}

		/**
		 * This event is fired after the payment method has been selected. It can be used to store
		 * additional payment info in the cart.
		 *
		 * @param VirtueMartCart $cart : the actual cart
		 *
		 * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
		 */
		public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg) {
			return $this->OnSelectCheck($cart);
		}

		/**
		 * plgVmDisplayListFEPayment
		 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel.
		 *
		 * @param object $cart Cart object
		 * @param int $selected ID of the method selected
		 *
		 * @return bool True on succes, false on failures, null when this plugin was not selected.
		 *              On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
		 *
		 */
		public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected, &$htmlIn) {
			return $this->displayListFE($cart, $selected, $htmlIn);
		}

		/*
		* plgVmonSelectedCalculatePricePayment
		* Calculate the price (value, tax_id) of the selected method
		* It is called by the calculator
		* This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
		* @cart: VirtueMartCart the current cart
		* @cart_prices: array the new cart prices
		* @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
		*
		*
		*/

		public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
			return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
		}

		public function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
			if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
				return; // Another method was selected, do nothing
			}
			if (!$this->selectedThisElement($method->payment_element)) {
				return false;
			}
			$this->getPaymentCurrency($method);

			$paymentCurrencyId = $method->payment_currency;
		}

		/**
		 * plgVmOnCheckAutomaticSelectedPayment
		 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
		 * The plugin must check first if it is the correct type.
		 *
		 *
		 * @param VirtueMartCart cart: the cart object
		 *
		 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
		 */
		public function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices, &$paymentCounter) {
			return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
		}

		/**
		 * This method is fired when showing the order details in the frontend.
		 * It displays the method-specific data.
		 *
		 * @param int $order_id The order ID
		 *
		 * @return mixed Null for methods that aren't active, text (HTML) otherwise
		 *
		 */
		public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
			$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
		}

		/**
		 * @param $orderDetails
		 * @param $data
		 *
		 * @return null
		 */
		public function plgVmOnUserInvoice($orderDetails, &$data) {
			if (!($method = $this->getVmPluginMethod($orderDetails['virtuemart_paymentmethod_id']))) {
				return; // Another method was selected, do nothing
			}
			if (!$this->selectedThisElement($method->payment_element)) {
				return;
			}
			//vmdebug('plgVmOnUserInvoice',$orderDetails, $method);

			if (!isset($method->send_invoice_on_order_null) or $method->send_invoice_on_order_null == 1 or $orderDetails['order_total'] > 0.00) {
				return;
			}

			if ($orderDetails['order_salesPrice'] == 0.00) {
				$data['invoice_number'] = 'reservedByPayment_' . $orderDetails['order_number']; // Nerver send the invoice via email
			}
		}

		/**
		 * @param $virtuemart_paymentmethod_id
		 * @param $paymentCurrencyId
		 *
		 * @return bool|null
		 */
		public function plgVmgetEmailCurrency($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId) {
			if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
				return; // Another method was selected, do nothing
			}
			if (!$this->selectedThisElement($method->payment_element)) {
				return false;
			}
			if (!($payments = $this->getDatasByOrderId($virtuemart_order_id))) {
				// JError::raiseWarning(500, $db->getErrorMsg());
				return '';
			}
			if (empty($payments[0]->email_currency)) {
				$vendorId = 1; //VirtueMartModelVendor::getLoggedVendor();
				$db = JFactory::getDBO();
				$q = 'SELECT   `vendor_currency` FROM `#__virtuemart_vendors` WHERE `virtuemart_vendor_id`=' . $vendorId;
				$db->setQuery($q);
				$emailCurrencyId = $db->loadResult();
			} else {
				$emailCurrencyId = $payments[0]->email_currency;
			}
		}

		/**
		 * This event is fired during the checkout process. It can be used to validate the
		 * method data as entered by the user.
		 *
		 * @return bool True when the data was valid, false otherwise. If the plugin is not activated, it should return null.
		 *
		 */

		/**
		 * This method is fired when showing when priting an Order
		 * It displays the the payment method-specific data.
		 *
		 * @param int $_virtuemart_order_id The order ID
		 * @param int $method_id method used for this order
		 *
		 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
		 *
		 */
		public function plgVmonShowOrderPrintPayment($order_number, $method_id) {
			return $this->onShowOrderPrint($order_number, $method_id);
		}

		public function plgVmDeclarePluginParamsPaymentVM3(&$data) {
			return $this->declarePluginParams('payment', $data);
		}

		public function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
			return $this->setOnTablePluginParams($name, $id, $table);
		}



		private function update_order_status($ons) {
			$conn = JFactory::getDBO();
			$data = new stdClass();
			$data->order_status = 'C';
			$data->order_number = $ons;
			if ($conn->updateObject('#__virtuemart_orders', $data, 'order_number')) {
				unset($conn);
			} else {
				//echo $conn->stderr();
			}
		}
		private function get_user_info($ons) {
			$conn = &JFactory::getDBO();
			$sql = "select * from `#__virtuemart_orders` where `order_number` = '{$ons}' AND `order_status` ='C'";
			$conn->setQuery($sql);
			$conn->query();
			$order_row = $conn->loadobject();
			$vmuid = $order_row->virtuemart_user_id;

			$sql = "select * from `#__users` where `id` = '{$vmuid}'";
			$conn->setQuery($sql);
			$conn->query();

			return $conn->loadobject();
		}
		
		
		//Create sign data(Tripledes(ECB,PKCS7)) using mcrypt
		private function mcrypt_encrypt_pkcs7($str, $key) {
			$block = mcrypt_get_block_size("tripledes", "ecb");
			$pad = $block - (strlen($str) % $block);
			$str .= str_repeat(chr($pad), $pad);
			$ciphertext = mcrypt_encrypt("tripledes", $key, $str,"ecb");
			return base64_encode($ciphertext);
		}

		//Create sign data(Tripledes(ECB,PKCS7)) using openssl
		private function openssl_encrypt_pkcs7($key, $data) {
			$ivlen = openssl_cipher_iv_length('des-ede3');
			$iv = openssl_random_pseudo_bytes($ivlen);
			$encData = openssl_encrypt($data, 'des-ede3', $key, 0, $iv);
			return $encData;
		}


		private function sadad_encrypt($data, $key) {
			$key = base64_decode($key);
			if( function_exists('openssl_encrypt') ) {
				return $this->openssl_encrypt_pkcs7($key, $data);
			} elseif( function_exists('mcrypt_encrypt') ) {
				return $this->mcrypt_encrypt_pkcs7($data, $key);
			} else {
				return '';
			}

		}

		private function sadad_call_api($url, $data = false) {
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json; charset=utf-8'));
			curl_setopt($ch, CURLOPT_POST, 1);
			if ($data) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
			}
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
			$result = curl_exec($ch);
			curl_close($ch);
			return !empty($result) ? json_decode($result) : false;
		}

		private function sadad_request_err_msg($err_code) {
			return JText::_("VIRTUEMART_MELLI_PAYMENT_REQ_" . $err_code);
		}

		private function sadad_verify_err_msg($res_code) {
			return JText::_("VIRTUEMART_MELLI_PAYMENT_VER_" . $res_code);
		}

	}
