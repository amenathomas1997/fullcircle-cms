<?php
/**
 * Control panel screens for payments: Products, Orders and the Payments section of Settings.
 * Loaded by lib/features/payments.php only when one of them is shown.
 */

declare(strict_types=1);

/* =====================================================================
   Products
   ===================================================================== */

function products_screen(bool $post): never
{
    if ($post) {
        if (isset($_GET['delete'])) {
            product_delete((string) $_GET['delete']);
        }
        product_save_screen();
    }
    if (isset($_GET['delete'])) {
        product_delete_confirm((string) $_GET['delete']);
    }
    if (isset($_GET['slug']) || isset($_GET['new'])) {
        product_form();
    }
    products_list();
}

function products_list(): never
{
    $cur = payments_currency();
    $rows = '';
    foreach (products() as $p) {
        $name = h((string) $p['name']);
        $rows .= '<tr><th scope="row">' . $name . '</th><td>' . h(format_money((int) $p['price'], $cur)) . '</td>'
            . '<td>' . (product_active($p) ? 'Active' : 'Not for sale') . '</td>'
            . '<td><code>[pay ' . h($p['slug']) . ']</code></td>'
            . '<td class="actions"><a href="' . h(admin_url('products', ['slug' => $p['slug']])) . '">Edit<span class="visually-hidden"> ' . $name . '</span></a>'
            . ' <a href="/pay/' . h($p['slug']) . '/">View<span class="visually-hidden"> ' . $name . '</span></a>'
            . ' <a href="' . h(admin_url('products', ['delete' => $p['slug']])) . '">Delete<span class="visually-hidden"> ' . $name . '</span></a></td></tr>';
    }
    $body = '<p>A product is anything someone can pay for: a session, a class, a deposit. Put its shortcode on any page to show a pay button there; the paying itself happens on a secure page run by Stripe.</p>'
        . (payments_ready() ? '' : '<div class="notice notice-error"><p>Payments are not set up yet. <a href="' . h(admin_url('settings')) . '#payments-heading">Add your Stripe secret key in Settings</a> before pay buttons will work.</p></div>')
        . (payments_ready() && payments_test_mode() ? '<p><strong>Test mode:</strong> the saved key starts with sk_test, so no real money moves. Use Stripe’s test card 4242 4242 4242 4242 with any future date.</p>' : '')
        . '<p><a class="button" href="' . h(admin_url('products', ['new' => 1])) . '">Add a product</a></p>';
    $body .= $rows === '' ? '<p>There are no products yet.</p>'
        : '<div class="table-wrap"><table><caption>All products, by name</caption><thead><tr><th scope="col">Name</th><th scope="col">Price</th><th scope="col">Status</th><th scope="col">Shortcode</th><th scope="col">Actions</th></tr></thead>'
            . "<tbody>$rows</tbody></table></div>";
    admin_screen('Products', $body, 'products');
}

function product_form(?array $values = null, array $errors = [], ?string $origSlug = null): never
{
    $origSlug ??= isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null && valid_slug($origSlug) ? get_product($origSlug) : null;
    if ($origSlug !== null && !$existing && $values === null) {
        flash('That product could not be found.', 'error');
        go(admin_url('products'));
    }
    $cur = payments_currency();
    $v = $values ?? ($existing ?? ['name' => '', 'slug' => '', 'price' => 0, 'description' => '', 'active' => true, 'quantity' => false, 'ask_note' => false, 'collect_phone' => false]);
    $isNew = $existing === null;
    $priceText = $values !== null ? (string) ($values['price_text'] ?? '') : ((int) ($v['price'] ?? 0) > 0 ? (zero_decimal_currency($cur) ? (string) $v['price'] : number_format(((int) $v['price']) / 100, 2, '.', '')) : '');
    $body = '<form method="post" action="' . h(admin_url('products', $isNew ? [] : ['slug' => $origSlug])) . '">' . csrf_field()
        . text_field('name', 'Name', (string) ($v['name'] ?? ''), ['required' => true, 'error' => $errors['name'] ?? null, 'hint' => 'What the visitor is paying for, as it should read on the button and on Stripe’s page. For example “Reiki session”.'])
        . text_field('price', 'Price', $priceText, ['required' => true, 'inputmode' => 'decimal', 'autocomplete' => 'off', 'error' => $errors['price'] ?? null,
            'hint' => 'In ' . h(strtoupper($cur)) . (zero_decimal_currency($cur) ? ', whole units only, for example 2500.' : ', for example 25 or 25.50.')])
        . textarea_field('description', 'Description (optional)', (string) ($v['description'] ?? ''), ['rows' => 3, 'hint' => 'A sentence or two shown above the pay button and on Stripe’s page.'])
        . checkbox_field('active', 'For sale now', !empty($v['active']), ['hint' => 'Untick to hide the pay button without deleting the product.'])
        . '<h2>Options</h2>'
        . checkbox_field('quantity', 'Let people choose a quantity (1 to ' . PAY_MAX_QUANTITY . ')', !empty($v['quantity']))
        . checkbox_field('ask_note', 'Ask for a note', !empty($v['ask_note']), ['hint' => 'An optional box for anything they want to tell you, such as a preferred day.'])
        . checkbox_field('collect_phone', 'Collect a phone number', !empty($v['collect_phone']), ['hint' => 'Stripe asks for it on the payment page.'])
        . text_field('slug', 'Address', (string) ($v['slug'] ?? ''), ['prefix' => site_url('/pay/'), 'spellcheck' => 'false', 'autocomplete' => 'off', 'error' => $errors['slug'] ?? null,
            'hint' => 'Also the word in the shortcode, [pay address]. Leave it empty to make one from the name.'])
        . '<div class="form-actions"><button type="submit" class="button">Save product</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('products')) . '">Cancel</a></div></form>';
    if (!$isNew) {
        $body .= '<h2>Use it</h2><p>Put <code>[pay ' . h((string) $origSlug) . ']</code> on any page, or link straight to <a href="/pay/' . h((string) $origSlug) . '/">' . h(site_url('/pay/' . $origSlug . '/')) . '</a>.</p>'
            . '<p><a href="' . h(admin_url('products', ['delete' => $origSlug])) . '">Delete this product</a></p>';
    }
    admin_screen($isNew ? 'Add a product' : 'Edit product: ' . h((string) $existing['name']), $body, 'products', ['errors' => $errors]);
}

