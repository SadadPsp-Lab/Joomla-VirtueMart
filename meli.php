<?php
defined('_JEXEC') or die('Restricted access');

if (!class_exists('vmPSPlugin')) {
    require(JPATH_VM_PLUGINS . '/vmpsplugin.php');
}

class plgVmPaymentMeli extends vmPSPlugin
{

    function __construct(&$subject, $config)
    {

        parent::__construct($subject, $config);
        $this->_loggable = TRUE;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $varsToPush = array('merchant' => array('', 'varchar'), 'terminal' => array('', 'varchar'), 'key' => array('', 'varchar'), 'currency' => array('', 'varchar'));
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment meli Table');
    }

    function getTableSQLFields()
    {

        $SQLfields = array(
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'order_pass' => 'varchar(50)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'crypt_virtuemart_pid' => 'varchar(255)',
            'salt' => 'varchar(255)',
            'payment_name' => 'varchar(5000)',
            'amount' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'email_currency' => 'char(3)',
            'mobile' => 'varchar(12)',
            'tracking_code' => 'varchar(50)'
        );

        return $SQLfields;
    }


    function plgVmConfirmedOrder($cart, $order)
    {
        if (!class_exists('nusoap_client')) {
            require_once("helper/nusoap.php");
        }

        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return null;
        }

        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return NULL;
        }
        $app = JFactory::getApplication();
        $session = JFactory::getSession();
        $salt = JUserHelper::genRandomPassword(32);
        $crypt_virtuemartPID = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id, $salt);
        if ($session->isActive('uniq')) {
            $session->clear('uniq');
        }
        $session->set('uniq', $crypt_virtuemartPID);

        $payment_currency = $this->getPaymentCurrency($method, $order['details']['BT']->payment_currency_id);
        $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $payment_currency);
        $currency_code_3 = shopFunctions::getCurrencyByID($payment_currency, 'currency_code_3');
        $email_currency = $this->getEmailCurrency($method);
        $dbValues['payment_name'] = $this->renderPluginName($method) . '<br />';
        $dbValues['order_number'] = $order['details']['BT']->order_number;
        $dbValues['order_pass'] = $order['details']['BT']->order_pass;
        $dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
        $dbValues['crypt_virtuemart_pid'] = $crypt_virtuemartPID;
        $dbValues['salt'] = $salt;
        $dbValues['payment_currency'] = $order['details']['BT']->order_currency;
        $dbValues['email_currency'] = $email_currency;
        $dbValues['amount'] = $totalInPaymentCurrency['value'];
        $dbValues['mobile'] = $order['details']['BT']->phone_2;
        $this->storePSPluginInternalData($dbValues);
        $id = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id);
        $app = JFactory::getApplication();

        $key = $method->key;
        $MerchantId = $method->merchant;
        $TerminalId = $method->terminal;
        $currency = $method->currency;

        $Amount = $totalInPaymentCurrency['value'];
        if ($currency == "toman") {
            $Amount = $totalInPaymentCurrency['value'] * 10; // Toman
        }

        $OrderId = rand(11111111, 99999999);
        $ReturnUrl = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . "&onorder=" . $OrderId . "&pm=" . $order['details']['BT']->virtuemart_paymentmethod_id;

        try {
            $SignData = $this->encrypt_pkcs7("$TerminalId;$OrderId;$Amount", "$key");
            $data = array(
                'TerminalId' => $TerminalId,
                'MerchantId' => $MerchantId,
                'Amount' => $Amount,
                'SignData' => $SignData,
                'ReturnUrl' => $ReturnUrl,
                'LocalDateTime' => date("m/d/Y g:i:s a"),
                'OrderId' => $OrderId
            );
            $str_data = json_encode($data);
            $res = $this->CallAPI('https://sadad.shaparak.ir/vpg/api/v0/Request/PaymentRequest', $str_data);
            $arrres = json_decode($res);
            if ($arrres->ResCode == '0') {
                $Token = $arrres->Token;
                $url = "https://sadad.shaparak.ir/VPG/Purchase?Token=$Token";
                header("Location: $url");
            } else {
                $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
                $app->redirect($link, $arrres->Description, $msgType = 'Error');
            }
        } catch (\SoapFault $e) {
            $msg = $e;
            $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
        }
    }

    public function plgVmOnPaymentResponseReceived(&$html)
    {
        if (!class_exists('nusoap_client')) {
            require_once("helper/nusoap.php");
        }

        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }

        $app = JFactory::getApplication();
        $jinput = $app->input;
        $session = JFactory::getSession();
        if ($session->isActive('uniq') && $session->get('uniq') != null) {
            $cryptID = $session->get('uniq');
        } else {
            $msg = 'notff';
            $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
            $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
        }
        $orderInfo = $this->getOrderInfo($cryptID);
        if ($orderInfo != null) {
            if (!($currentMethod = $this->getVmPluginMethod($orderInfo->virtuemart_paymentmethod_id))) {
                return NULL;
            }
        } else {
            return NULL;
        }

        $salt = $orderInfo->salt;
        $id = $orderInfo->virtuemart_order_id;
        $uId = $cryptID . ':' . $salt;
        $order_id = $orderInfo->order_number;
        $payment_id = $orderInfo->virtuemart_paymentmethod_id;
        $pass_id = $orderInfo->order_pass;
        $method = $this->getVmPluginMethod($payment_id);

        if (JUserHelper::verifyPassword($id, $uId)) {
            if (isset($_POST['OrderId']) && isset($_POST['token']) && isset($_POST['ResCode'])) {
                try {
                    $key = $method->key;
                    $Token = $_POST["token"];
                    $ResCode = $_POST["ResCode"];

                    if ($ResCode == '0') {
                        $verifyData = [
                            'Token' => $Token,
                            'SignData' => $this->encrypt_pkcs7($Token, $key)
                        ];
                        $str_data = json_encode($verifyData);
                        $res = $this->CallAPI('https://sadad.shaparak.ir/vpg/api/v0/Advice/Verify', $str_data);
                        $arrres = json_decode($res);
                        if ($arrres->ResCode != '-1' && $ResCode == '0') {
                            $msg = 'پرداخت شما با موفقیت انجام شد';
                            $html = $this->renderByLayout('meli_payment', array(
                                'order_number' => $order_id,
                                'order_pass' => $pass_id,
                                'tracking_code' => $arrres->SystemTraceNo,
                                'status' => $msg
                            ));

                            $msg .= ' - پیگیری: ' . $arrres->SystemTraceNo . ' مرجع: ' . $arrres->RetrivalRefNo;

                            $this->updateStatus('C', 1, $msg, $id);
                            $this->updateOrderInfo($id, $arrres->SystemTraceNo);
                            vRequest::setVar('html', $html);
                            $cart = VirtueMartCart::getCart();
                            $cart->emptyCart();
                            $session->clear('uniq');

                        } else {
                            $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
                            $app->redirect($link, 'تراکنش نا موفق بود در صورت کسر مبلغ از حساب شما حداکثر پس از 72 ساعت مبلغ به حسابتان برمی گردد.', $msgType = 'Error');
                        }
                    } else {
                        $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
                        $app->redirect($link, 'کاربر منصرف شد', $msgType = 'Error');
                    }
                } catch (\SoapFault $e) {
                    $msg = $e;
                    $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
                    $app->redirect($link, '<h2>' . $msg . '</h2>', $msgType = 'Error');
                }
            } else {
                $link = JRoute::_(JUri::root() . 'index.php/component/virtuemart/cart', false);
                $app->redirect($link, '<h2>کاربر منصرف شد</h2>', $msgType = 'Error');
            }
        }
    }

    protected function getOrderInfo($id)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $query->select('*')
            ->from($db->qn('#__virtuemart_payment_plg_meli'));
        $query->where($db->qn('crypt_virtuemart_pid') . ' = ' . $db->q($id));
        $db->setQuery((string)$query);
        $result = $db->loadObject();
        return $result;
    }

    protected function updateOrderInfo($id, $trackingCode)
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true);
        $fields = array($db->qn('tracking_code') . ' = ' . $db->q($trackingCode));
        $conditions = array($db->qn('virtuemart_order_id') . ' = ' . $db->q($id));
        $query->update($db->qn('#__virtuemart_payment_plg_meli'));
        $query->set($fields);
        $query->where($conditions);

        $db->setQuery($query);
        $result = $db->execute();
    }


    protected function checkConditions($cart, $method, $cart_prices)
    {
        $amount = $this->getCartAmount($cart_prices);
        $address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

        if ($this->_toConvert) {
            $this->convertToVendorCurrency($method);
        }

        $countries = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }

        if (!is_array($address)) {
            $address = array();
            $address['virtuemart_country_id'] = 0;
        }

        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (count($countries) == 0 || in_array($address['virtuemart_country_id'], $countries)) {
            return TRUE;
        }

        return FALSE;
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {

        if ($this->getPluginMethods($cart->vendorId) === 0) {
            if (empty($this->_name)) {
                $app = JFactory::getApplication();
                $app->enqueueMessage(vmText::_('COM_VIRTUEMART_CART_NO_' . strtoupper($this->_psType)));
                return false;
            } else {
                return false;
            }
        }
        $method_name = $this->_psType . '_name';

        $htmla = array();
        foreach ($this->methods as $this->_currentMethod) {
            if ($this->checkConditions($cart, $this->_currentMethod, $cart->cartPrices)) {

                $html = '';
                $cartPrices = $cart->cartPrices;
                if (isset($this->_currentMethod->cost_method)) {
                    $cost_method = $this->_currentMethod->cost_method;
                } else {
                    $cost_method = true;
                }
                $methodSalesPrice = $this->setCartPrices($cart, $cartPrices, $this->_currentMethod, $cost_method);

                $this->_currentMethod->payment_currency = $this->getPaymentCurrency($this->_currentMethod);
                $this->_currentMethod->$method_name = $this->renderPluginName($this->_currentMethod);
                $html .= $this->getPluginHtml($this->_currentMethod, $selected, $methodSalesPrice);
                $htmla[] = $html;
            }
        }
        $htmlIn[] = $htmla;
        return true;

    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return null;
        }

        return $this->OnSelectCheck($cart);
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    public function plgVmOnCheckoutCheckDataPayment(VirtueMartCart $cart)
    {
        if (!$this->selectedThisByMethodId($cart->virtuemart_paymentmethod_id)) {
            return NULL;
        }
        return true;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {

        return $this->onStoreInstallPluginTable($jplugin_id);
    }


    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {

        return $this->setOnTablePluginParams($name, $id, $table);
    }

    static function getPaymentCurrency(&$method, $selectedUserCurrency = false)
    {

        if (empty($method->payment_currency)) {
            $vendor_model = VmModel::getModel('vendor');
            $vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
            $method->payment_currency = $vendor->vendor_currency;
            return $method->payment_currency;
        } else {

            $vendor_model = VmModel::getModel('vendor');
            $vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies($method->virtuemart_vendor_id);

            if (!$selectedUserCurrency) {
                if ($method->payment_currency == -1) {
                    $mainframe = JFactory::getApplication();
                    $selectedUserCurrency = $mainframe->getUserStateFromRequest("virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt('virtuemart_currency_id', $vendor_currencies['vendor_currency']));
                } else {
                    $selectedUserCurrency = $method->payment_currency;
                }
            }

            $vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
            if (in_array($selectedUserCurrency, $vendor_currencies['all_currencies'])) {
                $method->payment_currency = $selectedUserCurrency;
            } else {
                $method->payment_currency = $vendor_currencies['vendor_currency'];
            }

            return $method->payment_currency;
        }

    }

    protected function updateStatus($status, $notified, $comments = '', $id)
    {
        $modelOrder = VmModel::getModel('orders');
        $order['order_status'] = $status;
        $order['customer_notified'] = $notified;
        $order['comments'] = $comments;
        $modelOrder->updateStatusForOneOrder($id, $order, TRUE);
    }

    //Create sign data(Tripledes(ECB,PKCS7))
    function encrypt_pkcs7($str, $key)
    {
        $key = base64_decode($key);
        $ciphertext = OpenSSL_encrypt($str, "DES-EDE3", $key, OPENSSL_RAW_DATA);
        return base64_encode($ciphertext);
    }

    //Send Data
    function CallAPI($url, $data = false)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Content-Length: ' . strlen($data)));
        $result = curl_exec($curl);
        curl_close($curl);
        return $result;
    }

}
