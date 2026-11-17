<?php
/**
 * Payments through Stripe Checkout (feature switch "payments", off by default).
 *
 * Nothing about cards ever touches this site. A page shows a short form made by the
 * shortcode [pay <slug>] ("Pay $25.00 for Reiki session"); pressing it POSTs to
 * /pay/<slug>/, which asks Stripe for a Checkout Session and sends the visitor to
 * Stripe's own hosted page. Stripe brings them back to /pay/thanks/, where the payment
 * is confirmed with the secret key, and tells /pay/webhook/ as well (signed), so an
 * order is recorded even when the visitor closes the tab. Orders are one JSON file
 * each in data/orders/ and the owner is emailed for every new one.
 *
 * Products are items of type "products" in content/products/ (Products screen).
 * Secrets (Stripe secret key, webhook signing secret) live in data/config.php;
 * the currency and success message in content/settings.json.
 *
 * Control panel screens live in admin/payments.php and are loaded only when needed.
 */

declare(strict_types=1);

const ORDERS_DIR = DATA_DIR . '/orders';
const STRIPE_API = 'https://api.stripe.com';
const PAY_MAX_QUANTITY = 10;
const PAY_NOTE_MAX = 500;
// Currencies Stripe counts in whole units rather than hundredths.
const ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
const CURRENCY_SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'CAD' => 'CA$', 'AUD' => 'A$', 'NZD' => 'NZ$', 'MXN' => 'MX$', 'JPY' => '¥', 'INR' => '₹', 'BRL' => 'R$'];

register_feature('payments', 'Payments', 'Take card payments on a page run by Stripe. Add products under Products, then put [pay product-address] on a page.');

/* ---------- Settings ---------- */

function payments_currency(): string
{
    $c = strtolower(trim((string) (settings()['payments_currency'] ?? 'usd')));
    return preg_match('/^[a-z]{3}$/', $c) ? $c : 'usd';
}

/** The feature is on and a secret key has been saved. */
function payments_ready(): bool
{
    return feature_on('payments') && (string) (config()['stripe_secret_key'] ?? '') !== '';
}

function payments_test_mode(): bool
{
    return str_starts_with((string) (config()['stripe_secret_key'] ?? ''), 'sk_test_');
}

/* ---------- Money ---------- */

function zero_decimal_currency(string $currency): bool
{
    return in_array(strtoupper($currency), ZERO_DECIMAL_CURRENCIES, true);
}

/** "$25.00", "€12.50", "¥500" or "25.00 CHF": an amount in the smallest unit, written for people. */
function format_money(int $amount, string $currency): string
{
    $cur = strtoupper($currency);
    $number = zero_decimal_currency($cur) ? number_format($amount) : number_format($amount / 100, 2);
    return isset(CURRENCY_SYMBOLS[$cur]) ? CURRENCY_SYMBOLS[$cur] . $number : $number . ' ' . $cur;
}

/** An amount someone typed ("25", "25.00", "$1,250.50") as the smallest unit, or null when it isn't an amount. */
function parse_money(string $text, string $currency): ?int
{
    $clean = preg_replace('/[^\d.]/', '', str_replace(',', '', trim($text)));
    if ($clean === '' || !preg_match('/^\d{1,7}(\.\d{1,2})?$/', (string) $clean)) {
        return null;
    }
    if (zero_decimal_currency($currency)) {
        return str_contains((string) $clean, '.') ? null : (int) $clean;
    }
    return (int) round(((float) $clean) * 100);
}

/* ---------- Products ---------- */

/**
 * Every product, by name. Products are written directly (not through save_item()) because
 * they are small records that don't need earlier versions kept.
 */
