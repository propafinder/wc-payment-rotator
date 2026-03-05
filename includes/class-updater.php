<?php
/**
 * Обновление плагина из GitHub-репозитория.
 * Показывает «Доступно обновление» в WP и устанавливает его из релиза.
 *
 * Требуется: в каждом релизе на GitHub прикрепить zip-архив плагина,
 * где в корне лежит папка wc-payment-rotator/ (как при ручной установке).
 */
defined('ABSPATH') || exit;

class WC_PLR_Updater {

    /** @var string Репозиторий в формате owner/repo */
    private $repo;

    /** @var string Текущая версия плагина */
    private $current_version;

    /** @var string Базовое имя плагина (папка/файл.php) */
    private $plugin_basename;

    /** @var string Полный путь к главному файлу плагина */
    private $plugin_file;

    /** @var string|null GitHub token для приватных репо (опционально) */
    private $token;

    /** Кэш ответа API, чтобы не дергать GitHub лишний раз */
    private static $release_cache = null;

    public function __construct(string $repo, string $current_version, string $plugin_basename, string $plugin_file, ?string $token = null) {
        $this->repo             = $repo;
        $this->current_version  = $current_version;
        $this->plugin_basename   = $plugin_basename;
        $this->plugin_file      = $plugin_file;
        $this->token            = $token;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        // Подмешиваем обновление и при чтении транзиента (если он был закэширован без нашего плагина)
        add_filter('pre_site_transient_update_plugins', [$this, 'inject_update_on_read'], 10, 1);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
    }

    /**
     * При чтении транзиента добавляем наше обновление, если его ещё нет в кэше.
     */
    public function inject_update_on_read($value) {
        if (!is_object($value) || !isset($value->response)) {
            return $value;
        }
        if (isset($value->response[$this->plugin_basename])) {
            return $value;
        }
        $release = $this->fetch_latest_release();
        if (!$release || !$this->is_newer($release['version'])) {
            return $value;
        }
        $package = $this->get_package_url($release);
        if (!$package) {
            return $value;
        }
        $value->response[$this->plugin_basename] = (object) [
            'id'            => 'wc-plr-github',
            'slug'          => 'wc-payment-rotator',
            'plugin'        => $this->plugin_basename,
            'new_version'   => $release['version'],
            'url'           => 'https://github.com/' . $this->repo,
            'package'       => $package,
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'tested'        => '',
            'requires_php'  => '',
            'compatibility' => new stdClass(),
        ];
        return $value;
    }

    /**
     * Подмешиваем данные об обновлении в транзиент update_plugins.
     */
    public function inject_update($transient) {
        if (empty($transient) || !is_object($transient)) {
            return $transient;
        }

        $release = $this->fetch_latest_release();
        if (!$release || !$this->is_newer($release['version'])) {
            return $transient;
        }

        $package = $this->get_package_url($release);
        if (!$package) {
            return $transient;
        }

        $transient->response[$this->plugin_basename] = (object) [
            'id'            => 'wc-plr-github',
            'slug'          => 'wc-payment-rotator',
            'plugin'        => $this->plugin_basename,
            'new_version'   => $release['version'],
            'url'           => 'https://github.com/' . $this->repo,
            'package'       => $package,
            'icons'         => [],
            'banners'       => [],
            'banners_rtl'   => [],
            'tested'        => '',
            'requires_php'  => '',
            'compatibility' => new stdClass(),
        ];

        return $transient;
    }

    /**
     * Ответ на plugins_api для модалки «Сведения о версии».
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== 'wc-payment-rotator') {
            return $result;
        }

        $release = $this->fetch_latest_release();
        if (!$release) {
            return $result;
        }

        $package = $this->get_package_url($release);

        return (object) [
            'name'          => 'Payment Link Rotator by Degrees',
            'slug'          => 'wc-payment-rotator',
            'version'       => $release['version'],
            'author'        => '',
            'homepage'      => 'https://github.com/' . $this->repo,
            'requires'      => '',
            'tested'        => '',
            'requires_php'  => '',
            'downloaded'    => 0,
            'last_updated'  => $release['published'],
            'sections'      => [
                'description' => wp_kses_post($release['body']),
                'changelog'   => wp_kses_post($release['body']),
            ],
            'download_link' => $package,
        ];
    }

    /**
     * Запрос к GitHub API: последний релиз.
     *
     * @return array|null ['version' => '1.1.2', 'assets' => [...], 'body' => '', 'published' => '', 'zip_asset' => url|null]
     */
    private function fetch_latest_release(): ?array {
        if (self::$release_cache !== null) {
            return is_array(self::$release_cache) ? self::$release_cache : null;
        }

        $url = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
        $args = [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WC-Payment-Link-Rotator-Plugin',
            ],
            'timeout' => 10,
        ];
        if (!empty($this->token)) {
            $args['headers']['Authorization'] = 'token ' . $this->token;
        }

        $response = wp_remote_get($url, $args);
        if (wp_remote_retrieve_response_code($response) !== 200) {
            self::$release_cache = false;
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || empty($body['tag_name'])) {
            self::$release_cache = false;
            return null;
        }

        $version = ltrim($body['tag_name'], 'v');
        $assets  = isset($body['assets']) && is_array($body['assets']) ? $body['assets'] : [];
        $zip_url = null;
        foreach ($assets as $asset) {
            $name = $asset['name'] ?? '';
            if (strtolower(substr($name, -4)) === '.zip') {
                $zip_url = $asset['browser_download_url'] ?? null;
                break;
            }
        }

        self::$release_cache = [
            'version'   => $version,
            'body'      => $body['body'] ?? '',
            'published' => isset($body['published_at']) ? $body['published_at'] : '',
            'zip_asset' => $zip_url,
            'zipball_url' => $body['zipball_url'] ?? '',
        ];

        return self::$release_cache;
    }

    private function is_newer(string $remote_version): bool {
        return version_compare($remote_version, $this->current_version, '>');
    }

    /**
     * URL архива для установки. Приоритет: прикреплённый .zip к релизу.
     */
    private function get_package_url(array $release): ?string {
        if (!empty($release['zip_asset'])) {
            return $release['zip_asset'];
        }
        // zipball_url даёт архив с папкой owner-repo-sha, не wc-payment-rotator — для корректной установки нужен asset.
        return null;
    }
}
