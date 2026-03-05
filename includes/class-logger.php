<?php
defined('ABSPATH') || exit;

class WC_PLR_Logger {

    const TABLE = 'wc_plr_log';

    public static function create_table(): void {
        global $wpdb;
        $t = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $t (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            link_url    TEXT NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            user_ip     VARCHAR(45) DEFAULT '',
            PRIMARY KEY (id),
            KEY order_id (order_id),
            KEY created_at (created_at)
        ) $charset;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function log_redirect(int $order_id, string $url): void {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            [
                'order_id'   => $order_id,
                'link_url'   => $url,
                'created_at' => current_time('mysql'),
                'user_ip'    => self::get_ip(),
            ],
            ['%d','%s','%s','%s']
        );
    }

    public static function get_stats(): array {
        global $wpdb;
        $t = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results(
            "SELECT link_url, COUNT(*) as cnt, MAX(created_at) as last_used
             FROM $t GROUP BY link_url ORDER BY cnt DESC",
            ARRAY_A
        ) ?: [];
    }

    public static function get_recent(int $limit = 50): array {
        global $wpdb;
        $t = $wpdb->prefix . self::TABLE;
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $t ORDER BY created_at DESC LIMIT %d", $limit),
            ARRAY_A
        ) ?: [];
    }

    private static function get_ip(): string {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                return sanitize_text_field(explode(',', $_SERVER[$k])[0]);
            }
        }
        return '';
    }
}