function products(): array
{
    if (!is_dir(CONTENT_DIR . '/products')) {
        return [];
    }
    $items = all_items('products');
    usort($items, fn($a, $b) => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $items;
}

function get_product(string $slug): ?array
{
    return in_array('products', ITEM_TYPES, true) ? get_item('products', $slug) : null;
}

function save_product(string $slug, array $data, ?string $oldSlug = null): void
{
    unset($data['slug']);
    $data['updated'] = date('c');
    write_json(item_file('products', $slug), $data);
    if ($oldSlug !== null && $oldSlug !== $slug && is_file(item_file('products', $oldSlug))) {
        unlink(item_file('products', $oldSlug));
    }
    all_items('products', true);
}

function delete_product(string $slug): void
{
    $file = item_file('products', $slug);
    if (is_file($file)) {
        unlink($file);
    }
    all_items('products', true);
}

function product_active(array $p): bool
{
    return !empty($p['active']) && (int) ($p['price'] ?? 0) > 0;
}

/* ---------- Public: the pay form ---------- */

/** The form for one product: a button that says what it costs, and the extras the product asks for. */
function render_pay_form(array $p, string $path): string
{
    if (!feature_on('payments')) {
        return '';
    }
    $slug = (string) $p['slug'];
    $id = 'pay-' . $slug;
    $cur = payments_currency();
    $out = '';
    $state = (string) ($_GET['pay'] ?? '');
    $which = (string) ($_GET['item'] ?? '');
    if ($state !== '' && ($which === '' || $which === $slug)) {
        $msg = $state === 'cancelled' ? t('pay_cancelled') : t('pay_error');
        $role = $state === 'cancelled' ? 'status' : 'alert';
        $class = $state === 'cancelled' ? 'notice' : 'error-summary';
        $out .= '<div id="' . h($id) . '-status" class="' . $class . '" role="' . $role . '" tabindex="-1" data-focus-on-load><p>' . h($msg) . '</p></div>';
    }
    if (!product_active($p) || !payments_ready()) {
        return $out . '<p id="' . h($id) . '">' . h(t('pay_unavailable')) . '</p>';
    }
    $out .= '<form id="' . h($id) . '" class="contact-form pay-form" method="post" action="/pay/' . h($slug) . '/">'
        . '<input type="hidden" name="return" value="' . h($path) . '">'
        . '<input type="hidden" name="lang" value="' . h(current_lang()) . '">';
    if (trim((string) ($p['description'] ?? '')) !== '') {
        $out .= '<p>' . nl2br(h((string) $p['description'])) . '</p>';
    }
    if (!empty($p['quantity'])) {
        $out .= '<div class="field"><label for="' . h($id) . '-qty">' . h(t('pay_quantity')) . '</label>'
            . '<input type="number" id="' . h($id) . '-qty" name="quantity" value="1" min="1" max="' . PAY_MAX_QUANTITY . '" inputmode="numeric" required></div>';
    }
    if (!empty($p['ask_note'])) {
        $out .= '<div class="field"><label for="' . h($id) . '-note">' . h(t('pay_note')) . '</label>'
            . '<p class="hint" id="' . h($id) . '-note-hint">' . h(t('pay_note_hint')) . '</p>'
            . '<textarea id="' . h($id) . '-note" name="note" rows="3" maxlength="' . PAY_NOTE_MAX . '" aria-describedby="' . h($id) . '-note-hint"></textarea></div>';
    }
    $out .= '<div class="hp-field" aria-hidden="true"><label for="' . h($id) . '-website">Leave this empty</label>'
        . '<input id="' . h($id) . '-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
        . '<p>' . h(t('pay_secure')) . '</p>'
        . '<button type="submit" class="button">' . h(sprintf(t('pay_button'), format_money((int) $p['price'], $cur), (string) $p['name'])) . '</button></form>';
    return $out;
}

/** [pay <slug>] for every product that exists, so an editor can put any of them on a page. */
function register_pay_shortcodes(): void
{
    foreach (products() as $p) {
        register_shortcode('[pay ' . $p['slug'] . ']', fn(string $path): string => render_pay_form($p, $path));
    }
}

/* ---------- Public: routes ---------- */

register_route(function (string $path, array $parts): bool {
    if (($parts[0] ?? '') !== 'pay' || count($parts) !== 2 || !feature_on('payments')) {
        return false;
    }
    if ($parts[1] === 'webhook') {
        payments_webhook();
    }
    if ($parts[1] === 'thanks') {
        payments_thanks();
    }
    if (!valid_slug($parts[1]) || !($product = get_product($parts[1]))) {
        return false;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        payments_start($product);
    }
    // A plain address for the product, for sharing in an email or a message.
    if (!product_active($product)) {
        not_found();
    }
    $html = '<h1>' . h((string) $product['name']) . '</h1>' . render_pay_form($product, $path);
    send_html(render_layout((string) $product['name'], $html, mb_substr(trim((string) ($product['description'] ?? '')), 0, 160)));
});

/** A path on this site to go back to, or the product's own page. */
function pay_return_path(string $slug): string
{
    $return = (string) ($_POST['return'] ?? '');
    return preg_match('#^/(?![/\\\\])[^\r\n\s?\#]*$#', $return) ? $return : "/pay/$slug/";
}

/** At most 20 Checkout attempts an hour from one address. */
function pay_rate_limited(): bool
{
    return rate_limited(DATA_DIR . '/pay-rate.json', hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . site_secret()), 20, 3600);
}

