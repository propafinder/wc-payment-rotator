<?php
/**
 * Plugin Name: Payment Link Rotator by Degrees
 * Description: Ротация внешних платёжных ссылок с прокладкой для скрытия реферера
 * Version: 1.1.2
 * Text Domain: wc-plr
 */
defined('ABSPATH') || exit;

define('WC_PLR_PATH', plugin_dir_path(__FILE__));
define('WC_PLR_URL',  plugin_dir_url(__FILE__));
define('WC_PLR_VERSION', '1.1.2');

/**
 * Репозиторий для автообновления из GitHub (релизы).
 * Формат: owner/repo. Оставьте пустым, чтобы отключить проверку обновлений.
 */
if (!defined('WC_PLR_GITHUB_REPO')) {
    define('WC_PLR_GITHUB_REPO', 'propafinder/wc-payment-rotator');
}

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
    require WC_PLR_PATH . 'admin/class-admin.php';

    WC_PLR_Proxy::init();
    WC_PLR_Admin::init();

    add_filter('woocommerce_payment_gateways', function($gateways) {
        $gateways[] = 'WC_PLR_Gateway';
        return $gateways;
    });
});

// Автообновление из GitHub (релизы). Инициализация при заданном репозитории.
if (WC_PLR_GITHUB_REPO !== '' && is_admin()) {
    require_once WC_PLR_PATH . 'includes/class-updater.php';
    new WC_PLR_Updater(
        WC_PLR_GITHUB_REPO,
        WC_PLR_VERSION,
        plugin_basename(__FILE__),
        __FILE__,
        defined('WC_PLR_GITHUB_TOKEN') ? WC_PLR_GITHUB_TOKEN : null
    );
}

register_activation_hook(__FILE__, function() {
    $defaults = [
        'wc_plr_links'         => [],
        'wc_plr_rotation'      => 'random',
        'wc_plr_rr_index'      => 0,
        'wc_plr_mode'          => 'redirect',
        'wc_plr_show_loading'  => '1',
        'wc_plr_loading_delay' => 2,
        'wc_plr_logging'       => '1',
        'wc_plr_title'         => 'Оплата онлайн',
        'wc_plr_description'   => 'Безопасная оплата через внешний платёжный сервис.',
    ];
    foreach ($defaults as $k => $v) {
        if (false === get_option($k)) add_option($k, $v);
    }
    require_once plugin_dir_path(__FILE__) . 'includes/class-logger.php';
    WC_PLR_Logger::create_table();
    // Сброс правил перезаписи, чтобы эндпоинт прокси /wc-plr-go/ работал сразу после активации
    require_once plugin_dir_path(__FILE__) . 'includes/class-proxy.php';
    WC_PLR_Proxy::add_endpoint();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function() {
    flush_rewrite_rules();
});