function product_save_screen(): never
{
    $origSlug = isset($_GET['slug']) ? (string) $_GET['slug'] : null;
    $existing = $origSlug !== null && valid_slug($origSlug) ? get_product($origSlug) : null;
    $cur = payments_currency();
    $priceText = trim((string) ($_POST['price'] ?? ''));
    $v = [
        'name' => trim((string) ($_POST['name'] ?? '')),
        'slug' => strtolower(trim((string) ($_POST['slug'] ?? ''))),
        'price' => parse_money($priceText, $cur) ?? 0,
        'description' => trim(str_replace("\r\n", "\n", (string) ($_POST['description'] ?? ''))),
        'active' => !empty($_POST['active']),
        'quantity' => !empty($_POST['quantity']),
        'ask_note' => !empty($_POST['ask_note']),
        'collect_phone' => !empty($_POST['collect_phone']),
    ];
    if ($v['slug'] === '') {
        $v['slug'] = slugify($v['name']);
    }
    $errors = [];
    if ($v['name'] === '') {
        $errors['name'] = 'Enter a name.';
    } elseif (mb_strlen($v['name']) > 120) {
        $errors['name'] = 'The name must be 120 characters or fewer.';
    }
    if ($priceText === '' || parse_money($priceText, $cur) === null || $v['price'] <= 0) {
        $errors['price'] = zero_decimal_currency($cur) ? 'Enter the price as a whole number, for example 2500.' : 'Enter the price as a number, for example 25 or 25.50.';
    }
    if (!valid_slug($v['slug']) || in_array($v['slug'], ['thanks', 'webhook'], true)) {
        $errors['slug'] = 'The address can only have lowercase letters, numbers and dashes, and cannot be “thanks” or “webhook”.';
    } elseif ($v['slug'] !== $origSlug && get_product($v['slug'])) {
        $errors['slug'] = 'Another product already has that address.';
    }
    if ($errors) {
        product_form($v + ['price_text' => $priceText], $errors, $existing ? $origSlug : null);
    }
    if ($existing) {
        $v += ['created' => $existing['created'] ?? date('c')];
    } else {
        $v['created'] = date('c');
    }
    save_product($v['slug'], $v, $existing ? $origSlug : null);
    flash('Saved “' . $v['name'] . '”. Put [pay ' . $v['slug'] . '] on a page to show its pay button.');
    go(admin_url('products'));
}

function product_delete_confirm(string $slug): never
{
    $p = valid_slug($slug) ? get_product($slug) : null;
    if (!$p) {
        not_found_screen();
    }
    $body = '<p>Are you sure you want to delete “' . h((string) $p['name']) . '”? Its pay button will disappear from any page that uses [pay ' . h($slug) . ']. Orders already made are kept.</p>'
        . '<form method="post" action="' . h(admin_url('products', ['delete' => $slug])) . '" class="form-actions">' . csrf_field()
        . '<button type="submit" class="button button-danger">Yes, delete this product</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('products', ['slug' => $slug])) . '">No, go back</a></form>';
    admin_screen('Delete product: ' . h((string) $p['name']), $body, 'products');
}

