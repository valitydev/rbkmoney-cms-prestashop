<?php

if (!defined('_PS_VERSION_')) {
    exit;
}


/**
 * @since 1.5.0
 */
class Rbkmoney_PaymentPaymentModuleFrontController extends ModuleFrontController
{

    const CHECKOUT_URL = 'https://checkout.rbk.money/v1/checkout.html';
    const COMMON_API_URL = 'https://api.rbk.money/v2/';

    /**
     * Create invoice settings
     */
    const CREATE_INVOICE_TEMPLATE_DUE_DATE = 'Y-m-d\TH:i:s\Z';
    const CREATE_INVOICE_DUE_DATE = '+1 days';

    const HTTP_CREATED = 201;
    const DELIVERY_TAX_MODE = "20%";


    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        parent::initContent();

        $cart = $this->context->cart;
        $headers = $this->prepareHeaders(Configuration::get(Rbkmoney_Payment::RBKM_API_KEY));
        $shopId = Configuration::get(Rbkmoney_Payment::RBKM_SHOP_ID);
        $currency = $this->context->currency;

        $data = [
            'shopID' => $shopId,
            'amount' => $this->prepareAmount($this->getTotalAmount($cart)),
            'metadata' => $this->prepareMetadata($cart),
            'dueDate' => $this->prepareDueDate(),
            'currency' => $currency->iso_code,
            'product' => isset($cart->getProducts()[0]["manufacturer_name"]) ? $cart->getProducts()[0]["manufacturer_name"] : "",
            'cart' => $this->prepareCart($cart),
            'description' => "Order " . $cart->id,
        ];

