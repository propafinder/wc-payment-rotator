<?php
defined('ABSPATH') || exit;

/**
 * Прокладка с максимальным скрытием реферера:
 * HTTP Referrer-Policy: no-referrer + JS blob redirect
 * iframe с автоматическим fallback на redirect если провайдер блокирует
 */
class WC_PLR_Proxy {

    const EP  = 'wc-plr-go';
    const TR  = 'wc_plr_token_';
    const TTL = 1800;

    public static function init(): void {
        add_action('init',              [__CLASS__, 'add_endpoint']);
        add_action('template_redirect', [__CLASS__, 'handle']);
    }

    public static function add_endpoint(): void {
        add_rewrite_rule('^' . self::EP . '/?$', 'index.php?' . self::EP . '=1', 'top');
        add_rewrite_tag('%' . self::EP . '%', '([^&]+)');
    }

    public static function make_url(string $dest, int $order_id): string {
        $token = wp_generate_uuid4();
        set_transient(self::TR . $token, ['url' => $dest, 'order_id' => $order_id], self::TTL);
        return home_url('/' . self::EP . '/?token=' . $token);
    }

    public static function handle(): void {
        if (!get_query_var(self::EP)) return;

        $token = sanitize_text_field($_GET['token'] ?? '');
        if (!$token) wp_die('Invalid token.', 'Error', ['response' => 400]);

        $data = get_transient(self::TR . $token);
        if (!$data || empty($data['url']))
            wp_die('Link expired. Please return to the shop and try again.', 'Error', ['response' => 410]);

        delete_transient(self::TR . $token); // одноразовый токен

        $dest     = esc_url_raw($data['url']);
        $order_id = (int)($data['order_id'] ?? 0);
        // Задержка в секундах из админки (Настройки → Задержка перед редиректом)
        $delay_sec = (int) get_option('wc_plr_loading_delay', 8);
        $delay_sec = max(0, min(60, $delay_sec)); // 0–60 сек
        $delay_ms  = $delay_sec * 1000;
        $show      = get_option('wc_plr_show_loading', '1');
        $mode     = get_option('wc_plr_mode', 'redirect');

        if (get_option('wc_plr_logging', '1'))
            WC_PLR_Logger::log_redirect($order_id, $dest);

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $order_number = $order ? $order->get_order_number() : $order_id;

        // Скрываем реферер и запрещаем кэш (чтобы задержка из админки всегда актуальна)
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        if ($show !== '1') { wp_redirect($dest, 302); exit; }

        self::render($dest, $delay_ms, $mode, $order_number);
    }

