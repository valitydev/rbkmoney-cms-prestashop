<?php

if (!defined('_PS_VERSION_')) {
    exit;
}


/**
 * @since 1.5.0
 */
class Rbkmoney_PaymentPaymentModuleFrontController extends ModuleFrontController
{

    const CHECKOUT_URL = 'https://checkout.rbk.money/html/payframe.html';
    const COMMON_API_URL = 'https://api.rbk.money/v1/';

    /**
     * Create invoice settings
     */
    const CREATE_INVOICE_TEMPLATE_DUE_DATE = 'Y-m-d\TH:i:s\Z';
    const CREATE_INVOICE_DUE_DATE = '+1 days';


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
            'product' => $cart->getProducts()[0]["manufacturer_name"],
            'cart' => $this->prepareCart($cart),
            'description' => '',
        ];

        $url = static::COMMON_API_URL . 'processing/invoices';
        $request = [
            'headers' => $headers,
            'data' => $data,
            'url' => $url,
        ];
        $logs = [
            'request' => $request,
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

        if ($info['http_code'] != 201) {
            Tools::redirect($this->context->link->getPageLink('order', true, NULL, "step=3"));
        }


        $response = json_decode($body, true);

        $successUrl = 'http://' . htmlspecialchars($_SERVER['HTTP_HOST'], ENT_COMPAT, 'UTF-8')
            . __PS_BASE_URI__ . 'index.php?fc=module&module=' . $this->module->name . '&controller=success';


        $dataCheckout = [];
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

        $logo = Configuration::get(Rbkmoney_Payment::RBKM_PAYFORM_PATH_LOGO);
        if (!empty($logo)) {
            $dataCheckout["logo"] = $logo;
        }

        $customer = new Customer($cart->id_customer);
        if (!empty($customer->email)) {
            $dataCheckout['email'] = $customer->email;
        }

        $dataCheckout["redirectUrl"] = $successUrl;
        $dataCheckout["popupMode"] = "true";
        $dataCheckout["invoiceID"] = $response["invoice"]["id"];
        $dataCheckout["invoiceAccessToken"] = $response["invoiceAccessToken"]["payload"];


        $params = http_build_query($dataCheckout);
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
        $headers = [];
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
        return $amount * 100;
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

        $lines = [];
        foreach ($cart->getProducts() as $product) {

            $item = [];

            $item['product'] = $product['name'];
            $item['quantity'] = $product['quantity'];

            $price = round($product['price_wt'], 2, PHP_ROUND_HALF_UP);
            $item['price'] = $this->prepareAmount($price);

            if (!empty($product['rate'])) {
                $taxMode = [
                    'type' => 'InvoiceLineTaxVAT',
                    'rate' => $this->getRate($product['rate']),
                ];

                $item['taxMode'] = $taxMode;
            }

            $lines[] = $item;
        }

        // Доставка
        /** @var Carrier $carrier */
        $carrier = $cart->getSummaryDetails()['carrier'];
        if (!$carrier->is_free && $cart->getPackageShippingCost() > 0) {
            $item = [];

            $item['product'] = $carrier->name . ' (' . $carrier->delay . ')';
            $item['quantity'] = 1;
            $item['price'] = $this->prepareAmount($cart->getPackageShippingCost());

            // Доставка всегда с НДС 18%?
            $taxMode = [
                'type' => 'InvoiceLineTaxVAT',
                'rate' => "18%",
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
            $totalPrice = $product['quantity'] * round($product['price_wt'], 2, PHP_ROUND_HALF_UP);
            $price = round($totalPrice, 2, PHP_ROUND_HALF_UP);
            $amount += $price;
        }

        // Добавляем доставку
        /** @var Carrier $carrier */
        $carrier = $cart->getSummaryDetails()['carrier'];
        if (!$carrier->is_free && $cart->getPackageShippingCost() > 0) {
            $amount += $cart->getPackageShippingCost();
        }
        return $amount;
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

            case '18':
                return '18%';
                break;

            case '10/100':
                return '10/110';
                break;

            case '18/118':
                return '18/118';
                break;

            default:
                return null;
                break;
        }
    }

}
