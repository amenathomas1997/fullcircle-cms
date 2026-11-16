<?php
/**
 * Microsoft 365 sign-in for the control panel (OpenID Connect, authorization
 * code flow with PKCE), using the same Microsoft app as the contact form.
 * Only people on the list in Settings can sign in, so each person has their own
 * account, and Microsoft's own security (such as two-step verification) applies.
 * The site password still works, as a way in if Microsoft is unavailable.
 */

declare(strict_types=1);

function m365_redirect_uri(): string
{
    return site_url('/admin/oauth.php');
}

function m365_enabled(): bool
{
    return !empty(config()['m365_signin']) && graph_app_configured() && preg_match('/^[0-9a-f-]{36}$/i', config()['graph_tenant_id']) === 1;
}

function admin_people(): array
{
    return (array) (config()['admin_people'] ?? []);
}

function base64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

/** Send the browser to Microsoft's sign-in page. */
function m365_start(): never
{
    if (!m365_enabled()) {
        flash('Microsoft 365 sign-in isn’t turned on.', 'error');
        go(ADMIN_URL);
    }
    $verifier = base64url(random_bytes(48));
    $_SESSION['oidc'] = ['state' => bin2hex(random_bytes(16)), 'nonce' => bin2hex(random_bytes(16)), 'verifier' => $verifier, 'started' => time()];
    $c = config();
    go('https://login.microsoftonline.com/' . rawurlencode($c['graph_tenant_id']) . '/oauth2/v2.0/authorize?' . http_build_query([
        'client_id' => $c['graph_client_id'],
        'response_type' => 'code',
        'redirect_uri' => m365_redirect_uri(),
        'response_mode' => 'query',
        'scope' => 'openid profile email',
        'state' => $_SESSION['oidc']['state'],
        'nonce' => $_SESSION['oidc']['nonce'],
        'code_challenge' => base64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ]));
}

/** Microsoft sends the browser back here with a code; swap it for the person's identity and check they're allowed. */
function m365_callback(): never
{
    $fail = function (string $why): never {
        unset($_SESSION['oidc']);
        flash($why, 'error');
        go(ADMIN_URL);
    };
    $oidc = $_SESSION['oidc'] ?? null;
    if (!$oidc || !hash_equals($oidc['state'], (string) ($_GET['state'] ?? '')) || time() - $oidc['started'] > 600) {
        $fail('That sign-in had expired. Please try again.');
    }
    if (isset($_GET['error'])) {
        $fail('Microsoft didn’t complete the sign-in: ' . mb_substr((string) ($_GET['error_description'] ?? $_GET['error']), 0, 300));
    }
    $c = config();
    [$status, $json] = graph_http('POST', 'https://login.microsoftonline.com/' . rawurlencode($c['graph_tenant_id']) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id' => $c['graph_client_id'],
            'client_secret' => $c['graph_client_secret'],
            'grant_type' => 'authorization_code',
            'code' => (string) ($_GET['code'] ?? ''),
            'redirect_uri' => m365_redirect_uri(),
            'code_verifier' => $oidc['verifier'],
            'scope' => 'openid profile email',
        ]));
    if ($status !== 200 || empty($json['id_token'])) {
        $fail('Microsoft sign-in failed: ' . mb_substr((string) ($json['error_description'] ?? "HTTP $status"), 0, 300));
    }
    // The token came straight from Microsoft over a checked HTTPS connection, so its claims are
    // checked here rather than its signature (OpenID Connect Core 1.0, section 3.1.3.7).
    $parts = explode('.', (string) $json['id_token']);
    $claims = json_decode((string) base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
    $tenant = strtolower($c['graph_tenant_id']);
    if (!is_array($claims)
        || strtolower((string) ($claims['aud'] ?? '')) !== strtolower($c['graph_client_id'])
        || strtolower((string) ($claims['tid'] ?? '')) !== $tenant
        || ($claims['iss'] ?? '') !== "https://login.microsoftonline.com/$tenant/v2.0"
        || ($claims['exp'] ?? 0) < time()
        || !hash_equals($oidc['nonce'], (string) ($claims['nonce'] ?? ''))) {
        $fail('The sign-in couldn’t be checked, so it was refused. Please try again.');
    }
    $email = strtolower((string) ($claims['preferred_username'] ?? $claims['email'] ?? ''));
    $person = null;
    foreach (admin_people() as $p) {
        if (strtolower($p['email']) === $email) {
            $person = $p;
        }
    }
    if (!$person) {
        $fail("$email isn’t on the list of people who can sign in to the control panel. Ask the site owner to add you in Settings.");
    }
    unset($_SESSION['oidc']);
    begin_signed_in_session((string) ($person['name'] ?: ($claims['name'] ?? $email)), $email);
    flash('Signed in with Microsoft 365 as ' . $_SESSION['admin_name'] . '.');
    go(ADMIN_URL);
}