    private static function render(string $url, int $ms, string $mode, $order_number): void {
        $site = esc_html(get_bloginfo('name'));
        $href = esc_url($url);
        $url_js = wp_json_encode($url);
        $orderCopyText = '#' . $order_number;
        $delay_sec = (int) round($ms / 1000);

        $rdr_title     = get_option('wc_plr_rdr_title', 'Processing Payment');
        $rdr_subtitle  = get_option('wc_plr_rdr_subtitle', 'Copy your order number and paste it on the payment page if needed. Redirect in %s sec.');
        $rdr_subtitle_before = get_option('wc_plr_rdr_subtitle_before', 'Copy your order number. After you copy, the countdown will start.');
        $rdr_order_lbl = get_option('wc_plr_rdr_order_label', 'Order number');
        $rdr_btn       = get_option('wc_plr_rdr_btn', 'Copy order number');
        $rdr_copied    = get_option('wc_plr_rdr_copied', 'Copied ✓');
        $rdr_hint      = get_option('wc_plr_rdr_hint', 'Redirect not starting?');
        $rdr_text_clr  = sanitize_hex_color(get_option('wc_plr_rdr_text_color', '#1a1a2e')) ?: '#1a1a2e';
        $rdr_bg_clr    = sanitize_hex_color(get_option('wc_plr_rdr_bg_color', '#f7f7f8')) ?: '#f7f7f8';
        $rdr_card_bg    = sanitize_hex_color(get_option('wc_plr_rdr_card_bg', '#ffffff')) ?: '#ffffff';
        $rdr_card_text_raw = get_option('wc_plr_rdr_card_text_color', '');
        $rdr_card_text  = $rdr_card_text_raw ? (sanitize_hex_color($rdr_card_text_raw) ?: $rdr_text_clr) : $rdr_text_clr;
        $rdr_card_bg_img = esc_url_raw(get_option('wc_plr_rdr_card_bg_image', ''));
        $rdr_accent    = sanitize_hex_color(get_option('wc_plr_rdr_accent', '#5b4cde')) ?: '#5b4cde';
        $rdr_bg_img    = esc_url_raw(get_option('wc_plr_rdr_bg_image', ''));
        $rdr_subtitle_rendered = sprintf($rdr_subtitle, $delay_sec);
        $rdr_subtitle_before_esc = esc_html($rdr_subtitle_before);
        $body_bg = 'background-color:' . esc_attr($rdr_bg_clr);
        if ($rdr_bg_img) {
            $body_bg .= ';background-image:url(' . esc_attr($rdr_bg_img) . ');background-size:cover;background-position:center';
        }
        $card_bg_style = 'background-color:' . esc_attr($rdr_card_bg);
        if ($rdr_card_bg_img) {
            $card_bg_style .= ';background-image:url(' . esc_attr($rdr_card_bg_img) . ');background-size:cover;background-position:center';
        }
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">
<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<title><?= esc_html($rdr_title) ?> — <?= $site ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:<?= esc_attr($rdr_text_clr) ?>;display:flex;align-items:center;justify-content:center;min-height:100vh;<?= $body_bg ?>}
.card{<?= $card_bg_style ?>;border-radius:16px;box-shadow:0 4px 32px rgba(0,0,0,.08);padding:48px 40px;text-align:center;max-width:420px;width:90%;color:<?= esc_attr($rdr_card_text) ?>}
.spinner{width:48px;height:48px;border:4px solid #e8e8f0;border-top-color:<?= esc_attr($rdr_accent) ?>;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 24px}
@keyframes spin{to{transform:rotate(360deg)}}
.card h1{font-size:1.2rem;font-weight:700;margin-bottom:8px;color:<?= esc_attr($rdr_card_text) ?>}
.card p{color:<?= esc_attr($rdr_card_text) ?>;font-size:.9rem;line-height:1.5;margin-bottom:20px;opacity:.9}
.bar-wrap{height:4px;background:#e8e8f0;border-radius:99px;overflow:hidden;margin-bottom:20px}
.bar{height:100%;width:0;background:linear-gradient(90deg,<?= esc_attr($rdr_accent) ?>,#8b7cf8)}
.bar.countdown{animation:prog var(--plr-ms) linear forwards}
@keyframes prog{from{width:0}to{width:100%}}
.hint{font-size:.8rem;opacity:.8}.hint a{color:<?= esc_attr($rdr_accent) ?>;text-decoration:none}
.order-box{background:rgba(0,0,0,.05);padding:18px;border-radius:10px;margin-bottom:20px}
.order-number{font-size:20px;font-weight:700;color:<?= esc_attr($rdr_accent) ?>;margin:10px 0}
.copy-btn{margin-top:10px;background:<?= esc_attr($rdr_accent) ?>;color:#fff;border:none;padding:10px 16px;border-radius:8px;font-size:14px;cursor:pointer}
.copy-status{font-size:12px;color:#16a34a;margin-top:6px;display:none}
#ifw{position:fixed;inset:0;background:#fff;display:none;flex-direction:column;z-index:9999}
#ifw iframe{flex:1;width:100%;border:none}
.ifh{padding:10px 16px;background:#1a1a2e;color:#fff;font-size:.85rem;display:flex;align-items:center}
.ifh a{color:#8b7cf8;font-size:.8rem;margin-left:auto}
</style>
</head>
<body>
<div id="ifw">
  <div class="ifh">🔒 Secure payment
    <a href="<?= $href ?>" target="_blank">Open in new tab</a>
  </div>
  <iframe id="ifr" src="" sandbox="allow-forms allow-scripts allow-same-origin allow-top-navigation"></iframe>
</div>
<div class="card" id="card" data-delay-sec="<?= $delay_sec ?>" data-subtitle="<?= esc_attr($rdr_subtitle) ?>">
  <div class="spinner"></div>
  <h1><?= esc_html($rdr_title) ?></h1>
  <p id="countdownText"><?= $rdr_subtitle_before_esc ?></p>
  <div class="order-box">
    <div><?= esc_html($rdr_order_lbl) ?></div>
    <div class="order-number" id="orderNum">#<?= esc_html($order_number) ?></div>
    <button type="button" class="copy-btn" id="copyBtn"><?= esc_html($rdr_btn) ?></button>
    <div class="copy-status" id="copyStatus"><?= esc_html($rdr_copied) ?></div>
  </div>
  <div class="bar-wrap"><div class="bar" id="countdownBar"></div></div>
  <div class="hint"><?= esc_html($rdr_hint) ?> <a href="<?= $href ?>">Click here</a></div>
</div>
<!-- Сначала скрипт таймера (задаёт wcPlrStartCountdown), потом копирование — иначе на мобильных таймер не стартует -->
<script>
(function(){
  var ms = <?= (int)$ms ?>;
  var url = <?= $url_js ?>;
  var mode = <?= wp_json_encode($mode) ?>;
  var card = document.getElementById("card");
  var countdownText = document.getElementById("countdownText");
  var countdownBar = document.getElementById("countdownBar");
  var subtitleTpl = card ? (card.getAttribute("data-subtitle") || "Redirect in %s sec.") : "Redirect in %s sec.";

  function blobRedirect(u){
    try{
      var html = '<scr'+'ipt>top.location.replace('+JSON.stringify(u)+')<\/sc'+'ript>';
      top.location.replace(URL.createObjectURL(new Blob([html],{type:'text/html'})));
    }catch(e){
      top.location.replace(u);
    }
  }

  function tryIframe(u){
    var w=document.getElementById('ifw'), f=document.getElementById('ifr'), c=document.getElementById('card');
    if(!w||!f){ blobRedirect(u); return; }
    var done=false;
    f.src=u;
    f.onload=function(){ if(done)return; done=true; try{ var d=f.contentDocument||f.contentWindow.document; if(!d||!d.body)throw 0; c.style.display='none'; w.style.display='flex'; }catch(e){ blobRedirect(u); } };
    f.onerror=function(){ if(!done){ done=true; blobRedirect(u); } };
    setTimeout(function(){ if(!done){ done=true; blobRedirect(u); } }, 5000);
  }

  function doRedirect(){
    if(mode==='iframe') tryIframe(url); else blobRedirect(url);
  }

  window.wcPlrStartCountdown = function(){
    if(!card || card.dataset.countdownStarted) return;
    card.dataset.countdownStarted = "1";
    var sec = parseInt(card.getAttribute("data-delay-sec"), 10) || 0;
    if(sec <= 0){ doRedirect(); return; }
    if(countdownBar){
      countdownBar.style.setProperty("--plr-ms", ms + "ms");
      countdownBar.classList.add("countdown");
    }
    var left = sec;
    function tick(){
      if(countdownText) countdownText.textContent = subtitleTpl.replace(/%s/, left);
      if(left <= 0){ doRedirect(); return; }
      left--;
      setTimeout(tick, 1000);
    }
    tick();
    setTimeout(doRedirect, ms);
  };
})();
</script>
<script>
(function copyOrder(){
  var orderCopyText = <?= json_encode($orderCopyText) ?>;
  var statusEl = document.getElementById("copyStatus");
  var card = document.getElementById("card");
  function showCopied(){
    if(statusEl){ statusEl.style.display="block"; setTimeout(function(){ statusEl.style.display="none"; },2000); }
    var startCountdown = window.wcPlrStartCountdown;
    if(startCountdown && card && !card.dataset.countdownStarted){
      setTimeout(function(){ startCountdown(); }, 0);
    }
  }
  function fallback(){
    var ta = document.createElement("textarea");
    ta.value = orderCopyText; ta.style.position="fixed"; ta.style.left="-9999px";
    document.body.appendChild(ta); ta.focus(); ta.select();
    try{ if(document.execCommand("copy")) showCopied(); }catch(e){}
    document.body.removeChild(ta);
  }
  function run(){
    if(navigator.clipboard && window.isSecureContext)
      navigator.clipboard.writeText(orderCopyText).then(showCopied).catch(fallback);
    else fallback();
  }
  var copyBtn = document.getElementById("copyBtn"), orderNum = document.getElementById("orderNum");
  if(copyBtn) copyBtn.onclick = run;
  if(orderNum) orderNum.onclick = run;
})();
</script>
</body></html>
<?php exit;
    }
}