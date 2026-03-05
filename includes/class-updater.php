<?php
/**
 * Проверка обновлений из GitHub (нативный способ WordPress 5.8+: Update URI + update_plugins_{hostname}).
 * Работает только при проверке обновлений (wp-admin или cron), не затрагивает фронт/чекаут.
 *
 * @package WC_Payment_Link_Rotator
 */

defined('ABSPATH') || exit;

class WC_PLR_Updater {

	const GITHUB_API_URL   = 'https://api.github.com/repos/propafinder/wc-payment-rotator/releases/latest';
	const PLUGIN_FILE      = 'wc-payment-rotator/wc-payment-rotator.php';
	const PLUGIN_SLUG      = 'wc-payment-rotator';
	const UPDATE_URI       = 'https://github.com/propafinder/wc-payment-rotator/';
	const CACHE_KEY        = 'wc_plr_github_release';
	const CACHE_TTL        = 43200; // 12 часов

	/**
	 * Регистрирует фильтры обновлений (только для нашего плагина).
	 */
	public static function init() {
		// Хост из Update URI: https://github.com/... → github.com
		add_filter('update_plugins_github.com', [ __CLASS__, 'filter_update_plugins' ], 10, 4);
		add_filter('plugins_api', [ __CLASS__, 'filter_plugins_api' ], 10, 3);
		// Подмешиваем обновление в кэш, если хук по hostname не сработал (разные окружения)
		add_filter('pre_set_site_transient_update_plugins', [ __CLASS__, 'inject_into_update_plugins' ], 10, 2);
	}

	/**
	 * Получает данные последнего релиза с GitHub (с кэшем).
	 *
	 * @return array|null Данные релиза или null при ошибке.
	 */
	public static function get_latest_release() {
		$cached = get_site_transient(self::CACHE_KEY);
		if (is_array($cached) && ! empty($cached['tag_name'])) {
			return $cached;
		}

		$response = wp_remote_get(self::GITHUB_API_URL, [
			'timeout'    => 10,
			'user-agent' => 'WC-Payment-Rotator-Plugin',
		]);

		if (is_wp_error($response)) {
			return null;
		}

		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			return null;
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		if (! is_array($data) || empty($data['tag_name'])) {
			return null;
		}

		set_site_transient(self::CACHE_KEY, $data, self::CACHE_TTL);
		return $data;
	}

	/**
	 * Фильтр update_plugins_{hostname}: подставляет данные обновления из GitHub.
	 * Важно: возвращаем только array или false, никогда не void (избегаем ошибок типа в ядре).
	 *
	 * @param array|false $update      Текущие данные обновления.
	 * @param array       $plugin_data Заголовки плагина.
	 * @param string      $plugin_file Путь к файлу плагина.
	 * @param array       $locales     Локали.
	 * @return array|false
	 */
	public static function filter_update_plugins($update, $plugin_data, $plugin_file, $locales) {
		if (! self::is_our_plugin($plugin_file, $plugin_data)) {
			return $update;
		}
		if (! empty($update)) {
			return $update;
		}

		$release = self::get_latest_release();
		if (! $release) {
			return $update;
		}

		$new_version = self::normalize_version($release['tag_name']);
		$current     = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0';
		if (! version_compare($current, $new_version, '<')) {
			return false;
		}

		$package = self::get_release_package_url($release);
		if (! $package) {
			return $update;
		}

		return self::build_update_object($new_version, $release, $package, $plugin_file);
	}

	/**
	 * Подмешивает наше обновление в transient update_plugins (fallback, если хук по hostname не вызывается).
	 *
	 * @param object $value    Значение transient update_plugins.
	 * @param string $transient Имя transient.
	 * @return object
	 */
	public static function inject_into_update_plugins($value, $transient) {
		if ($transient !== 'update_plugins' || ! is_object($value) || ! isset($value->response)) {
			return $value;
		}
		$release = self::get_latest_release();
		if (! $release) {
			return $value;
		}
		$new_version = self::normalize_version($release['tag_name']);
		$package     = self::get_release_package_url($release);
		if (! $package) {
			return $value;
		}
		// Найти наш плагин в get_plugins() по имени или Update URI
		$plugin_file = self::get_our_plugin_file();
		if (! $plugin_file) {
			return $value;
		}
		$plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
		$current     = isset($plugin_data['Version']) ? $plugin_data['Version'] : '0';
		if (! version_compare($current, $new_version, '<')) {
			return $value;
		}
		$value->response[ $plugin_file ] = self::build_update_object($new_version, $release, $package, $plugin_file);
		return $value;
	}