/** The Settings section for Microsoft 365 sign-in and the people who may use it. */
function people_settings_html(array $errors = []): string
{
    $c = config();
    $rows = '';
    foreach (admin_people() as $i => $p) {
        $rows .= '<tr><th scope="row">' . h($p['name']) . '</th><td>' . h($p['email']) . '</td><td>' . h(format_time($p['added'] ?? '')) . '</td><td>'
            . '<form method="post" action="' . h(admin_url('settings')) . '#people-heading" class="inline">' . csrf_field()
            . '<input type="hidden" name="part" value="people"><input type="hidden" name="remove" value="' . h($p['email']) . '">'
            . '<button type="submit" class="button-link">Remove<span class="visually-hidden"> ' . h($p['name']) . '</span></button></form></td></tr>';
    }
    return '<section aria-labelledby="people-heading"><h2 id="people-heading">Microsoft 365 sign-in</h2>'
        . '<p>Let people sign in to this control panel with their own Microsoft 365 account, with Microsoft’s two-step verification. Changes are then recorded with their name. '
        . 'The site password keeps working too.</p>'
        . '<p><strong>Set up once in Microsoft Entra</strong> (the app registration you use for the contact form): under Authentication, add a <strong>Web</strong> redirect address of '
        . '<code>' . h(m365_redirect_uri()) . '</code>. The sign-in uses the <em>openid</em>, <em>profile</em> and <em>email</em> permissions, which need no admin consent.</p>'
        . '<p>Status: <strong>' . (m365_enabled() ? 'On' : (graph_app_configured() ? 'Off' : 'Fill in the contact form email settings first')) . '</strong></p>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#people-heading">' . csrf_field() . '<input type="hidden" name="part" value="people">'
        . checkbox_field('m365_signin', 'Allow Microsoft 365 sign-in', !empty($c['m365_signin']))
        . '<button type="submit" class="button">Save</button></form>'
        . ($rows === '' ? '<p>No one is on the list yet.</p>'
            : '<div class="table-wrap"><table><caption>People who can sign in with Microsoft 365</caption><thead><tr><th scope="col">Name</th><th scope="col">Microsoft 365 email</th><th scope="col">Added</th><th scope="col">Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>')
        . '<h3>Add a person</h3>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#people-heading" class="narrow">' . csrf_field() . '<input type="hidden" name="part" value="people">'
        . text_field('person_name', 'Their name', '', ['autocomplete' => 'off', 'error' => $errors['person_name'] ?? null])
        . text_field('person_email', 'Their Microsoft 365 email address', '', ['type' => 'email', 'autocomplete' => 'off', 'error' => $errors['person_email'] ?? null,
            'hint' => 'The address they sign in to Microsoft with, for example name@yourcompany.com.'])
        . '<button type="submit" class="button" name="add" value="1">Add person</button></form></section>';
}