function product_delete(string $slug): never
{
    $p = valid_slug($slug) ? get_product($slug) : null;
    if (!$p) {
        not_found_screen();
    }
    delete_product($slug);
    flash('Deleted “' . $p['name'] . '”.');
    go(admin_url('products'));
}

/* =====================================================================
   Orders
   ===================================================================== */

function orders_screen(bool $post): never
{
    $orders = all_orders();
    if (isset($_GET['csv'])) {
        orders_csv($orders);
    }
    $months = '';
    foreach (order_totals_by_month($orders) as $month => $m) {
        $label = date('F Y', strtotime($month . '-01')) ?: $month;
        $totals = implode(', ', array_map(fn($cur, $amt) => format_money($amt, $cur), array_keys($m['totals']), $m['totals']));
        $months .= '<tr><th scope="row">' . h($label) . '</th><td>' . $m['count'] . '</td><td>' . h($totals) . '</td></tr>';
    }
    $rows = '';
    foreach ($orders as $o) {
        $who = h((string) ($o['name'] ?? '')) . (($o['email'] ?? '') !== '' ? '<br><a href="mailto:' . h($o['email']) . '">' . h($o['email']) . '</a>' : '');
        $rows .= '<tr><th scope="row">' . h(format_time((string) ($o['created'] ?? ''))) . '</th>'
            . '<td>' . h((string) ($o['product_name'] ?? '')) . ((int) ($o['quantity'] ?? 1) > 1 ? ' × ' . (int) $o['quantity'] : '') . '</td>'
            . '<td>' . h(format_money((int) ($o['amount'] ?? 0), (string) ($o['currency'] ?? 'usd'))) . (!empty($o['test']) ? ' <span class="tag">test</span>' : '') . '</td>'
            . '<td>' . ($who !== '' ? $who : '—') . '</td>'
            . '<td>' . h((string) ($o['phone'] ?? '') ?: '—') . '</td>'
            . '<td>' . nl2br(h((string) ($o['note'] ?? ''))) . (empty($o['emailed']) ? '<br><em>Not emailed to you</em>' : '') . '</td></tr>';
    }
    $body = '<p>Every payment made through Stripe Checkout, newest first. Refunds are done in your Stripe dashboard.</p>';
    if (!$orders) {
        $body .= '<p>No orders yet.</p>';
    } else {
        $body .= '<p><a class="button button-secondary" href="' . h(admin_url('orders', ['csv' => 1])) . '">Download all orders as a spreadsheet (CSV)</a></p>'
            . '<h2>Totals by month</h2><div class="table-wrap"><table><caption>Orders and totals for each month, newest first</caption>'
            . '<thead><tr><th scope="col">Month</th><th scope="col">Orders</th><th scope="col">Total</th></tr></thead><tbody>' . $months . '</tbody></table></div>'
            . '<h2>All orders</h2><div class="table-wrap"><table><caption>All orders, newest first</caption>'
            . '<thead><tr><th scope="col">Paid on</th><th scope="col">Product</th><th scope="col">Amount</th><th scope="col">Customer</th><th scope="col">Phone</th><th scope="col">Note</th></tr></thead>'
            . '<tbody>' . $rows . '</tbody></table></div>';
    }
    admin_screen('Orders', $body, 'orders');
}

function orders_csv(array $orders): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    // The explicit separator, enclosure and (empty) escape keep the output the same on every PHP version.
    fputcsv($out, ['Paid on', 'Product', 'Quantity', 'Amount', 'Currency', 'Name', 'Email', 'Phone', 'Note', 'Test', 'Stripe session'], ',', '"', '');
    foreach ($orders as $o) {
        $cur = (string) ($o['currency'] ?? 'usd');
        $amount = zero_decimal_currency($cur) ? (string) (int) ($o['amount'] ?? 0) : number_format(((int) ($o['amount'] ?? 0)) / 100, 2, '.', '');
        // A leading = + - or @ would be run as a formula by spreadsheets; a space in front stops that.
        $safe = fn($v) => preg_match('/^[=+\-@]/', (string) $v) ? ' ' . $v : (string) $v;
        fputcsv($out, [$o['created'] ?? '', $safe($o['product_name'] ?? ''), (int) ($o['quantity'] ?? 1), $amount, strtoupper($cur),
            $safe($o['name'] ?? ''), $safe($o['email'] ?? ''), $safe($o['phone'] ?? ''), $safe($o['note'] ?? ''), !empty($o['test']) ? 'yes' : 'no', $o['id'] ?? ''], ',', '"', '');
    }
    fclose($out);
    exit;
}

/* =====================================================================
   Settings section
   ===================================================================== */

