<?php

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}


/**
 * Class RbkmoneyPayment
 *
 * @see https://rbkmoney.github.io/docs/
 * @see https://rbkmoney.github.io/api/
 * @see https://rbkmoney.github.io/webhooks-events-api/
 * @see http://doc.prestashop.com/display/PS17/User+Guide
 * @see http://doc.prestashop.com/display/PS17/Payment+Preferences
 * @see http://developers.prestashop.com/module/50-PaymentModules/index.html
 */
class Rbkmoney_Payment extends PaymentModule
{

    // Required
    const RBKM_SHOP_ID = 'RBKM_SHOP_ID';
    const RBKM_SHOP_ID_DEFAULT = 'TEST';
    const RBKM_API_KEY = 'RBKM_API_KEY';
    const RBKM_WEBHOOK_KEY = 'RBKM_WEBHOOK_KEY';

    // Optional
    const RBKM_PAYFORM_BUTTON_LABEL = 'RBKM_PAYFORM_BUTTON_LABEL';
    const RBKM_PAYFORM_DESCRIPTION = 'RBKM_PAYFORM_DESCRIPTION';
    const RBKM_PAYFORM_COMPANY_NAME = 'RBKM_PAYFORM_COMPANY_NAME';
    const RBKM_PAYFORM_CSS_BUTTON = 'RBKM_PAYFORM_CSS_BUTTON';

    // Logs
    const RBKM_DEBUG = 'RBKM_DEBUG';
    const RBKM_DEBUG_TRUE = 'true';
    const RBKM_DEBUG_FALSE = 'false';

    // Other
    const CHARSET = 'UTF-8';


    /**
     * rbkmoney_payment constructor.
     */
    function __construct()
    {
        $this->name = 'rbkmoney_payment';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.1';
        $this->author = 'RBKmoney';
        $this->need_instance = 1;
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->controllers = array('payment', 'validation', 'success');
        $this->currencies = true;
        $this->currencies_mode = 'radio';

        parent::__construct();

        $this->displayName = $this->l('Платежная система RBKmoney');
        $this->description = $this->l('Прием платежей через платежную систему RBKmoney');
        $this->confirmUninstall = $this->l('Вы действительно хотите удалить?');
    }

    /**
     * @return bool
     */
    function install()
    {

        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }

        parent::install();

        // Registration hook
        $this->registerHook('paymentReturn');
        $this->registerHook('paymentOptions');