function people_settings_save(): never
{
    $people = admin_people();
    if (($remove = strtolower((string) ($_POST['remove'] ?? ''))) !== '') {
        $people = array_values(array_filter($people, fn($p) => strtolower($p['email']) !== $remove));
        save_config(['admin_people' => $people]);
        flash("Removed $remove. If they were signed in, they have been signed out.");
        go(admin_url('settings') . '#people-heading');
    }
    if (!empty($_POST['add'])) {
        $name = trim((string) ($_POST['person_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['person_email'] ?? '')));
        $errors = [];
        if ($name === '') {
            $errors['person_name'] = 'Enter the person’s name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['person_email'] = 'Enter their Microsoft 365 email address, like name@yourcompany.com.';
        } elseif (in_array($email, array_map('strtolower', array_column($people, 'email')), true)) {
            $errors['person_email'] = "$email is already on the list.";
        }
        if ($errors) {
            settings_form($errors);
        }
        $people[] = ['name' => $name, 'email' => $email, 'added' => date('c')];
        save_config(['admin_people' => $people]);
        flash("Added $name. They can sign in with Microsoft 365 once it’s turned on.");
        go(admin_url('settings') . '#people-heading');
    }
    save_config(['m365_signin' => !empty($_POST['m365_signin'])]);
    flash(!empty($_POST['m365_signin']) ? 'Microsoft 365 sign-in is on.' : 'Microsoft 365 sign-in is off.');
    go(admin_url('settings') . '#people-heading');
}

/* ---------- Next opening from Microsoft Bookings ---------- */

function bookings_settings_html(array $errors = []): string
{
    $c = config();
    $status = bookings_configured() ? next_opening_status() : null;
    $state = !graph_app_configured() ? 'Fill in the contact form email settings first.'
        : (($c['bookings_business'] ?? '') === '' ? 'Off. Add your Bookings page address below.'
        : ($status['time'] ?? null ? 'Working. Next opening: ' . (new DateTimeImmutable($status['time']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('l, F j \a\t g:i a T') . '.'
        : 'Not showing. ' . ($status['error'] !== '' ? 'Microsoft said: ' . $status['error'] : 'No opening found in the next three weeks.')));
    return '<section aria-labelledby="bookings-heading"><h2 id="bookings-heading">Next opening from Microsoft Bookings</h2>'
        . '<p>Shows “Next opening: Tuesday at 2:00 pm” wherever you put the [next-opening] shortcode, from your Microsoft Bookings calendar. '
        . 'In Microsoft Entra, give the contact form’s app registration the <strong>Bookings.Read.All</strong> application permission (Microsoft Graph) and grant admin consent.</p>'
        . '<p>Status: <strong>' . h($state) . '</strong></p>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#bookings-heading">' . csrf_field() . '<input type="hidden" name="part" value="bookings">'
        . text_field('bookings_business', 'Bookings page address', (string) ($c['bookings_business'] ?? 'Bookings@yourcompany.com'), ['type' => 'email', 'autocomplete' => 'off',
            'error' => $errors['bookings_business'] ?? null, 'hint' => 'The email address in your booking page’s link, for example Bookings@yourcompany.com.'])
        . text_field('bookings_notice_hours', 'Minimum notice, in hours', (string) ($c['bookings_notice_hours'] ?? 24), ['type' => 'number', 'inputmode' => 'numeric', 'hint' => 'Match the lead time set in Bookings.'])
        . text_field('bookings_length', 'Session length, in minutes', (string) ($c['bookings_length'] ?? 60), ['type' => 'number', 'inputmode' => 'numeric'])
        . '<div class="form-actions"><button type="submit" class="button">Save and check now</button></div></form></section>';
}

function bookings_settings_save(): never
{
    $business = trim((string) ($_POST['bookings_business'] ?? ''));
    if ($business !== '' && !filter_var($business, FILTER_VALIDATE_EMAIL)) {
        settings_form(['bookings_business' => 'Enter the Bookings page address as an email address, like Bookings@yourcompany.com.']);
    }
    save_config([
        'bookings_business' => $business,
        'bookings_notice_hours' => max(0, min(720, (int) ($_POST['bookings_notice_hours'] ?? 24))),
        'bookings_length' => max(15, min(480, (int) ($_POST['bookings_length'] ?? 60))),
    ]);
    if ($business === '') {
        flash('Saved. The next opening won’t be shown.');
    } else {
        $status = next_opening_status(true);
        flash($status['time'] ? 'Saved. The next opening is showing wherever [next-opening] is used.' : 'Saved, but no opening could be shown. ' . ($status['error'] ?: 'No opening was found in the next three weeks.'), $status['time'] ? 'success' : 'error');
    }
    go(admin_url('settings') . '#bookings-heading');
}

/* ---------- Ask a question assistant ---------- */

function assistant_settings_html(): string
{
    $c = config();
    $hasKey = ($c['anthropic_api_key'] ?? '') !== '';
    return '<section aria-labelledby="assistant-heading"><h2 id="assistant-heading">Ask a question assistant</h2>'
        . '<p>Answers visitors’ questions using Claude, on any page where you put the [ask-assistant] shortcode, from this website’s own content only. Each question costs a small amount on your Anthropic account. '
        . 'Questions aren’t stored on this website.</p>'
        . '<p>Status: <strong>' . (assistant_enabled() ? 'On' : 'Off') . '</strong></p>'
        . '<form method="post" action="' . h(admin_url('settings')) . '#assistant-heading">' . csrf_field() . '<input type="hidden" name="part" value="assistant">'
        . text_field('anthropic_api_key', 'Anthropic API key', '', ['type' => 'password', 'autocomplete' => 'off',
            'hint' => $hasKey ? 'A key is saved. Leave this empty to keep it, or paste a new one to replace it.' : 'Create one at console.anthropic.com, under API keys. It starts with sk-ant-.'])
        . checkbox_field('assistant_enabled', 'Turn on the assistant', !empty($c['assistant_enabled']))
        . '<button type="submit" class="button">Save</button></form></section>';
}
