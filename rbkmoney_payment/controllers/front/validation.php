<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @since 1.5.0
 */
class Rbkmoney_PaymentValidationModuleFrontController extends ModuleFrontController
{

    /**
     * Constants for Callback
     */
    const SIGNATURE = 'HTTP_CONTENT_SIGNATURE';
    const SIGNATURE_ALG = 'alg';
    const SIGNATURE_DIGEST = 'digest';
    const SIGNATURE_PATTERN = '|alg=(\S+);\sdigest=(.*)|i';

    /**
     * HTTP CODE
     */
    const HTTP_CODE_OK = 200;
    const HTTP_CODE_CREATED = 201;
    const HTTP_CODE_MOVED_PERMANENTLY = 301;
    const HTTP_CODE_BAD_REQUEST = 400;
    const HTTP_CODE_INTERNAL_SERVER_ERROR = 500;

    /**
     * Openssl verify
     */
    const OPENSSL_VERIFY_SIGNATURE_IS_CORRECT = 1;
    const OPENSSL_VERIFY_SIGNATURE_IS_INCORRECT = 0;
    const OPENSSL_VERIFY_ERROR = -1;
    const OPENSSL_SIGNATURE_ALG = OPENSSL_ALGO_SHA256;

    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {

        if (empty($_SERVER[static::SIGNATURE])) {
            $message = 'Webhook notification signature missing';
            $this->outputWithLogger($message, static::HTTP_CODE_BAD_REQUEST);
        }

        $paramsSignature = $this->getParametersContentSignature($_SERVER[static::SIGNATURE]);
        if (empty($paramsSignature[static::SIGNATURE_ALG])) {
            $message = 'Missing required parameter ' . static::SIGNATURE_ALG;
            $this->outputWithLogger($message, static::HTTP_CODE_BAD_REQUEST);
        }

        if (empty($paramsSignature[static::SIGNATURE_DIGEST])) {
            $message = 'Missing required parameter ' . static::SIGNATURE_DIGEST;
            $this->outputWithLogger($message, static::HTTP_CODE_BAD_REQUEST);
        }

        $signature = $this->urlsafeB64decode($paramsSignature[static::SIGNATURE_DIGEST]);
        $content = file_get_contents('php://input');
        $publicKey = trim(Configuration::get(Rbkmoney_Payment::RBKM_WEBHOOK_KEY));
        if (!$this->verificationSignature($content, $signature, $publicKey)) {
            $message = 'Webhook notification signature mismatch';
            $this->outputWithLogger($message, static::HTTP_CODE_BAD_REQUEST);
        }

        $data = json_decode($content, TRUE);

        $currentShopId = Configuration::get(Rbkmoney_Payment::RBKM_SHOP_ID);
        if ($data['invoice']['shopID'] != $currentShopId) {
            $message = 'Shop ID is missing';
            $this->outputWithLogger($message, static::HTTP_CODE_BAD_REQUEST);
        }

        $cartId = isset($data['invoice']['metadata']['cart_id']) ? $data['invoice']['metadata']['cart_id'] : "";

        if (empty($cartId)) {
            $message = 'Cart ID is missing';
            $this->outputWithLogger($message, static::HTTP_CODE_BAD_REQUEST);
        }

        $allowedEventTypes = ["InvoicePaid", "InvoiceCancelled"];
        if (in_array($data["eventType"], $allowedEventTypes)) {

            $orderId = Order::getOrderByCartId($cartId);
            $order = new Order($orderId);
            $invoiceStatus = $data["invoice"]["status"];

            if ($invoiceStatus == "paid") {
                $order->setCurrentState(Configuration::get('PS_OS_WS_PAYMENT'));
            }

            if ($invoiceStatus == "cancelled") {
                $order->setCurrentState(Configuration::get('_PS_OS_CANCELED_'));
            }

        }

        exit();
    }

    function outputWithLogger($message, $httpCode = self::HTTP_CODE_BAD_REQUEST) {
        http_response_code($httpCode);
        Rbkmoney_Payment::logger($message);
        echo json_encode(array('message' => $message));
        exit();
    }

    function urlsafeB64decode($string)
    {
        return base64_decode(strtr($string, '-_,', '+/='));
    }

    function getParametersContentSignature($contentSignature)
    {
        preg_match_all(static::SIGNATURE_PATTERN, $contentSignature, $matches, PREG_PATTERN_ORDER);
        $params = array();
        $params[static::SIGNATURE_ALG] = !empty($matches[1][0]) ? $matches[1][0] : '';
        $params[static::SIGNATURE_DIGEST] = !empty($matches[2][0]) ? $matches[2][0] : '';
        return $params;
    }

    function verificationSignature($data = '', $signature = '', $public_key = '')
    {
        if (empty($data) || empty($signature) || empty($public_key)) {
            return FALSE;
        }
        $public_key_id = openssl_get_publickey($public_key);
        if (empty($public_key_id)) {
            return FALSE;
        }
        $verify = openssl_verify($data, $signature, $public_key_id, static::OPENSSL_SIGNATURE_ALG);
        return ($verify == static::OPENSSL_VERIFY_SIGNATURE_IS_CORRECT);
    }

}