/** The form fields Stripe needs to open a Checkout page for this product. */
function checkout_session_fields(array $product, int $quantity, string $note, string $lang, string $returnPath): array
{
    $slug = (string) $product['slug'];
    $cur = payments_currency();
    $sep = str_contains($returnPath, '?') ? '&' : '?';
    $fields = [
        'mode' => 'payment',
        'line_items' => [[
            'quantity' => $quantity,
            'price_data' => [
                'currency' => $cur,
                'unit_amount' => (int) $product['price'],
                'product_data' => ['name' => (string) $product['name']],
            ],
        ]],
        'success_url' => site_url('/pay/thanks/') . '?s={CHECKOUT_SESSION_ID}' . (is_second_lang($lang) ? '&lang=' . $lang : ''),
        'cancel_url' => site_url($returnPath) . $sep . 'pay=cancelled&item=' . rawurlencode($slug) . '#pay-' . $slug . '-status',
        'customer_creation' => 'always',
        // Stripe shows its page in these languages; anything else lets it pick from the browser.
        'locale' => in_array($lang, ['es', 'fr', 'de', 'it', 'pt', 'nl', 'pl', 'zh', 'ja', 'ko', 'vi'], true) ? $lang : 'auto',
        'metadata' => ['product' => $slug, 'quantity' => (string) $quantity, 'lang' => $lang, 'site' => site_host()],
    ];
    if (($d = trim((string) ($product['description'] ?? ''))) !== '') {
        $fields['line_items'][0]['price_data']['product_data']['description'] = mb_substr($d, 0, 300);
    }
    if (!empty($product['collect_phone'])) {
        $fields['phone_number_collection'] = ['enabled' => 'true'];
    }
    if ($note !== '') {
        $fields['metadata']['note'] = $note;
    }
    return $fields;
}

/** POST /pay/<slug>/: ask Stripe for a Checkout page and send the visitor there. */
function payments_start(array $product): never
{
    $slug = (string) $product['slug'];
    $return = pay_return_path($slug);
    $lang = is_second_lang((string) ($_POST['lang'] ?? '')) ? second_lang() : primary_lang();
    current_lang($lang);
    $back = fn(string $state): never => redirect_303($return . (str_contains($return, '?') ? '&' : '?') . "pay=$state&item=" . rawurlencode($slug) . "#pay-$slug-status");
    if (($_POST['website'] ?? '') !== '') {
        // A bot filled in the hidden field; pretend nothing happened.
        $back('cancelled');
    }
    if (!product_active($product) || !payments_ready() || pay_rate_limited()) {
        $back('error');
    }
    $quantity = !empty($product['quantity']) ? max(1, min(PAY_MAX_QUANTITY, (int) ($_POST['quantity'] ?? 1))) : 1;
    $note = !empty($product['ask_note']) ? mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($_POST['note'] ?? ''))), 0, PAY_NOTE_MAX) : '';
    try {
        [$status, $session] = stripe_request('POST', '/v1/checkout/sessions', checkout_session_fields($product, $quantity, $note, $lang, $return));
    } catch (Throwable $e) {
        error_log('Stripe Checkout could not be started: ' . $e->getMessage());
        $back('error');
    }
    $url = (string) ($session['url'] ?? '');
    if ($status !== 200 || !str_starts_with($url, 'https://')) {
        error_log('Stripe Checkout refused the session (' . $status . '): ' . (string) ($session['error']['message'] ?? 'no details'));
        $back('error');
    }
    redirect_303($url);
}

