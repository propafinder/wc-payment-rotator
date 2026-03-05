<?php
defined('ABSPATH') || exit;

class WC_PLR_Admin {

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_post_wc_plr_save', [__CLASS__, 'save_settings']);
        add_action('admin_post_wc_plr_add_link', [__CLASS__, 'add_link']);
        add_action('admin_post_wc_plr_delete_link', [__CLASS__, 'delete_link']);
        add_action('admin_post_wc_plr_toggle_link', [__CLASS__, 'toggle_link']);
    }

    public static function enqueue_assets($hook): void {
        if ($hook !== 'woocommerce_page_wc-plr-settings') return;
        wp_enqueue_media();
        wp_add_inline_script('jquery', "
jQuery(function($){
  $('#wc_plr_rdr_pick_bg').on('click', function(){
    var i = $('#wc_plr_rdr_bg_image');
    var f = wp.media({ library: { type: 'image' }, multiple: false });
    f.on('select', function(){ var a = f.state().get('selection').first(); if (a) i.val(a.get('url')); });
    f.open();
  });
  $('#wc_plr_rdr_clear_bg').on('click', function(){ $('#wc_plr_rdr_bg_image').val(''); });
  $('#wc_plr_rdr_pick_card_bg').on('click', function(){
    var i = $('#wc_plr_rdr_card_bg_image');
    var f = wp.media({ library: { type: 'image' }, multiple: false });
    f.on('select', function(){ var a = f.state().get('selection').first(); if (a) i.val(a.get('url')); });
    f.open();
  });
  $('#wc_plr_rdr_clear_card_bg').on('click', function(){ $('#wc_plr_rdr_card_bg_image').val(''); });
});
");
    }

    public static function add_menu(): void {
        add_submenu_page(
            'woocommerce',
            'Payment Link Rotator',
            '🔄 Link Rotator',
            'manage_woocommerce',
            'wc-plr-settings',
            [__CLASS__, 'render_page']
        );
    }

    /* ---------------------------------------------------------------- */
    /*  Render                                                            */
    /* ---------------------------------------------------------------- */
    public static function render_page(): void {
        $links    = get_option('wc_plr_links', []);
        $rotation = get_option('wc_plr_rotation', 'random');
        $mode     = get_option('wc_plr_mode', 'redirect');
        $show     = get_option('wc_plr_show_loading', '1');
        $delay    = (int)get_option('wc_plr_loading_delay', 8);
        $logging  = get_option('wc_plr_logging', '1');
        $title    = get_option('wc_plr_title', 'Оплата онлайн');
        $desc     = get_option('wc_plr_description', '');
        $rdr_title     = get_option('wc_plr_rdr_title', 'Processing Payment');
        $rdr_subtitle  = get_option('wc_plr_rdr_subtitle', 'Copy your order number and paste it on the payment page if needed. Redirect in %s sec.');
        $rdr_subtitle_before = get_option('wc_plr_rdr_subtitle_before', 'Copy your order number. After you copy, the countdown will start.');
        $rdr_order_lbl = get_option('wc_plr_rdr_order_label', 'Order number');
        $rdr_btn       = get_option('wc_plr_rdr_btn', 'Copy order number');
        $rdr_copied    = get_option('wc_plr_rdr_copied', 'Copied ✓');
        $rdr_hint      = get_option('wc_plr_rdr_hint', 'Redirect not starting?');
        $rdr_text_clr  = get_option('wc_plr_rdr_text_color', '#1a1a2e');
        $rdr_bg_clr    = get_option('wc_plr_rdr_bg_color', '#f7f7f8');
        $rdr_card_bg      = get_option('wc_plr_rdr_card_bg', '#ffffff');
        $rdr_card_text    = get_option('wc_plr_rdr_card_text_color', '');
        $rdr_card_bg_img  = get_option('wc_plr_rdr_card_bg_image', '');
        $rdr_accent    = get_option('wc_plr_rdr_accent', '#5b4cde');
        $rdr_bg_img    = get_option('wc_plr_rdr_bg_image', '');
        $stats    = WC_PLR_Logger::get_stats();
        $recent   = WC_PLR_Logger::get_recent(20);

        // Map stats by url for quick lookup
        $stat_map = [];
        foreach ($stats as $s) $stat_map[$s['link_url']] = $s;

        if (isset($_GET['saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div>';
        }
        ?>
<div class="wrap" style="max-width:900px">
<h1>🔄 WC Payment Link Rotator</h1>

<!-- TABS -->
<h2 class="nav-tab-wrapper">
  <a href="#tab-links"    class="nav-tab nav-tab-active" onclick="showTab('links',this)">Ссылки</a>
  <a href="#tab-settings" class="nav-tab" onclick="showTab('settings',this)">Настройки</a>
  <a href="#tab-log"      class="nav-tab" onclick="showTab('log',this)">Логи</a>
</h2>

<!-- ===================== LINKS TAB ===================== -->
<div id="tab-links">
<h2>Платёжные ссылки</h2>

<!-- Add link form -->
<form method="post" action="<?= admin_url('admin-post.php') ?>" style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:4px;margin-bottom:20px">
  <?php wp_nonce_field('wc_plr_add_link') ?>
  <input type="hidden" name="action" value="wc_plr_add_link">
  <table class="form-table">
    <tr>
      <th><label>Название</label></th>
      <td><input type="text" name="label" class="regular-text" placeholder="Stripe" required></td>
    </tr>
    <tr>
      <th><label>URL платёжной ссылки</label></th>
      <td><input type="url" name="url" class="large-text" placeholder="https://buy.stripe.com/..." required></td>
    </tr>
    <tr>
      <th><label>Вес (для weighted ротации)</label></th>
      <td><input type="number" name="weight" value="25" min="1" max="100" style="width:80px"> %
      <span class="description">Используется только при режиме "С весами"</span></td>
    </tr>
    <tr>
      <th><label>Лимит суммы заказа</label></th>
      <td>
        От <input type="number" name="min_amount" value="0" min="0" step="0.01" style="width:100px">
        до <input type="number" name="max_amount" value="0" min="0" step="0.01" style="width:100px">
        <span class="description">0 = без ограничений. Для случайного выбора среди всех ссылок оставьте 0 у всех.</span>
      </td>
    </tr>
  </table>
  <button type="submit" class="button button-primary">➕ Добавить ссылку</button>
</form>

<!-- Links table -->
<?php if (empty($links)): ?>
  <p><em>Ссылок пока нет. Добавьте первую выше.</em></p>
<?php else: ?>
<table class="widefat striped" style="margin-bottom:20px">
  <thead>
    <tr>
      <th>Название</th><th>URL</th><th>Вес</th><th>Лимит суммы</th>
      <th>Использований</th><th>Последнее</th><th>Статус</th><th>Действия</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($links as $i => $link):
    $stat   = $stat_map[$link['url']] ?? null;
    $cnt    = $stat ? $stat['cnt'] : 0;
    $last   = $stat ? $stat['last_used'] : '—';
    $active = !empty($link['enabled']);
    $min    = $link['min_amount'] ?? 0;
    $max    = $link['max_amount'] ?? 0;
  ?>
    <tr>
      <td><strong><?= esc_html($link['label']) ?></strong></td>
      <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <a href="<?= esc_url($link['url']) ?>" target="_blank"><?= esc_html($link['url']) ?></a>
      </td>
      <td><?= (int)($link['weight'] ?? 1) ?>%</td>
      <td><?= $min > 0 || $max > 0 ? esc_html("$min — $max") : 'Нет' ?></td>
      <td><strong><?= (int)$cnt ?></strong></td>
      <td><?= esc_html($last) ?></td>
      <td>
        <?php if ($active): ?>
          <span style="color:green">● Активна</span>
        <?php else: ?>
          <span style="color:#999">○ Выкл.</span>
        <?php endif; ?>
      </td>
      <td>
        <!-- Toggle -->
        <form method="post" action="<?= admin_url('admin-post.php') ?>" style="display:inline">
          <?php wp_nonce_field('wc_plr_toggle_link') ?>
          <input type="hidden" name="action" value="wc_plr_toggle_link">
          <input type="hidden" name="index" value="<?= $i ?>">
          <button type="submit" class="button button-small">
            <?= $active ? '⏸ Выкл' : '▶ Вкл' ?>
          </button>
        </form>
        <!-- Delete -->
        <form method="post" action="<?= admin_url('admin-post.php') ?>" style="display:inline"
              onsubmit="return confirm('Удалить эту ссылку?')">
          <?php wp_nonce_field('wc_plr_delete_link') ?>
          <input type="hidden" name="action" value="wc_plr_delete_link">
          <input type="hidden" name="index" value="<?= $i ?>">
          <button type="submit" class="button button-small button-link-delete">🗑 Удалить</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>

<!-- ===================== SETTINGS TAB ===================== -->
<div id="tab-settings" style="display:none">
<h2>Настройки</h2>
<form method="post" action="<?= admin_url('admin-post.php') ?>">
  <?php wp_nonce_field('wc_plr_save') ?>
  <input type="hidden" name="action" value="wc_plr_save">
  <table class="form-table">
    <tr>
      <th>Название способа оплаты</th>
      <td><input type="text" name="wc_plr_title" value="<?= esc_attr($title) ?>" class="regular-text">
      <p class="description">Что видит покупатель в чекауте</p></td>
    </tr>
    <tr>
      <th>Описание</th>
      <td><textarea name="wc_plr_description" rows="3" class="large-text"><?= esc_textarea($desc) ?></textarea></td>
    </tr>
    <tr>
      <th>Алгоритм ротации</th>
      <td>
        <select name="wc_plr_rotation">
          <option value="random"      <?= selected($rotation,'random',false) ?>>Случайный (random)</option>
          <option value="round_robin" <?= selected($rotation,'round_robin',false) ?>>По очереди (round-robin)</option>
          <option value="weighted"    <?= selected($rotation,'weighted',false) ?>>С весами (weighted)</option>
        </select>
        <p class="description">Веса задаются для каждой ссылки отдельно в таблице выше</p>
      </td>
    </tr>
    <tr>
      <th>Режим отображения</th>
      <td>
        <select name="wc_plr_mode">
          <option value="redirect" <?= selected($mode,'redirect',false) ?>>Редирект (надёжно)</option>
          <option value="iframe"   <?= selected($mode,'iframe',false) ?>>iFrame (с fallback на редирект)</option>
        </select>
      </td>
    </tr>
    <tr>
      <th>Страница «Обработка платежа»</th>
      <td>
        <label><input type="checkbox" name="wc_plr_show_loading" value="1" <?= checked($show,'1',false) ?>>
        Показывать промежуточную страницу с прогресс-баром</label>
      </td>
    </tr>
    <tr>
      <th>Задержка перед редиректом</th>
      <td>
        <input type="number" name="wc_plr_loading_delay" value="<?= $delay ?>" min="0" max="60" style="width:70px"> sec
        <p class="description">0 = мгновенный редирект (только при включённой странице выше)</p>
      </td>
    </tr>
    <tr>
      <th>Логирование</th>
      <td>
        <label><input type="checkbox" name="wc_plr_logging" value="1" <?= checked($logging,'1',false) ?>>
        Логировать переходы по ссылкам</label>
      </td>
    </tr>
  </table>

  <h3 style="margin-top:24px">Оформление окна редиректа</h3>
  <p class="description">Текст, цвета и фон страницы с номером заказа и кнопкой «Копировать».</p>
  <table class="form-table">
    <tr>
      <th><label>Заголовок</label></th>
      <td><input type="text" name="wc_plr_rdr_title" value="<?= esc_attr($rdr_title) ?>" class="regular-text"></td>
    </tr>
    <tr>
      <th><label>Подзаголовок до копирования</label></th>
      <td><input type="text" name="wc_plr_rdr_subtitle_before" value="<?= esc_attr($rdr_subtitle_before) ?>" class="large-text">
      <p class="description">Показывается до нажатия «Копировать». После копирования начнётся обратный отсчёт.</p></td>
    </tr>
    <tr>
      <th><label>Подзаголовок при отсчёте</label></th>
      <td><input type="text" name="wc_plr_rdr_subtitle" value="<?= esc_attr($rdr_subtitle) ?>" class="large-text">
      <p class="description">Во время обратного отсчёта. Вместо числа секунд подставится <code>%s</code></p></td>
    </tr>
    <tr>
      <th><label>Подпись «Номер заказа»</label></th>
      <td><input type="text" name="wc_plr_rdr_order_label" value="<?= esc_attr($rdr_order_lbl) ?>" class="regular-text"></td>
    </tr>
    <tr>
      <th><label>Текст кнопки</label></th>
      <td><input type="text" name="wc_plr_rdr_btn" value="<?= esc_attr($rdr_btn) ?>" class="regular-text"></td>
    </tr>
    <tr>
      <th><label>Текст «Скопировано»</label></th>
      <td><input type="text" name="wc_plr_rdr_copied" value="<?= esc_attr($rdr_copied) ?>" class="regular-text"></td>
    </tr>
    <tr>
      <th><label>Подсказка (если не редиректит)</label></th>
      <td><input type="text" name="wc_plr_rdr_hint" value="<?= esc_attr($rdr_hint) ?>" class="large-text"></td>
    </tr>
    <tr>
      <th><label>Цвет текста</label></th>
      <td><input type="text" name="wc_plr_rdr_text_color" value="<?= esc_attr($rdr_text_clr) ?>" class="small-text" placeholder="#1a1a2e"></td>
    </tr>
    <tr>
      <th><label>Цвет фона страницы</label></th>
      <td><input type="text" name="wc_plr_rdr_bg_color" value="<?= esc_attr($rdr_bg_clr) ?>" class="small-text" placeholder="#f7f7f8"></td>
    </tr>
    <tr>
      <th><label>Цвет фона карточки</label></th>
      <td><input type="text" name="wc_plr_rdr_card_bg" value="<?= esc_attr($rdr_card_bg) ?>" class="small-text" placeholder="#ffffff">
      <p class="description">Фон самого модального окна (номер заказа, кнопка)</p></td>
    </tr>
    <tr>
      <th><label>Цвет текста в карточке</label></th>
      <td><input type="text" name="wc_plr_rdr_card_text_color" value="<?= esc_attr($rdr_card_text) ?>" class="small-text" placeholder="#1a1a2e">
      <p class="description">Если пусто — используется цвет текста страницы</p></td>
    </tr>
    <tr>
      <th><label>Фон карточки (изображение)</label></th>
      <td>
        <input type="url" name="wc_plr_rdr_card_bg_image" id="wc_plr_rdr_card_bg_image" value="<?= esc_attr($rdr_card_bg_img) ?>" class="large-text">
        <button type="button" class="button" id="wc_plr_rdr_pick_card_bg">Выбрать</button>
        <button type="button" class="button" id="wc_plr_rdr_clear_card_bg">Удалить</button>
      </td>
    </tr>
    <tr>
      <th><label>Акцент (кнопка, ссылки, номер)</label></th>
      <td><input type="text" name="wc_plr_rdr_accent" value="<?= esc_attr($rdr_accent) ?>" class="small-text" placeholder="#5b4cde"></td>
    </tr>
    <tr>
      <th><label>Фон страницы (изображение)</label></th>
      <td>
        <input type="url" name="wc_plr_rdr_bg_image" id="wc_plr_rdr_bg_image" value="<?= esc_attr($rdr_bg_img) ?>" class="large-text">
        <button type="button" class="button" id="wc_plr_rdr_pick_bg">Выбрать изображение</button>
        <button type="button" class="button" id="wc_plr_rdr_clear_bg">Удалить</button>
        <p class="description">URL картинки. Растягивается на весь фон.</p>
      </td>
    </tr>
  </table>
  <?php submit_button('Сохранить настройки') ?>
</form>

<!-- Flush rewrite rules reminder -->
<div class="notice notice-info" style="margin-top:20px">
  <p>⚠️ После первой активации плагина перейдите в
  <strong>Настройки → Постоянные ссылки</strong> и нажмите «Сохранить»
  для обновления правил rewrite.</p>
</div>
</div>

<!-- ===================== LOG TAB ===================== -->
<div id="tab-log" style="display:none">
<h2>Статистика использования</h2>
<?php if (empty($stats)): ?>
  <p><em>Логов пока нет.</em></p>
<?php else: ?>
<h3>По ссылкам</h3>
<table class="widefat striped" style="margin-bottom:30px">
  <thead><tr><th>Ссылка</th><th>Использований</th><th>Последнее</th></tr></thead>
  <tbody>
  <?php foreach ($stats as $s): ?>
    <tr>
      <td><?= esc_html($s['link_url']) ?></td>
      <td><strong><?= (int)$s['cnt'] ?></strong></td>
      <td><?= esc_html($s['last_used']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<h3>Последние 20 переходов</h3>
<table class="widefat striped">
  <thead><tr><th>ID заказа</th><th>Ссылка</th><th>IP</th><th>Время</th></tr></thead>
  <tbody>
  <?php foreach ($recent as $r): ?>
    <tr>
      <td><a href="<?= esc_url(admin_url('post.php?post='.(int)$r['order_id'].'&action=edit')) ?>">#<?= (int)$r['order_id'] ?></a></td>
      <td><?= esc_html($r['link_url']) ?></td>
      <td><?= esc_html($r['user_ip']) ?></td>
      <td><?= esc_html($r['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
</div>

</div><!-- .wrap -->

<script>
function showTab(id, el) {
  event.preventDefault();
  ['links','settings','log'].forEach(function(t){
    document.getElementById('tab-'+t).style.display = t===id ? '' : 'none';
  });
  document.querySelectorAll('.nav-tab').forEach(function(a){ a.classList.remove('nav-tab-active'); });
  el.classList.add('nav-tab-active');
}
</script>
<?php
    }

    /* ---------------------------------------------------------------- */
    /*  Actions                                                           */
    /* ---------------------------------------------------------------- */
    public static function save_settings(): void {
        check_admin_referer('wc_plr_save');
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');

        update_option('wc_plr_title',         sanitize_text_field($_POST['wc_plr_title'] ?? ''));
        update_option('wc_plr_description',   sanitize_textarea_field($_POST['wc_plr_description'] ?? ''));
        update_option('wc_plr_rotation',      sanitize_text_field($_POST['wc_plr_rotation'] ?? 'random'));
        update_option('wc_plr_mode',          sanitize_text_field($_POST['wc_plr_mode'] ?? 'redirect'));
        update_option('wc_plr_show_loading',  isset($_POST['wc_plr_show_loading']) ? '1' : '0');
        update_option('wc_plr_loading_delay', max(0, min(60, (int)($_POST['wc_plr_loading_delay'] ?? 8))));
        update_option('wc_plr_logging',       isset($_POST['wc_plr_logging']) ? '1' : '0');
        update_option('wc_plr_rdr_title',     sanitize_text_field($_POST['wc_plr_rdr_title'] ?? ''));
        update_option('wc_plr_rdr_subtitle',  sanitize_text_field($_POST['wc_plr_rdr_subtitle'] ?? ''));
        update_option('wc_plr_rdr_subtitle_before', sanitize_text_field($_POST['wc_plr_rdr_subtitle_before'] ?? ''));
        update_option('wc_plr_rdr_order_label', sanitize_text_field($_POST['wc_plr_rdr_order_label'] ?? ''));
        update_option('wc_plr_rdr_btn',       sanitize_text_field($_POST['wc_plr_rdr_btn'] ?? ''));
        update_option('wc_plr_rdr_copied',    sanitize_text_field($_POST['wc_plr_rdr_copied'] ?? ''));
        update_option('wc_plr_rdr_hint',      sanitize_text_field($_POST['wc_plr_rdr_hint'] ?? ''));
        update_option('wc_plr_rdr_text_color', sanitize_text_field($_POST['wc_plr_rdr_text_color'] ?? '#1a1a2e'));
        update_option('wc_plr_rdr_bg_color',   sanitize_text_field($_POST['wc_plr_rdr_bg_color'] ?? '#f7f7f8'));
        update_option('wc_plr_rdr_card_bg',   sanitize_text_field($_POST['wc_plr_rdr_card_bg'] ?? '#ffffff'));
        update_option('wc_plr_rdr_card_text_color', sanitize_text_field($_POST['wc_plr_rdr_card_text_color'] ?? ''));
        update_option('wc_plr_rdr_card_bg_image',    esc_url_raw($_POST['wc_plr_rdr_card_bg_image'] ?? ''));
        update_option('wc_plr_rdr_accent',    sanitize_text_field($_POST['wc_plr_rdr_accent'] ?? '#5b4cde'));
        update_option('wc_plr_rdr_bg_image',  esc_url_raw($_POST['wc_plr_rdr_bg_image'] ?? ''));

        wp_redirect(admin_url('admin.php?page=wc-plr-settings&saved=1'));
        exit;
    }

    public static function add_link(): void {
        check_admin_referer('wc_plr_add_link');
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');

        $links = get_option('wc_plr_links', []);
        $links[] = [
            'id'         => uniqid('link_', true),
            'label'      => sanitize_text_field($_POST['label'] ?? ''),
            'url'        => esc_url_raw($_POST['url'] ?? ''),
            'weight'     => max(1, (int)($_POST['weight'] ?? 25)),
            'min_amount' => max(0, (float)($_POST['min_amount'] ?? 0)),
            'max_amount' => max(0, (float)($_POST['max_amount'] ?? 0)),
            'enabled'    => true,
        ];
        update_option('wc_plr_links', $links);

        wp_redirect(admin_url('admin.php?page=wc-plr-settings&saved=1'));
        exit;
    }

    public static function delete_link(): void {
        check_admin_referer('wc_plr_delete_link');
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');

        $links = get_option('wc_plr_links', []);
        $i     = (int)($_POST['index'] ?? -1);
        if (isset($links[$i])) {
            array_splice($links, $i, 1);
            update_option('wc_plr_links', array_values($links));
        }
        wp_redirect(admin_url('admin.php?page=wc-plr-settings'));
        exit;
    }

    public static function toggle_link(): void {
        check_admin_referer('wc_plr_toggle_link');
        if (!current_user_can('manage_woocommerce')) wp_die('Forbidden');

        $links = get_option('wc_plr_links', []);
        $i     = (int)($_POST['index'] ?? -1);
        if (isset($links[$i])) {
            $links[$i]['enabled'] = empty($links[$i]['enabled']);
            update_option('wc_plr_links', $links);
        }
        wp_redirect(admin_url('admin.php?page=wc-plr-settings'));
        exit;
    }
}