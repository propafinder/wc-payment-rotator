<?php
defined('ABSPATH') || exit;

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

        set_transient(self::TR . $token, [
            'url'      => $dest,
            'order_id' => $order_id
        ], self::TTL);

        // Всегда используем фронтовой URL (home_url), чтобы редирект не уходил в wp-admin
        $url = home_url('/' . self::EP . '/?token=' . $token);
        return $url;
    }

    public static function handle(): void {

        if (!get_query_var(self::EP)) return;

        $token = sanitize_text_field($_GET['token'] ?? '');

        if (!$token) {
            wp_die('Invalid token.', 'Error', ['response' => 400]);
        }

        $data = get_transient(self::TR . $token);

        if (!$data || empty($data['url'])) {
            wp_die('Link expired. Please return to the shop and try again.', 'Error', ['response' => 410]);
        }

        delete_transient(self::TR . $token);

        $dest     = esc_url_raw($data['url']);
        $order_id = (int)($data['order_id'] ?? 0);

        $delay_ms = (int)get_option('wc_plr_loading_delay', 8) * 1000;
        $show     = get_option('wc_plr_show_loading', '1');

        if (get_option('wc_plr_logging', '1')) {
            WC_PLR_Logger::log_redirect($order_id, $dest);
        }

        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        $order_number = $order ? $order->get_order_number() : $order_id;

        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: no-store, no-cache');

        if ($show !== '1') {
            wp_safe_redirect($dest, 302);
            exit;
        }

        self::render($dest, $delay_ms, $order_number);
    }

    private static function render(string $url, int $ms, $order_number): void {

        $site = esc_html(get_bloginfo('name'));
        $href = esc_url($url);
        $url_js = wp_json_encode($url); // правильная передача URL в JS
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="referrer" content="no-referrer">

<title>Processing Payment — <?= $site ?></title>

<style>

*{box-sizing:border-box;margin:0;padding:0}

body{
font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
background:#f7f7f8;
display:flex;
align-items:center;
justify-content:center;
min-height:100vh
}

.card{
background:#fff;
border-radius:16px;
box-shadow:0 4px 32px rgba(0,0,0,.08);
padding:48px 40px;
text-align:center;
max-width:420px;
width:90%
}

.spinner{
width:48px;height:48px;
border:4px solid #e8e8f0;
border-top-color:#5b4cde;
border-radius:50%;
animation:spin .8s linear infinite;
margin:0 auto 24px
}

@keyframes spin{to{transform:rotate(360deg)}}

h1{
font-size:1.2rem;
font-weight:700;
margin-bottom:8px;
color:#1a1a2e
}

p{
color:#6b7280;
font-size:.9rem;
line-height:1.5;
margin-bottom:20px
}

.order-box{
background:#f3f4f6;
padding:18px;
border-radius:10px;
margin-bottom:20px
}

.order-number{
font-size:20px;
font-weight:700;
color:#5b4cde;
margin:10px 0
}

.copy-btn{
margin-top:10px;
background:#5b4cde;
color:#fff;
border:none;
padding:10px 16px;
border-radius:8px;
font-size:14px;
cursor:pointer
}

.copy-btn:active{
transform:scale(.96)
}

.copy-status{
font-size:12px;
color:#16a34a;
margin-top:6px;
display:none
}

.bar-wrap{
height:4px;
background:#e8e8f0;
border-radius:99px;
overflow:hidden;
margin-bottom:20px
}

.bar{
height:100%;
background:linear-gradient(90deg,#5b4cde,#8b7cf8);
animation:prog <?= $ms ?>ms linear forwards
}

@keyframes prog{from{width:0}to{width:100%}}

.hint{
font-size:.8rem;
color:#9ca3af
}

.hint a{
color:#5b4cde;
text-decoration:none
}

</style>
</head>

<body>

<div class="card">

<div class="spinner"></div>

<h1>Processing Payment</h1>

<p>
Copy your order number and paste it on the payment page.
</p>

<div class="order-box">

<div>Order number</div>

<div class="order-number" id="orderNum">
#<?= esc_html($order_number) ?>
</div>

<button class="copy-btn" id="copyBtn">
COPY ORDER NUMBER
</button>

<div class="copy-status" id="copyStatus">
Copied ✓
</div>

</div>

<div class="bar-wrap"><div class="bar"></div></div>

<div class="hint">
If redirect does not start
<a href="<?= $href ?>">click here</a>
</div>

</div>

<script>

var order="<?= esc_js($order_number) ?>";

function copyOrder(){

navigator.clipboard.writeText(order);

document.getElementById("copyStatus").style.display="block";

setTimeout(function(){
document.getElementById("copyStatus").style.display="none";
},2000);

}

document.getElementById("copyBtn").onclick=copyOrder;
document.getElementById("orderNum").onclick=copyOrder;

(function(){

var ms = <?= (int)$ms ?>;
var url = <?= $url_js ?>;

function blobRedirect(u){
try{
var html='<scr'+'ipt>top.location.replace("'+u+'")<\/sc'+'ript>';
top.location.replace(URL.createObjectURL(new Blob([html],{type:'text/html'})));
}catch(e){
top.location.replace(u);
}
}

setTimeout(function(){
blobRedirect(url);
}, ms);

})();

</script>

</body>
</html>

<?php
exit;
}

}