/** A "see other" redirect to a full address (Stripe's page) or a path on this site. */
function redirect_303(string $to): never
{
    if (str_contains($to, "\r") || str_contains($to, "\n")) {
        $to = '/';
    }
    header('Location: ' . $to, true, 303);
    exit;
}

/** /pay/thanks/?s=<session id>: confirm the payment with Stripe and say thank you. */
function payments_thanks(): never
{
    if (is_second_lang((string) ($_GET['lang'] ?? ''))) {
        current_lang(second_lang());
    }
    $id = (string) ($_GET['s'] ?? '');
    $paid = false;
    $session = [];
    if (preg_match('/^cs_[A-Za-z0-9_]{10,200}$/', $id) && payments_ready()) {
        try {
            [$status, $session] = stripe_request('GET', '/v1/checkout/sessions/' . $id);
            $paid = $status === 200 && ($session['payment_status'] ?? '') === 'paid';
        } catch (Throwable $e) {
            error_log('Stripe session could not be read: ' . $e->getMessage());
        }
    }
    if ($paid) {
        record_order($session, 'thanks');
        $product = get_product((string) ($session['metadata']['product'] ?? ''));
        $name = (string) ($product['name'] ?? ($session['metadata']['product'] ?? ''));
        $custom = trim((string) (settings()['payments_success'] ?? ''));
        $html = '<h1 id="pay-status" tabindex="-1" data-focus-on-load>' . h(t('pay_thanks_title')) . '</h1>'
            . '<p>' . h(sprintf(t('pay_thanks_body'), format_money((int) ($session['amount_total'] ?? 0), (string) ($session['currency'] ?? payments_currency())), $name)) . '</p>'
            . ($custom !== '' ? '<p>' . nl2br(h($custom)) . '</p>' : '')
            . '<p><a href="' . h(home_path()) . '">' . h(t('pay_home')) . '</a></p>';
        send_html(render_layout(t('pay_thanks_title'), $html));
    }
    $html = '<h1 id="pay-status" tabindex="-1" data-focus-on-load>' . h(t('pay_not_confirmed_title')) . '</h1>'
        . '<p>' . h(t('pay_not_confirmed_body')) . '</p>'
        . '<p><a href="' . h(home_path()) . '">' . h(t('pay_home')) . '</a></p>';
    send_html(render_layout(t('pay_not_confirmed_title'), $html), 200);
}

/* ---------- Stripe API ---------- */

/**
 * One call to Stripe's API: [HTTP status, decoded JSON body]. Form-encoded, with the secret key as a Bearer token.
 * Tests only: when $GLOBALS['payments_http'] is a callable (string $method, string $url, array $fields, array $headers): array{int, string},
 * it answers instead of curl so nothing leaves the machine. The site itself never sets it.
 */