	/**
	 * Проверяет, что это наш плагин по пути или по заголовкам.
	 */
	private static function is_our_plugin($plugin_file, $plugin_data) {
		$normalized = is_string($plugin_file) ? str_replace('\\', '/', $plugin_file) : '';
		if ($normalized === self::PLUGIN_FILE) {
			return true;
		}
		if (! empty($plugin_data['Name']) && $plugin_data['Name'] === 'WC Payment Link Rotator') {
			return true;
		}
		$uri = isset($plugin_data['Update URI']) ? $plugin_data['Update URI'] : '';
		return (strpos($uri, 'github.com/propafinder/wc-payment-rotator') !== false);
	}

	/**
	 * Возвращает путь к нашему плагину (папка/файл.php) из get_plugins().
	 *
	 * @return string|null
	 */
	private static function get_our_plugin_file() {
		if (! function_exists('get_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$all = get_plugins();
		foreach ($all as $file => $data) {
			if (! empty($data['Name']) && $data['Name'] === 'WC Payment Link Rotator') {
				return $file;
			}
			if (isset($data['Update URI']) && strpos($data['Update URI'], 'github.com/propafinder/wc-payment-rotator') !== false) {
				return $file;
			}
		}
		return null;
	}

	/**
	 * Собирает объект обновления для WP (id нужен для сопоставления с Update URI).
	 *
	 * @param string $new_version
	 * @param array  $release
	 * @param string $package
	 * @param string $plugin_file
	 * @return object
	 */
	private static function build_update_object($new_version, $release, $package, $plugin_file) {
		return (object) [
			'id'          => self::UPDATE_URI,
			'slug'        => self::PLUGIN_SLUG,
			'plugin'      => $plugin_file,
			'version'     => $new_version,
			'url'         => isset($release['html_url']) ? $release['html_url'] : 'https://github.com/propafinder/wc-payment-rotator',
			'package'     => $package,
			'icons'       => [],
			'banners'     => [],
			'banners_rtl' => [],
			'requires'    => '5.6',
			'tested'      => '6.7',
			'requires_php'=> '7.4',
		];
	}

	/**
	 * Фильтр plugins_api: данные для экрана «Подробности» при обновлении.
	 *
	 * @param object|false $result Ответ API.
	 * @param string       $action Действие (plugin_information и т.д.).
	 * @param object       $args   Аргументы запроса.
	 * @return object|false
	 */
	public static function filter_plugins_api($result, $action, $args) {
		if ($action !== 'plugin_information') {
			return $result;
		}
		$slug = isset($args->slug) ? $args->slug : '';
		if ($slug !== self::PLUGIN_SLUG) {
			return $result;
		}

		$release = self::get_latest_release();
		if (! $release) {
			return $result;
		}

		$version = self::normalize_version($release['tag_name']);
		$package = self::get_release_package_url($release);

		$repo_url = 'https://github.com/propafinder/wc-payment-rotator';
		$info = (object) [
			'name'          => 'WC Payment Link Rotator',
			'slug'          => self::PLUGIN_SLUG,
			'version'       => $version,
			'author'        => 'by <a href="' . esc_url($repo_url) . '">Degrees Team</a>',
			'homepage'      => $repo_url,
			'download_link' => $package,
			'last_updated'  => isset($release['published_at']) ? $release['published_at'] : '',
			'sections'      => [
				'description' => 'Rotation of external payment links with a proxy page to hide the referrer. Updates from GitHub.',
				'changelog'   => isset($release['body']) ? $release['body'] : '',
			],
			'requires'      => '5.6',
			'tested'        => '6.7',
			'requires_php'  => '7.4',
		];

		return $info;
	}

	/**
	 * Нормализует версию из тега (убирает префикс v).
	 *
	 * @param string $tag_name Например v1.0.1.
	 * @return string
	 */
	private static function normalize_version($tag_name) {
		return is_string($tag_name) ? ltrim($tag_name, 'v') : '0';
	}

	/**
	 * Возвращает URL zip-архива из релиза (первый asset с .zip).
	 *
	 * @param array $release Ответ GitHub API.
	 * @return string|null
	 */
	private static function get_release_package_url($release) {
		if (empty($release['assets']) || ! is_array($release['assets'])) {
			return null;
		}
		foreach ($release['assets'] as $asset) {
			if (! empty($asset['browser_download_url'])) {
				$url = $asset['browser_download_url'];
				if (substr(strtolower($url), -4) === '.zip') {
					return $url;
				}
			}
		}
		return null;
	}
}