        $url = static::COMMON_API_URL . 'processing/invoices';
        $request = [
            'headers' => $headers,
            'data' => $data,
            'url' => $url,
        ];
        $logs = [
            'request' => $request,
            'json' => json_encode($data),
        ];
        Rbkmoney_Payment::logger("Create invoice - begin", $logs);

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_POST, TRUE);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($curl);
        $info = curl_getinfo($curl);
        $curl_errno = curl_errno($curl);

        $responseCreateInvoice = [
            'code' => $info['http_code'],
            'body' => $body,
            'info' => $info,
            'error' => $curl_errno,
        ];

        $logs['response'] = $responseCreateInvoice;

        Rbkmoney_Payment::logger("Create invoice - finish", $logs);

        if ($info['http_code'] != static::HTTP_CREATED) {
            Tools::redirect($this->context->link->getPageLink('order', true, NULL, "step=3"));
        }


        $response = json_decode($body, true);

        $successUrl = static::getCurrentSchema() . '://' . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8')
            . __PS_BASE_URI__ . 'index.php?fc=module&module=' . $this->module->name . '&controller=success';


        $dataCheckout = array();
        $companyName = Configuration::get(Rbkmoney_Payment::RBKM_PAYFORM_COMPANY_NAME);
        if (!empty($companyName)) {
            $dataCheckout["name"] = $companyName;
        }

        $description = Configuration::get(Rbkmoney_Payment::RBKM_PAYFORM_DESCRIPTION);
        if (!empty($description)) {
            $dataCheckout["description"] = $description;
        }

        $payButtonLabel = Configuration::get(Rbkmoney_Payment::RBKM_PAYFORM_BUTTON_LABEL);
        if (!empty($payButtonLabel)) {
            $dataCheckout["payButtonLabel"] = $payButtonLabel;
        }

        $customer = new Customer($cart->id_customer);
        if (!empty($customer->email)) {
            $dataCheckout['email'] = $customer->email;
        }

        $dataCheckout["redirectUrl"] = $successUrl;
        $dataCheckout["popupMode"] = "true";
        $dataCheckout["invoiceID"] = $response["invoice"]["id"];
        $dataCheckout["invoiceAccessToken"] = $response["invoiceAccessToken"]["payload"];


        $params = http_build_query($dataCheckout, null, '&', PHP_QUERY_RFC3986);
        $checkoutUrl = static::CHECKOUT_URL . "?" . $params;

        $rbkmoney = new RBKmoney_Payment();
        $rbkmoney->validateOrder((int)$cart->id,
            Configuration::get('PS_OS_BANKWIRE'), $cart->getOrderTotal(),
            $this->module->displayName, null, null,
            $cart->id_currency, false,
            $customer->secure_key);

        Tools::redirect($checkoutUrl);
    }

    public function prepareHeaders($apiKey)
    {
        $headers = array();
        $headers[] = 'X-Request-ID: ' . uniqid();
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $headers[] = 'Content-type: application/json; charset=utf-8';
        $headers[] = 'Accept: application/json';
        return $headers;
    }

    /**
     * Prepare due date
     * @return string
     */
    function prepareDueDate()
    {
        date_default_timezone_set('UTC');
        return date(static::CREATE_INVOICE_TEMPLATE_DUE_DATE, strtotime(static::CREATE_INVOICE_DUE_DATE));
    }

    function prepareAmount($amount)
    {
        $prepareAmount = $amount * 100;
        return (int)$prepareAmount;
    }

    /**
     * Prepare metadata
     *
     * @param $cart Cart
     * @return array
     */
    function prepareMetadata(Cart $cart)
    {
        return [
            'cms' => 'prestashop',
            'cms_version' => _PS_VERSION_,
            'module' => $this->module->name,
            'cart_id' => $cart->id,
        ];
    }

    /**
     * <pre>
     * <code>
     * [cart] => Array
     *  (
     *      [0] => Array
     *      (
     *          [product] => Printed Dress
     *          [quantity] => 3
     *          [price] => 3067
     *          [taxMode] => Array
     *          (
     *              [type] => InvoiceLineTaxVAT
     *              [rate] => 18%
     *          )
     *
     *      )
     *      [1] => Array
     *      (
     *          [product] => Test (Самовывоз)
     *          [quantity] => 1
     *          [price] => 27376
     *          [taxMode] => Array
     *          (
     *              [type] => InvoiceLineTaxVAT
     *              [rate] => 18%
     *          )
     *      )
     *
     * )
     * <code>
     * </pre>
     * @param Cart $cart
     * @return array
     */
    function prepareCart(Cart $cart)
    {
        $lines = array();
        foreach ($cart->getProducts() as $product) {

            $item = array();

            $item['product'] = $product['name'];
            $item['quantity'] = $product['quantity'];

            $price = number_format($product['price_wt'], 2, '.', '');
            if ($price <= 0) {
                continue;
            }

            $item['price'] = $this->prepareAmount($price);

            $rate = trim($product['rate']);
            if (!empty($rate)) {
                $taxMode = [
                    'type' => 'InvoiceLineTaxVAT',
                    'rate' => $this->getRate($product['rate']),
                ];

                $item['taxMode'] = $taxMode;
            }

            $lines[] = $item;
        }

        // delivery
        /** @var Carrier $carrier */
        $carrier = $cart->getSummaryDetails()['carrier'];
        if (!$carrier->is_free && $cart->getPackageShippingCost() > 0) {
            $item = array();

            $item['product'] = $carrier->name . ' (' . $carrier->delay . ')';
            $item['quantity'] = 1;

            $price = number_format($cart->getPackageShippingCost(), 2, '.', '');
            $item['price'] = $this->prepareAmount($price);

            // Доставка всегда с НДС 20%?
            $taxMode = [
                'type' => 'InvoiceLineTaxVAT',
                'rate' => static::DELIVERY_TAX_MODE,
            ];

            $item['taxMode'] = $taxMode;
            $lines[] = $item;
        }

        return $lines;
    }

    /**
     * Get total amount
     * @param Cart $cart
     * @return float|int
     */
    function getTotalAmount(Cart $cart)
    {
        $amount = 0;
        foreach ($cart->getProducts() as $product) {
            $totalPrice = $product['quantity'] * number_format($product['price_wt'], 2, '.', '');
            $amount += $totalPrice;
        }

        // Added delivery
        /** @var Carrier $carrier */
        $carrier = $cart->getSummaryDetails()['carrier'];
        if (!$carrier->is_free && $cart->getPackageShippingCost() > 0) {
            $amount += $cart->getPackageShippingCost();
        }
        return number_format($amount, 2, '.', '');
    }

    public function getRate($rate)
    {
        switch ($rate) {

            case '0':
                return '0%';
                break;

            case '10':
                return '10%';
                break;

            case '20':
                return '20%';
                break;

            default:
                return null;
                break;
        }
    }

    public static function getCurrentSchema()
    {
        return ((isset($_SERVER['HTTPS']) && preg_match("/^on$/i", $_SERVER['HTTPS'])) ? "https" : "http");
    }

}