function stripe_request(string $method, string $path, array $fields = []): array
{
    $key = (string) (config()['stripe_secret_key'] ?? '');
    if ($key === '') {
        throw new RuntimeException('The Stripe secret key is not set.');
    }
    $url = STRIPE_API . $path;
    $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/x-www-form-urlencoded'];
    if ($method === 'POST') {
        $headers[] = 'Idempotency-Key: ' . bin2hex(random_bytes(16));
    }
    $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    if (isset($GLOBALS['payments_http']) && is_callable($GLOBALS['payments_http'])) {
        [$status, $text] = ($GLOBALS['payments_http'])($method, $url, $fields, $headers);
    } else {
        $ch = curl_init($method === 'GET' && $body !== '' ? "$url?$body" : $url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_USERAGENT => 'Full Circle CMS',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($text === false) {
            throw new RuntimeException('Could not reach Stripe: ' . $err);
        }
    }
    $data = json_decode((string) $text, true);
    return [(int) $status, is_array($data) ? $data : []];
}

/* ---------- Webhook ---------- */

/**
 * Check a Stripe-Signature header: "t=<unix time>,v1=<hex>[,v1=<hex>…]" where each v1 is
 * HMAC-SHA256 of "<t>.<payload>" with the signing secret. Old timestamps (over $tolerance seconds) are refused.
 */
function stripe_signature_ok(string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
{
    if ($secret === '' || $header === '') {
        return false;
    }
    $t = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($k === 't' && ctype_digit($v)) {
            $t = (int) $v;
        } elseif ($k === 'v1' && $v !== '') {
            $signatures[] = $v;
        }
    }
    if ($t === null || !$signatures || abs(($now ?? time()) - $t) > $tolerance) {
        return false;
    }
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    foreach ($signatures as $sig) {
        if (hash_equals($expected, $sig)) {
            return true;
        }
    }
    return false;
}

/** POST /pay/webhook/: Stripe tells us a Checkout finished. Only signed messages are believed. */
function payments_webhook(): never
{
    header('Content-Type: application/json');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('{"error":"POST only"}');
    }
    $payload = (string) file_get_contents('php://input');
    $secret = (string) (config()['stripe_webhook_secret'] ?? '');
    if (!stripe_signature_ok($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $secret)) {
        http_response_code(400);
        exit('{"error":"bad signature"}');
    }
    $event = json_decode($payload, true);
    if (!is_array($event)) {
        http_response_code(400);
        exit('{"error":"not json"}');
    }
    if (($event['type'] ?? '') === 'checkout.session.completed') {
        $session = (array) ($event['data']['object'] ?? []);
        if (($session['payment_status'] ?? '') === 'paid') {
            record_order($session, 'webhook');
        }
    }
    exit('{"received":true}');
}

/* ---------- Orders ---------- */

/** Save an order from a Checkout Session, once per session, and email the owner. True when it was new. */
function record_order(array $session, string $source): bool
{
    $id = (string) ($session['id'] ?? '');
    if (!preg_match('/^cs_[A-Za-z0-9_]{10,200}$/', $id)) {
        return false;
    }
    $file = ORDERS_DIR . "/$id.json";
    if (is_file($file)) {
        return false;
    }
    $meta = (array) ($session['metadata'] ?? []);
    $customer = (array) ($session['customer_details'] ?? []);
    $product = get_product((string) ($meta['product'] ?? ''));
    $order = [
        'id' => $id,
        'product' => (string) ($meta['product'] ?? ''),
        'product_name' => (string) ($product['name'] ?? ($meta['product'] ?? '')),
        'quantity' => max(1, (int) ($meta['quantity'] ?? 1)),
        'amount' => (int) ($session['amount_total'] ?? 0),
        'currency' => strtolower((string) ($session['currency'] ?? payments_currency())),
        'email' => (string) ($customer['email'] ?? ''),
        'name' => (string) ($customer['name'] ?? ''),
        'phone' => (string) ($customer['phone'] ?? ''),
        'note' => (string) ($meta['note'] ?? ''),
        'language' => (string) ($meta['lang'] ?? 'en'),
        'test' => empty($session['livemode']),
        'created' => date('c', (int) ($session['created'] ?? time())),
        'recorded_by' => $source,
        'emailed' => false,
    ];
    if (!is_dir(ORDERS_DIR)) {
        mkdir(ORDERS_DIR, 0700, true);
    }
    write_json($file, $order);
    @chmod($file, 0600);
    try {
        $money = format_money($order['amount'], $order['currency']);
        $rows = '<p><strong>Product:</strong> ' . h($order['product_name']) . ($order['quantity'] > 1 ? ' × ' . $order['quantity'] : '') . '</p>'
            . '<p><strong>Amount:</strong> ' . h($money) . ($order['test'] ? ' (test mode)' : '') . '</p>'
            . '<p><strong>Name:</strong> ' . h($order['name'] ?: '—') . '<br><strong>Email:</strong> ' . h($order['email'] ?: '—')
            . '<br><strong>Phone:</strong> ' . h($order['phone'] ?: '—') . '</p>'
            . ($order['note'] !== '' ? '<p><strong>Note:</strong><br>' . nl2br(h($order['note'])) . '</p>' : '');
        send_mail_owner('New order: ' . $order['product_name'] . ' — ' . $money,
            '<p>Someone paid on your website.</p>' . $rows . '<p>Orders are listed under Orders in the control panel. Reply to this email to answer the customer.</p>',
            $order['email'] !== '' ? $order['email'] : null, $order['name'] !== '' ? $order['name'] : null);
        $order['emailed'] = true;
        write_json($file, $order);
    } catch (Throwable $e) {
        error_log('Order email failed: ' . $e->getMessage());
    }
    return true;
}

