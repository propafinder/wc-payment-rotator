<?php
defined('ABSPATH') || exit;

class WC_PLR_Gateway extends WC_Payment_Gateway {

    public function __construct() {

        $this->id                 = 'wc_plr';
        $this->has_fields         = false;
        $this->method_title       = 'Payment Link Rotator';
        $this->method_description = 'External payment link rotation gateway';

        $this->title       = get_option('wc_plr_title', 'Online payment');
        $this->description = get_option('wc_plr_description', 'Secure payment via external provider.');
        $this->enabled     = $this->get_option('enabled', 'yes');

        add_action(
            'woocommerce_update_options_payment_gateways_' . $this->id,
            [$this, 'process_admin_options']
        );
    }

    public function process_payment($order_id): array {

        $order = wc_get_order($order_id);

        if (!$order) {

            wc_add_notice(
                'Payment initialization error.',
                'error'
            );

            return ['result' => 'failure'];
        }

        $dest = WC_PLR_Rotator::get_link($order);

        if (!$dest) {

            wc_add_notice(
                'Payment provider temporarily unavailable. Please try again later.',
                'error'
            );

            return ['result' => 'failure'];
        }

        /*
        ------------------------------------------------
        Prefill Stripe fields
        ------------------------------------------------
        */

        $amount = intval($order->get_total() * 100);
        $email  = $order->get_billing_email();

        $dest = add_query_arg([
            '__prefilled_amount' => $amount,
            'prefilled_email'    => $email,
        ], $dest);

        /*
        ------------------------------------------------
        Add order info
        ------------------------------------------------
        */

        $dest = add_query_arg([
            'order_id'  => $order_id,
            'order_key' => $order->get_order_key(),
        ], $dest);

        /*
        ------------------------------------------------
        Order status
        ------------------------------------------------
        */

        $order->update_status(
            'on-hold',
            'Customer redirected to external payment provider.'
        );

        $order->save();

        /*
        ------------------------------------------------
        Reduce stock
        ------------------------------------------------
        */

        wc_reduce_stock_levels($order_id);

        /*
        ------------------------------------------------
        Clear cart
        ------------------------------------------------
        */

        if (WC()->cart) {
            WC()->cart->empty_cart();
        }

        /*
        ------------------------------------------------
        Create proxy redirect
        ------------------------------------------------
        */

        $proxy_url = WC_PLR_Proxy::make_url(
            $dest,
            $order_id
        );

        // Защита от редиректа в wp-admin (неверная настройка home_url или кэш)
        $redirect = (strpos($proxy_url, '/wp-admin') !== false)
            ? $dest
            : $proxy_url;

        // Если на боевом сайте кидает на wp-login.php — причина не в плагине: сессия не видна
        // при запросе (куки, HTTPS, домен в Настройках, кэш, плагины безопасности).
        return [
            'result'   => 'success',
            'redirect' => $redirect,
        ];
    }
}