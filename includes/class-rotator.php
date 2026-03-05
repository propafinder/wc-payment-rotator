<?php
defined('ABSPATH') || exit;

class WC_PLR_Rotator {

    public static function get_link(WC_Order $order) {
        $links    = self::get_eligible_links((float)$order->get_total());
        $rotation = get_option('wc_plr_rotation', 'random');
        if (empty($links)) return false;

        switch ($rotation) {
            case 'round_robin': return self::round_robin($links);
            case 'weighted':    return self::weighted($links);
            default:            return self::random($links);
        }
    }

    private static function get_eligible_links(float $amount): array {
        $valid = [];
        foreach (get_option('wc_plr_links', []) as $link) {
            if (empty($link['enabled'])) continue;
            $min = (float)($link['min_amount'] ?? 0);
            $max = (float)($link['max_amount'] ?? 0);
            if ($min > 0 && $amount < $min) continue;
            if ($max > 0 && $amount > $max) continue;
            $valid[] = $link;
        }
        return $valid;
    }

    private static function random(array $links): string {
        $idx = function_exists('random_int')
            ? random_int(0, count($links) - 1)
            : array_rand($links);
        return $links[$idx]['url'];
    }

    private static function round_robin(array $links): string {
        $i = (int)get_option('wc_plr_rr_index', 0) % count($links);
        update_option('wc_plr_rr_index', $i + 1);
        return $links[$i]['url'];
    }

    private static function weighted(array $links): string {
        $total = array_sum(array_column($links, 'weight'));
        if ($total <= 0) return self::random($links);
        $rand = mt_rand(1, (int)$total);
        $cum  = 0;
        foreach ($links as $link) {
            $cum += (float)($link['weight'] ?? 1);
            if ($rand <= $cum) return $link['url'];
        }
        return end($links)['url'];
    }
}