/** Every order, newest first. */
function all_orders(): array
{
    $orders = [];
    foreach (glob(ORDERS_DIR . '/*.json') ?: [] as $file) {
        if ($o = read_json($file)) {
            $orders[] = $o;
        }
    }
    usort($orders, fn($a, $b) => strcmp((string) ($b['created'] ?? ''), (string) ($a['created'] ?? '')));
    return $orders;
}

/** Month (YYYY-MM) => ['count' => n, 'totals' => [currency => amount]], newest month first. */
function order_totals_by_month(array $orders): array
{
    $months = [];
    foreach ($orders as $o) {
        $m = substr((string) ($o['created'] ?? ''), 0, 7);
        $months[$m] ??= ['count' => 0, 'totals' => []];
        $months[$m]['count']++;
        $cur = (string) ($o['currency'] ?? 'usd');
        $months[$m]['totals'][$cur] = ($months[$m]['totals'][$cur] ?? 0) + (int) ($o['amount'] ?? 0);
    }
    krsort($months);
    return $months;
}

/* ---------- Control panel ---------- */

function payments_admin(): void
{
    require_once ROOT . '/admin/payments.php';
}

register_admin_screen('products', function (bool $post): void {
    payments_admin();
    products_screen($post);
}, 'Products', 'payments');

register_admin_screen('orders', function (bool $post): void {
    payments_admin();
    orders_screen($post);
}, 'Orders', 'payments');

register_settings_section(function (array $errors): string {
    payments_admin();
    return payments_settings_html($errors);
}, 'payments', function (): never {
    payments_admin();
    payments_settings_save();
});

register_dashboard_card(function (): string {
    if (!feature_on('payments')) {
        return '';
    }
    $month = date('Y-m');
    $orders = array_filter(all_orders(), fn($o) => str_starts_with((string) ($o['created'] ?? ''), $month));
    $totals = order_totals_by_month($orders)[$month]['totals'] ?? [];
    $words = $orders ? count($orders) . ' ' . (count($orders) === 1 ? 'order' : 'orders') . ' this month, '
        . implode(' and ', array_map(fn($cur, $amt) => format_money($amt, $cur), array_keys($totals), $totals)) . '.' : 'No orders this month.';
    return '<li><h2><a href="' . h(admin_url('orders')) . '">Orders</a></h2><p>' . h($words) . (payments_ready() ? '' : ' Payments are not set up yet.') . '</p></li>';
});

// Nothing to tidy nightly yet: orders are kept for good and the rate-limit file prunes itself.
register_cron('daily', function (): void {
    if (!is_dir(ORDERS_DIR) && feature_on('payments')) {
        mkdir(ORDERS_DIR, 0700, true);
    }
});

register_pay_shortcodes();
