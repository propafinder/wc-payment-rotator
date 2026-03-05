<?php
/**
 * Plugin Name: WC Payment Link Rotator
 * Description: Rotation of external payment links with a proxy page to hide the referrer.
 * Version: 1.0.3
 * Author: Degrees Team
 * Author URI: https://github.com/propafinder/wc-payment-rotator
 * Text Domain: wc-plr
 * Update URI: https://github.com/propafinder/wc-payment-rotator/
 */
defined('ABSPATH') || exit;

define('WC_PLR_PATH', plugin_dir_path(__FILE__));
define('WC_PLR_URL',  plugin_dir_url(__FILE__));

add_action('plugins_loaded', function() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>WC Payment Link Rotator</strong> требует WooCommerce.</p></div>';
        });
        return;
    }
    require WC_PLR_PATH . 'includes/class-rotator.php';
    require WC_PLR_PATH . 'includes/class-proxy.php';
    require WC_PLR_PATH . 'includes/class-logger.php';
    require WC_PLR_PATH . 'includes/class-gateway.php';
    require WC_PLR_PATH . 'includes/class-updater.php';
    require WC_PLR_PATH . 'admin/class-admin.php';

    WC_PLR_Updater::init();
    WC_PLR_Proxy::init();
    WC_PLR_Admin::init();

    add_filter('woocommerce_payment_gateways', function($gateways) {
        $gateways[] = 'WC_PLR_Gateway';
        return $gateways;
    });
});

register_activation_hook(__FILE__, function() {
    $defaults = [
        'wc_plr_links'         => [],
        'wc_plr_rotation'      => 'random',
        'wc_plr_rr_index'      => 0,
        'wc_plr_mode'          => 'redirect',
        'wc_plr_show_loading'  => '1',
        'wc_plr_loading_delay' => 8,
        'wc_plr_logging'       => '1',
        'wc_plr_title'         => 'Оплата онлайн',
        'wc_plr_description'   => 'Безопасная оплата через внешний платёжный сервис.',
    ];
    foreach ($defaults as $k => $v) {
        if (false === get_option($k)) add_option($k, $v);
    }
    require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';
    WC_PLR_Logger::create_table();
});