        if (!Configuration::updateValue(static::RBKM_SHOP_ID, static::RBKM_SHOP_ID_DEFAULT)
            || !Configuration::updateValue(static::RBKM_API_KEY, '')
            || !Configuration::updateValue(static::RBKM_WEBHOOK_KEY, '')
            || !Configuration::updateValue(static::RBKM_PAYFORM_BUTTON_LABEL, '')
            || !Configuration::updateValue(static::RBKM_PAYFORM_DESCRIPTION, '')
            || !Configuration::updateValue(static::RBKM_PAYFORM_COMPANY_NAME, '')
            || !Configuration::updateValue(static::RBKM_DEBUG, static::RBKM_DEBUG_FALSE)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return bool
     */
    function uninstall()
    {
        $config = array(
            static::RBKM_SHOP_ID,
            static::RBKM_API_KEY,
            static::RBKM_WEBHOOK_KEY,

            static::RBKM_PAYFORM_BUTTON_LABEL,
            static::RBKM_PAYFORM_DESCRIPTION,
            static::RBKM_PAYFORM_COMPANY_NAME,

            static::RBKM_DEBUG
        );

        foreach ($config as $name) {
            Configuration::deleteByName($name);
        }

        // Uninstall default
        parent::uninstall();

        return true;
    }

    /**
     * @return string
     */
    public function getContent()
    {
        $this->output = "<h2>RBKmoney</h2>";

        if (isset($_POST['submit'])) {

            $params = [
                'rbkm_shop_id' => static::RBKM_SHOP_ID,
                'rbkm_api_key' => static::RBKM_API_KEY,
                'rbkm_webhook_key' => static::RBKM_WEBHOOK_KEY,

                'rbkm_payform_button_label' => static::RBKM_PAYFORM_BUTTON_LABEL,
                'rbkm_payform_description' => static::RBKM_PAYFORM_DESCRIPTION,
                'rbkm_payform_company_name' => static::RBKM_PAYFORM_COMPANY_NAME,

                'rbkm_debug' => static::RBKM_DEBUG,
            ];

            foreach ($params as $key => $value) {
                if (!empty($_POST[$key])) {
                    Configuration::updateValue($value, $_POST[$key]);
                }
            }

            $this->output .= '<div class="conf confirm"><img src="../img/admin/enabled.gif" alt="' . $this->l('Подтверждение') . '" />' . $this->l('Настройки обновлены') . '</div>';
        }

        $this->displayFormSettings();

        return $this->output;
    }

    /**
     * Settings
     */
    public function displayFormSettings()
    {
        $rbkm_shop_id = htmlentities(Configuration::get(static::RBKM_SHOP_ID), ENT_COMPAT, static::CHARSET);
        $rbkm_api_key = htmlentities(Configuration::get(static::RBKM_API_KEY), ENT_COMPAT, static::CHARSET);
        $rbkm_webhook_key = htmlentities(Configuration::get(static::RBKM_WEBHOOK_KEY), ENT_COMPAT, static::CHARSET);

        $rbkm_payform_button_label = htmlentities(Configuration::get(static::RBKM_PAYFORM_BUTTON_LABEL), ENT_COMPAT, static::CHARSET);
        $rbkm_payform_description = htmlentities(Configuration::get(static::RBKM_PAYFORM_DESCRIPTION), ENT_COMPAT, static::CHARSET);
        $rbkm_payform_company_name = htmlentities(Configuration::get(static::RBKM_PAYFORM_COMPANY_NAME), ENT_COMPAT, static::CHARSET);


        if (htmlentities(Configuration::get(static::RBKM_DEBUG), ENT_COMPAT, static::CHARSET) == 'true') {
            $on = 'checked="checked"';
            $off = '';
        } else {
            $on = '';
            $off = 'checked="checked"';
        }

        $this->output .= '<form action="' . $_SERVER['REQUEST_URI'] . '" method="post" xmlns="http://www.w3.org/1999/html">
		    <fieldset>
			    <legend><img src="../img/admin/contact.gif" />' . $this->l('Настройки платежного модуля RBKmoney') . '</legend>
			    
			    <h3>' . $this->l('Обязательные настройки') . '</h3>
			    
			    <label for="rbkm_shop_id">' . $this->l('ID магазина') . '</label>
			    <div class="margin-form">
			        <input type="text" name="rbkm_shop_id" id="rbkm_shop_id" size="121px" value="' . $rbkm_shop_id . '" />
			    </div>
			    
			    <label for="rbkm_api_key">' . $this->l('Api ключ') . '</label>
			    <div class="margin-form">
			        <textarea name="rbkm_api_key" cols="120" rows="6">' . $rbkm_api_key . '</textarea>
			    </div>
			    
			    <label for="rbkm_webhook_key">' . $this->l('Публичный ключ') . '</label>
			    <div class="margin-form">
			        <textarea name="rbkm_webhook_key" cols="120" rows="6">' . $rbkm_webhook_key . '</textarea>
			    </div>
			    
			    
			    <h3>' . $this->l('Кастомизация формы оплаты') . '</h3>
			    
			    <label for="rbkm_payform_button_label">' . $this->l('Текст кнопки открытия формы оплаты') . '</label>
			    <div class="margin-form">
			        <input type="text" name="rbkm_payform_button_label" id="rbkm_payform_button_label" size="121px" value="' . $rbkm_payform_button_label . '" />
			    </div>
			    
			    <label for="rbkm_payform_description">' . $this->l('Описание') . '</label>
			    <div class="margin-form">
			        <input type="text" name="rbkm_payform_description" id="rbkm_payform_description" size="121px" value="' . $rbkm_payform_description . '" />
			    </div>
			    
			    <label for="rbkm_payform_company_name">' . $this->l('Название магазина') . '</label>
			    <div class="margin-form">
			        <input type="text" name="rbkm_payform_company_name" id="rbkm_payform_company_name" size="121px" value="' . $rbkm_payform_company_name . '" />
			    </div>
			    
			    <h3>' . $this->l('Дополнительные настройки') . '</h3>
			    
			    <label>' . $this->l('Сохранять лог RBKmoney API (Расширенные параметры > Журнал событий)') . '</label>
			    <div class="margin-form">
			        <input type="radio" name="rbkm_debug" value="false" ' . $off . '/>Off
			        <input type="radio" name="rbkm_debug" value="true" ' . $on . '/>On
			    </div>
			    
			    <br />
			    
			    <h3>' . $this->l('Документация') . '</h3>
			    <ul>
			        <li><a href="https://rbkmoney.github.io/docs" target="_blank">' . $this->l('Документация по интеграции') .'</a></li>
			        <li><a href="https://rbkmoney.github.io/webhooks-events-api/" target="_blank">' . $this->l('Документация для работы с вебхуками') .'</a></li>
			        <li><a href="https://rbkmoney.github.io/docs/integrations/checkout/#html-api" target="_blank">' . $this->l('Документация по кастомизации платежной формы') .'</a></li>
                </ul>

                <div class="margin-form">
                    <input type="submit" name="submit" value="' . $this->l('Обновить настройки') . '" class="button" />
                </div>
		    </fieldset>
		</form>';

    }

    /**
     * Отображение в корзине при выборе способа оплаты
     * @param $params
     * @return array|void
     */
    public function hookPaymentOptions($params)
    {
        $payments_options = [];

        if (!$this->active) {
            return $payments_options;
        }

        $payment_options = new PaymentOption();
        $payment_options->setAction($this->context->link->getModuleLink($this->name, 'payment', array(), true));
        $payment_options->setCallToActionText("RBKmoney");
        $payment_options->setModuleName($this->name);
        $payment_options->setAdditionalInformation($this->fetch('module:rbkmoney_payment/views/templates/front/payment_infos.tpl'));

        $payments_options[] = $payment_options;
        return $payments_options;
    }

    /**
     * @param $params
     * @return string
     */
    function hookPaymentReturn($params)
    {
        return $this->display(__FILE__, 'module:rbkmoney_payment/views/templates/front/payment_success.tpl');
    }

    /**
     * @param int $id_cart
     * @param int $id_order_state
     * @param float $amount_paid
     * @param string $payment_method
     * @param null $message
     * @param array $extra_vars
     * @param null $currency_special
     * @param bool $dont_touch_amount
     * @param bool $secure_key
     * @param Shop|null $shop
     *
     * @return boolean
     */
    public function validateOrder(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = array(),
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        Shop $shop = null
    )
    {
        parent::validateOrder($id_cart, $id_order_state, $amount_paid,
            $payment_method, $message, $extra_vars, $currency_special,
            $dont_touch_amount, $secure_key, $shop);
    }

    public static function logger($message, array $context = array(), $level = Monolog\Logger::INFO)
    {
        $debug = Configuration::get(Rbkmoney_Payment::RBKM_DEBUG);
        if ($debug == static::RBKM_DEBUG_TRUE) {
            /** @var PrestaShop\PrestaShop\Adapter\LegacyLogger $logger */
            $logger = \PrestaShop\PrestaShop\Adapter\ServiceLocator::get('\\PrestaShop\\PrestaShop\\Adapter\\LegacyLogger');
            $logger->log($level, $message . " " . print_r($context, TRUE) . " ", array());
        }

    }

}