function payments_settings_html(array $errors): string
{
    $c = config();
    $s = settings();
    $keyHint = ($c['stripe_secret_key'] ?? '') !== ''
        ? 'A key is saved' . (payments_test_mode() ? ' (a test key)' : '') . '. Leave this empty to keep it, or paste a new one to replace it.'
        : 'From Developers, then API keys, in your Stripe dashboard. It starts with sk_test_ or sk_live_.';
    $whHint = ($c['stripe_webhook_secret'] ?? '') !== ''
        ? 'A signing secret is saved. Leave this empty to keep it, or paste a new one to replace it.'
        : 'From Developers, then Webhooks, after you add the address above. It starts with whsec_.';
    return '<section aria-labelledby="payments-heading" id="payments"><h2 id="payments-heading">Payments</h2>'
        . '<p>Card payments happen on a secure page run by Stripe; card numbers never reach this website. You need a Stripe account (stripe.com). '
        . 'Status: <strong>' . (feature_on('payments') ? (payments_ready() ? 'Set up' . (payments_test_mode() ? ', in test mode' : '') : 'Turned on, but no secret key yet') : 'Turned off under Features') . '</strong>.</p>'
        . '<p><strong>Test mode:</strong> a key that starts with <code>sk_test_</code> lets you try everything without real money. Pay with card number 4242 4242 4242 4242, any future expiry date and any three digits. '
        . 'When you are ready, swap in the key that starts with <code>sk_live_</code> and add a live webhook.</p>'
        . '<p><strong>Webhook address</strong> to add in Stripe (event <code>checkout.session.completed</code>): <code>' . h(site_url('/pay/webhook/')) . '</code>. '
        . 'It lets Stripe tell the site about a payment even when the customer closes the page before coming back.</p>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#payments-heading">' . csrf_field() . '<input type="hidden" name="part" value="payments">'
        . text_field('stripe_secret_key', 'Stripe secret key', '', ['type' => 'password', 'autocomplete' => 'off', 'hint' => $keyHint, 'error' => $errors['stripe_secret_key'] ?? null])
        . text_field('stripe_webhook_secret', 'Webhook signing secret', '', ['type' => 'password', 'autocomplete' => 'off', 'hint' => $whHint, 'error' => $errors['stripe_webhook_secret'] ?? null])
        . text_field('payments_currency', 'Currency', strtoupper(payments_currency()), ['spellcheck' => 'false', 'autocomplete' => 'off', 'maxlength' => 3, 'error' => $errors['payments_currency'] ?? null,
            'hint' => 'The three-letter code, for example USD, CAD or EUR. It must be one your Stripe account can charge in.'])
        . textarea_field('payments_success', 'Message after a successful payment', (string) ($s['payments_success'] ?? ''), ['rows' => 3,
            'hint' => 'Shown on the thank-you page under “Thank you, your payment went through”. For example what happens next, or how to book a time.'])
        . '<button type="submit" class="button">Save payment settings</button></form></section>';
}

function payments_settings_save(): never
{
    $errors = [];
    $key = trim((string) ($_POST['stripe_secret_key'] ?? ''));
    $wh = trim((string) ($_POST['stripe_webhook_secret'] ?? ''));
    $currency = strtolower(trim((string) ($_POST['payments_currency'] ?? 'usd')));
    if ($key !== '' && !preg_match('/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,}$/', $key)) {
        $errors['stripe_secret_key'] = 'That does not look like a Stripe secret key. It starts with sk_test_ or sk_live_.';
    }
    if ($wh !== '' && !preg_match('/^whsec_[A-Za-z0-9]{10,}$/', $wh)) {
        $errors['stripe_webhook_secret'] = 'That does not look like a webhook signing secret. It starts with whsec_.';
    }
    if (!preg_match('/^[a-z]{3}$/', $currency)) {
        $errors['payments_currency'] = 'Enter a three-letter currency code, for example USD.';
    }
    if ($errors) {
        settings_form($errors);
    }
    $changes = [];
    if ($key !== '') {
        $changes['stripe_secret_key'] = $key;
    }
    if ($wh !== '') {
        $changes['stripe_webhook_secret'] = $wh;
    }
    if ($changes) {
        save_config($changes);
    }
    $s = settings();
    $s['payments_currency'] = $currency;
    $s['payments_success'] = trim(str_replace("\r\n", "\n", (string) ($_POST['payments_success'] ?? '')));
    save_settings($s);
    flash('Payment settings saved.' . (payments_ready() ? (payments_test_mode() ? ' You are in test mode: no real money moves.' : '') : (feature_on('payments') ? ' Add a secret key to start taking payments.' : ' Turn on Payments under Features to use them.')));
    go(admin_url('settings') . '#payments-heading');
}
