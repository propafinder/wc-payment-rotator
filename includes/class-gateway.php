<?php
defined('ABSPATH') || exit;

class WC_PLR_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'wc_plr';
        $this->has_fields         = false;
        $this->method_title       = 'Payment Link Rotator';
        $this->method_description = 'Ротация внешних платёжных ссылок';

        $this->title       = get_option('wc_plr_title', 'Оплата онлайн');
        $this->description = get_option('wc_plr_description', 'Безопасная оплата через внешний платёжный сервис.');
        $this->enabled     = $this->get_option('enabled', 'yes');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
    }

    public function process_payment($order_id): array {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice('Ошибка при создании платежа.', 'error');
            return ['result' => 'failure'];
        }

        $dest = WC_PLR_Rotator::get_link($order);
        if (!$dest) {
            wc_add_notice('Платёжный сервис временно недоступен. Попробуйте позже.', 'error');
            return ['result' => 'failure'];
        }

        // Подстановка суммы (в копейках/центах) и email для Stripe и аналогов
        $amount = intval($order->get_total() * 100);
        $email  = $order->get_billing_email();
        $dest   = add_query_arg([
            '__prefilled_amount' => $amount,
            'prefilled_email'   => $email,
        ], $dest);

        // Параметры заказа
        $dest = add_query_arg([
            'order_id'  => $order_id,
            'order_key' => $order->get_order_key(),
        ], $dest);

        // Статус «На удержании» до подтверждения оплаты
        $order->update_status('on-hold', 'Customer redirected to external payment.');
        $order->save();

        // Уменьшаем запасы
        wc_reduce_stock_levels($order_id);

        // Очищаем корзину
        WC()->cart->empty_cart();

        // Генерируем прокси-URL
        $proxy_url = WC_PLR_Proxy::make_url($dest, $order_id);

        return [
            'result'   => 'success',
            'redirect' => $proxy_url,
        ];
    }
}