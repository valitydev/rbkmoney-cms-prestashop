<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @since 1.5.0
 */
class Rbkmoney_PaymentSuccessModuleFrontController extends ModuleFrontController
{

    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $this->setTemplate('module:rbkmoney_payment/views/templates/front/payment_success.tpl');
    }

}
