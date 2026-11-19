<?php
/**
 * Control panel screens for accounts: the People and Activity screens (owner), My account
 * (everyone: password and two-factor sign-in), and the sign-in screens that work before anyone
 * is signed in (forgotten password, set a password from an emailed link, the two-factor code,
 * and the one-time "Create your owner account" step on older sites with a shared site password).
 * Loaded by lib/features/accounts.php; uses the helpers in admin-lib.php.
 */

declare(strict_types=1);

/* =====================================================================
   Signing in
   ===================================================================== */

/** The sign-in screens that work without being signed in. Returns when $action isn't one of them. */
function accounts_guest_screen(string $action, bool $post): void
{
    match ($action) {
        'forgot' => forgot_password_screen($post),
        'set_password' => set_password_screen($post),
        'twofactor' => two_factor_screen($post),
        'signin_code' => function_exists('signin_code_screen') ? signin_code_screen($post) : null,
        'create_owner' => create_owner_screen($post),
        default => null,
    };
}

/** Sign-in forms carry the session's token; the session exists before anyone signs in. */
function guest_csrf_ok(): bool
{
    return hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''));
}

/** Only addresses inside the control panel, so a sign-in link can't send anyone elsewhere. */
function safe_next(string $next): string
{
    return str_starts_with($next, ADMIN_URL) && !str_contains($next, "\n") ? $next : ADMIN_URL;
}

/** After a correct email and password: straight in, or on to the two-factor screen, or on to the emailed-code screen on a new browser. */
function accounts_continue_sign_in(array $user, string $next): never
{
    if (($user['totp_secret'] ?? '') !== '') {
        $_SESSION['pending_2fa'] = ['id' => $user['id'], 'at' => time(), 'next' => safe_next($next)];
        go(admin_url('twofactor'));
    }
    // Two-step sign-in by emailed code (lib/features/signin-codes.php).
    if (function_exists('signin_code_needed') && signin_code_needed($user)) {
        signin_code_begin($user, safe_next($next));
    }
    accounts_sign_in($user);
    go(safe_next($next));
}

/** The second step of sign-in for people with two-factor turned on: a code from their app, or a recovery code. */
function two_factor_screen(bool $post): never
{
    $pending = $_SESSION['pending_2fa'] ?? null;
    $user = $pending ? find_user((string) $pending['id']) : null;
    if (!$user || time() - ($pending['at'] ?? 0) > 600) {
        unset($_SESSION['pending_2fa']);
        flash('Please sign in again, starting with your email address and password.', 'error');
        go(ADMIN_URL);
    }
    $errors = [];
    if ($post) {
        $code = trim((string) ($_POST['code'] ?? ''));
        if (login_locked()) {
            $errors['code'] = 'Too many wrong codes. For security, sign-in is paused for 15 minutes.';
        } elseif (!guest_csrf_ok()) {
            $errors['code'] = 'That form had expired. Please try again.';
        } elseif (second_factor_ok($user, $code)) {
            accounts_sign_in($user);
            if (!totp_verify((string) $user['totp_secret'], $code)) {
                $left = count((array) (find_user((string) $user['id'])['recovery_codes'] ?? []));
                flash("You signed in with a recovery code. You have $left left. If you have lost your authenticator app, turn two-factor sign-in off and on again under My account to get a new secret and new codes.");
            }
            go((string) $pending['next']);
        } else {
            record_failed_login();
            activity_log('failed sign-in', 'wrong two-factor code', $user);
            $errors['code'] = 'That code is not right. Open your authenticator app and type the current six-digit code, or one of your recovery codes.';
        }
    }
    $body = '<p>Hello ' . h((string) $user['name']) . '. Your account has two-factor sign-in turned on.</p>'
        . '<form method="post" action="' . h(admin_url('twofactor')) . '" class="narrow">' . csrf_field()
        . text_field('code', 'Code from your authenticator app', '', ['required' => true, 'autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'spellcheck' => 'false',
            'error' => $errors['code'] ?? null, 'hint' => 'The six-digit code the app shows for ' . h(site_name()) . ' right now. Lost the app? Type one of your recovery codes instead.'])
        . '<button type="submit" class="button">Finish signing in</button></form>'
        . '<p><a href="' . h(ADMIN_URL) . '">Start over</a></p>';
    admin_screen('Enter your sign-in code', $body, '', ['errors' => $errors]);
}

/** Ask for an email address and send a link. The answer is the same whether or not the address has an account. */
function forgot_password_screen(bool $post): never
{
    $errors = [];
    $email = normalize_email((string) ($_POST['email'] ?? ''));
    if ($post) {
        if (!guest_csrf_ok()) {
            $errors['email'] = 'That form had expired. Please try again.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter your email address in the right format, like name@example.com.';
        } elseif (rate_limited(DATA_DIR . '/password-reset-rate.json', 'ip:' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 5, 3600)
            || rate_limited(DATA_DIR . '/password-reset-rate.json', 'email:' . hash('sha256', $email), 3, 3600)) {
            $errors['email'] = 'Too many requests. Please wait an hour and try again, or ask the site owner to reset your password on the People screen.';
        } elseif (!mail_ready()) {
            flash('Email isn’t set up on this website yet, so a link can’t be sent. Ask the person who hosts the site to run php tools/set-password.php, or ask the site owner to reset your password on the People screen.', 'error');
            go(ADMIN_URL);
        } else {
            if ($user = find_user_by_email($email)) {
                try {
                    send_password_link($user, 'reset');
                    activity_log('asked for a password link', '', $user);
                } catch (Throwable $e) {
                    error_log('Password link email failed: ' . $e->getMessage());
                }
            }
            flash('If that address has an account, we’ve emailed it a link to set a new password. The link works for one hour.');
            go(ADMIN_URL);
        }
    }
    $body = '<p>Type the email address you sign in with and we’ll email you a link to choose a new password.</p>'
        . '<form method="post" action="' . h(admin_url('forgot')) . '" class="narrow">' . csrf_field()
        . text_field('email', 'Email address', $email, ['type' => 'email', 'required' => true, 'autocomplete' => 'username', 'spellcheck' => 'false', 'error' => $errors['email'] ?? null])
        . '<button type="submit" class="button">Email me a link</button></form>'
        . '<p><a href="' . h(ADMIN_URL) . '">Back to sign in</a></p>';
    admin_screen('Forgot your password', $body, '', ['errors' => $errors]);
}

/** Choose a password from an emailed link (an invitation or a reset). */
function set_password_screen(bool $post): never
{
    $token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
    $record = password_token_record($token);
    $user = $record ? find_user((string) $record['user_id']) : null;
    if (!$user) {
        admin_screen('This link has expired', '<p>Set-password links work once and for one hour, and this one is no longer valid.</p>'
            . '<p><a href="' . h(admin_url('forgot')) . '">Ask for a new link</a>, or <a href="' . h(ADMIN_URL) . '">go to the sign-in screen</a>.</p>');
    }
    $errors = [];
    if ($post) {
        $pw = (string) ($_POST['password'] ?? '');
        if (!guest_csrf_ok()) {
            $errors['password'] = 'That form had expired. Please try again.';
        } elseif (($why = password_problem($pw)) !== null) {
            $errors['password'] = $why;
        } elseif ($pw !== ($_POST['confirm'] ?? '')) {
            $errors['confirm'] = 'The two passwords do not match. Type the same password in both boxes.';
        } else {
            set_user_password((string) $user['id'], $pw);
            delete_password_token($token);
            @unlink(LOGIN_LOCK_FILE);
            activity_log('set a new password', $record['purpose'] === 'invite' ? 'from an invitation' : 'from an emailed link', $user);
            flash('Your password is set. Sign in with ' . $user['email'] . ' and your new password.');
            go(ADMIN_URL);
        }
    }
    $body = '<p>Hello ' . h((string) $user['name']) . '. Choose the password you will use to sign in as <strong>' . h((string) $user['email']) . '</strong>.</p>'
        . '<form method="post" action="' . h(admin_url('set_password')) . '" class="narrow">' . csrf_field() . '<input type="hidden" name="t" value="' . h($token) . '">'
        . '<input type="text" name="username" value="' . h((string) $user['email']) . '" autocomplete="username" aria-label="Email address" hidden>'
        . text_field('password', 'New password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'error' => $errors['password'] ?? null,
            'hint' => 'At least 12 characters. A short sentence you will remember works well.'])
        . text_field('confirm', 'Type the password again', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'error' => $errors['confirm'] ?? null])
        . '<button type="submit" class="button">Save my password</button></form>';
    admin_screen('Choose your password', $body, '', ['errors' => $errors]);
}

/**
 * On a site that only had a shared site password: the first person to sign in with it creates the
 * owner account, and the site password is retired.
 */
function create_owner_screen(bool $post): never
{
    if (owner_count() > 0 || config()['password_hash'] === '' || time() - (int) ($_SESSION['legacy_ok'] ?? 0) > 900) {
        unset($_SESSION['legacy_ok']);
        go(ADMIN_URL);
    }
    $v = ['name' => trim((string) ($_POST['name'] ?? '')), 'email' => normalize_email((string) ($_POST['email'] ?? contact_email()))];
    $errors = [];
    if ($post) {
        $pw = (string) ($_POST['password'] ?? '');
        if (!guest_csrf_ok()) {
            $errors['name'] = 'That form had expired. Please try again.';
        }
        if ($v['name'] === '') {
            $errors['name'] = 'Enter your name. It is shown next to the changes you make.';
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter your email address in the right format, like name@example.com.';
        }
        if (($why = password_problem($pw)) !== null) {
            $errors['password'] = $why;
        } elseif ($pw !== ($_POST['confirm'] ?? '')) {
            $errors['confirm'] = 'The two passwords do not match. Type the same password in both boxes.';
        }
        if (!$errors) {
            $user = create_user($v['name'], $v['email'], $pw, 'owner');
            save_config(['password_hash' => '']);
            accounts_sign_in($user);
            activity_log('created the owner account', 'replacing the shared site password', $user);
            flash('Your owner account is ready and the shared site password has been retired. From now on, sign in with your email address and this password. Add other people on the People screen.');
            go(ADMIN_URL);
        }
    }
    $body = '<p>The control panel now has named accounts instead of one shared password, so changes are recorded with the name of the person who made them and each person can have their own role. '
        . 'You signed in with the site password, so you become the owner. Choose your own sign-in details; the shared password stops working after this.</p>'
        . '<form method="post" action="' . h(admin_url('create_owner')) . '" class="narrow">' . csrf_field()
        . text_field('name', 'Your name', $v['name'], ['required' => true, 'autocomplete' => 'name', 'error' => $errors['name'] ?? null])
        . text_field('email', 'Your email address', $v['email'], ['type' => 'email', 'required' => true, 'autocomplete' => 'username', 'spellcheck' => 'false', 'error' => $errors['email'] ?? null,
            'hint' => 'You will sign in with this, and password links are sent to it.'])
        . text_field('password', 'New password', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'error' => $errors['password'] ?? null,
            'hint' => 'At least 12 characters. It can be the same as the old site password if you like.'])
        . text_field('confirm', 'Type the password again', '', ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'error' => $errors['confirm'] ?? null])
        . '<button type="submit" class="button">Create my owner account</button></form>';
    admin_screen('Create your owner account', $body, '', ['errors' => $errors]);
}

/* =====================================================================
   People (owner)
   ===================================================================== */

function role_radio(string $value): string
{
    $options = [];
    foreach (ROLE_LABELS as $role => $label) {
        $options[$role] = $label . ': ' . ROLE_HINTS[$role];
    }
    return radio_field('role', 'Role', $options, $value);
}

/** A link the owner can pass on by hand, kept for one screen after an invitation or reset when email isn't working. */
function pending_link_html(): string
{
    $pending = $_SESSION['people_link'] ?? null;
    unset($_SESSION['people_link']);
    if (!$pending) {
        return '';
    }
    return '<section aria-labelledby="link-heading" class="notice" id="pending-link" tabindex="-1"><h2 id="link-heading">Give this link to ' . h($pending['name']) . '</h2>'
        . '<p>' . h($pending['why']) . ' Copy the link below and send it to them yourself. It works once and for one hour.</p>'
        . '<div class="field"><label for="pending-link-url">Set-password link for ' . h($pending['email']) . '</label>'
        . '<input type="text" id="pending-link-url" value="' . h($pending['link']) . '" readonly spellcheck="false"></div></section>';
}

function people_screen(): never
{
    if (isset($_GET['id'])) {
        person_screen((string) $_GET['id']);
    }
    $me = current_user();
    $rows = '';
    foreach (users() as $u) {
        $name = h((string) $u['name']);
        $rows .= '<tr><th scope="row">' . $name . ($u['id'] === ($me['id'] ?? '') ? ' <span class="tag">You</span>' : '') . '</th>'
            . '<td>' . h((string) $u['email']) . '</td><td>' . h(role_label((string) $u['role'])) . '</td>'
            . '<td>' . (($u['totp_secret'] ?? '') !== '' ? 'On' : 'Off') . '</td>'
            . '<td>' . ($u['last_sign_in'] ? h(format_time((string) $u['last_sign_in'])) : 'Never') . '</td>'
            . '<td class="actions"><a href="' . h(admin_url('people', ['id' => $u['id']])) . '">Edit<span class="visually-hidden"> ' . $name . '</span></a></td></tr>';
    }
    $errors = $GLOBALS['people_errors'] ?? [];
    $v = $GLOBALS['people_values'] ?? ['name' => '', 'email' => '', 'role' => 'editor'];
    $body = pending_link_html()
        . '<p>Everyone who can sign in to this control panel, and what they can do. Each person signs in with their own email address and password'
        . (m365_enabled() ? ', or with Microsoft 365 if their account email matches' : '') . '.</p>'
        . '<div class="table-wrap"><table><caption>People with an account</caption>'
        . '<thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Two-factor</th><th scope="col">Last signed in</th><th scope="col">Actions</th></tr></thead>'
        . "<tbody>$rows</tbody></table></div>"
        . '<h2 id="invite-heading">Invite a person</h2>'
        . '<p>They get an email with a link to choose their password. ' . (mail_ready() ? '' : '<strong>Email isn’t set up yet</strong>, so the link will be shown to you here to pass on. ') . 'You can change their role later.</p>'
        . '<form method="post" action="' . h(admin_url('people')) . '" class="narrow">' . csrf_field() . '<input type="hidden" name="do" value="invite">'
        . text_field('name', 'Their name', (string) $v['name'], ['required' => true, 'autocomplete' => 'off', 'error' => $errors['name'] ?? null])
        . text_field('email', 'Their email address', (string) $v['email'], ['type' => 'email', 'required' => true, 'autocomplete' => 'off', 'spellcheck' => 'false', 'error' => $errors['email'] ?? null,
            'hint' => 'They sign in with this.' . (m365_enabled() ? ' For Microsoft 365 sign-in, use their Microsoft 365 address.' : '')])
        . role_radio((string) $v['role'])
        . '<button type="submit" class="button">Send the invitation</button></form>';
    admin_screen('People', $body, 'people', ['errors' => $errors]);
}

/** One person: change their role, send a password link, turn off two-factor, or remove them. */
function person_screen(string $id, array $errors = []): never
{
    $u = find_user($id);
    if (!$u) {
        flash('That person could not be found. They may have been removed.', 'error');
        go(admin_url('people'));
    }
    $me = current_user();
    $isMe = $u['id'] === ($me['id'] ?? '');
    $lastOwner = $u['role'] === 'owner' && owner_count() <= 1;
    if (isset($_GET['remove'])) {
        if ($lastOwner) {
            flash('You can’t remove the only owner. Make someone else an owner first.', 'error');
            go(admin_url('people', ['id' => $id]));
        }
        $body = '<p>Are you sure you want to remove ' . h((string) $u['name']) . ' (' . h((string) $u['email']) . ')? They will be signed out straight away and can no longer sign in. '
            . 'Pages and posts they wrote are kept.</p>'
            . '<form method="post" action="' . h(admin_url('people', ['id' => $id])) . '" class="form-actions">' . csrf_field() . '<input type="hidden" name="do" value="remove">'
            . '<button type="submit" class="button button-danger">Yes, remove ' . h((string) $u['name']) . '</button>'
            . '<a class="button button-secondary" href="' . h(admin_url('people', ['id' => $id])) . '">No, go back</a></form>';
        admin_screen('Remove ' . h((string) $u['name']), $body, 'people');
    }
    $action = h(admin_url('people', ['id' => $id]));
    $form = fn(string $do, string $button, string $class = 'button') => '<form method="post" action="' . $action . '" class="inline">' . csrf_field()
        . '<input type="hidden" name="do" value="' . $do . '"><button type="submit" class="' . $class . '">' . $button . '</button></form>';
    $body = pending_link_html()
        . '<dl><dt>Email</dt><dd>' . h((string) $u['email']) . '</dd><dt>Role</dt><dd>' . h(role_label((string) $u['role'])) . '</dd>'
        . '<dt>Two-factor sign-in</dt><dd>' . (($u['totp_secret'] ?? '') !== '' ? 'On' : 'Off') . '</dd>'
        . '<dt>Added</dt><dd>' . h(format_time((string) $u['created'])) . '</dd>'
        . '<dt>Last signed in</dt><dd>' . ($u['last_sign_in'] ? h(format_time((string) $u['last_sign_in'])) : 'Never') . '</dd></dl>'
        . '<h2>Role</h2>'
        . ($lastOwner ? '<p>' . ($isMe ? 'You are' : h((string) $u['name']) . ' is') . ' the only owner, so this role can’t be changed until someone else is made an owner.</p>'
            : '<form method="post" action="' . $action . '" class="narrow">' . csrf_field() . '<input type="hidden" name="do" value="role">' . role_radio((string) $u['role'])
                . '<button type="submit" class="button">Save role</button></form>')
        . '<h2>Password</h2><p>Emails ' . h((string) $u['name']) . ' a link to choose a new password' . (mail_ready() ? '' : ' (email isn’t set up, so the link will be shown to you to pass on)') . '. Their current sessions end.</p>'
        . $form('reset', 'Send a set-password link')
        . '<h2>Two-factor sign-in</h2>'
        . (($u['totp_secret'] ?? '') !== ''
            ? '<p>If ' . h((string) $u['name']) . ' has lost their authenticator app and recovery codes, turn two-factor off so they can sign in with their password alone. They can set it up again under My account.</p>' . $form('totp_off', 'Turn off two-factor sign-in', 'button button-secondary')
            : '<p>Off. Each person turns it on for themselves under My account.</p>')
        . '<h2>Remove</h2>'
        . ($lastOwner ? '<p>The only owner can’t be removed.</p>'
            : '<p><a class="button button-danger" href="' . h(admin_url('people', ['id' => $id, 'remove' => 1])) . '">Remove ' . h((string) $u['name']) . '</a></p>')
        . '<p><a href="' . h(admin_url('people')) . '">Back to People</a></p>';
    admin_screen(h((string) $u['name']), $body, 'people', ['errors' => $errors]);
}

/** Send or show a password link for a person, depending on whether email works. */
function offer_password_link(array $user, string $purpose): void
{
    $me = current_user();
    $why = $purpose === 'invite' ? 'The invitation could not be emailed.' : 'The password link could not be emailed.';
    try {
        if (!mail_ready()) {
            throw new RuntimeException('Email isn’t set up on this website yet.');
        }
        send_password_link($user, $purpose, (string) ($me['name'] ?? ''));
        flash($purpose === 'invite' ? "Invited {$user['name']}. An email with a link to choose their password went to {$user['email']}."
            : "Emailed {$user['email']} a link to choose a new password. It works for one hour.");
    } catch (Throwable $e) {
        $_SESSION['people_link'] = ['name' => $user['name'], 'email' => $user['email'], 'link' => create_password_link((string) $user['id'], $purpose), 'why' => $why . ' ' . $e->getMessage()];
        flash($purpose === 'invite' ? "Added {$user['name']}, but the invitation could not be emailed. The link to give them is below." : 'The link could not be emailed. It is shown below for you to pass on.', 'error');
    }
}

function people_action(): never
{
    $do = (string) ($_POST['do'] ?? '');
    $me = current_user();
    if ($do === 'invite') {
        $v = ['name' => trim((string) ($_POST['name'] ?? '')), 'email' => normalize_email((string) ($_POST['email'] ?? '')), 'role' => (string) ($_POST['role'] ?? 'editor')];
        $errors = [];
        if ($v['name'] === '') {
            $errors['name'] = 'Enter the person’s name.';
        }
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter their email address in the right format, like name@example.com.';
        } elseif (find_user_by_email($v['email'])) {
            $errors['email'] = $v['email'] . ' already has an account.';
        }
        if (!isset(ROLES[$v['role']])) {
            $v['role'] = 'editor';
        }
        if ($errors) {
            $GLOBALS['people_errors'] = $errors;
            $GLOBALS['people_values'] = $v;
            people_screen();
        }
        $user = create_user($v['name'], $v['email'], null, $v['role']);
        activity_log('invited a person', "{$user['name']} ({$user['email']}) as " . strtolower(role_label($v['role'])));
        offer_password_link($user, 'invite');
        go(admin_url('people'));
    }
    $id = (string) ($_GET['id'] ?? '');
    $u = find_user($id);
    if (!$u) {
        flash('That person could not be found. They may have been removed.', 'error');
        go(admin_url('people'));
    }
    $lastOwner = $u['role'] === 'owner' && owner_count() <= 1;
    if ($do === 'role') {
        $role = (string) ($_POST['role'] ?? '');
        if (!isset(ROLES[$role])) {
            flash('Choose one of the roles.', 'error');
        } elseif ($lastOwner && $role !== 'owner') {
            flash('You can’t change the only owner’s role. Make someone else an owner first.', 'error');
        } elseif ($role !== $u['role']) {
            update_user($id, ['role' => $role]);
            activity_log('changed a role', "{$u['name']} ({$u['email']}) is now " . strtolower(role_label($role)));
            flash("{$u['name']} is now " . strtolower(role_label($role)) . '. It applies at their next click.');
        } else {
            flash("{$u['name']} was already " . strtolower(role_label($role)) . '.');
        }
        go(admin_url('people', ['id' => $id]));
    }
    if ($do === 'reset') {
        end_user_sessions($id);
        activity_log('sent a set-password link', "{$u['name']} ({$u['email']})");
        offer_password_link($u, 'reset');
        go(admin_url('people', ['id' => $id]));
    }
    if ($do === 'totp_off') {
        update_user($id, ['totp_secret' => null, 'recovery_codes' => []]);
        activity_log('turned off two-factor sign-in for a person', "{$u['name']} ({$u['email']})");
        flash("Two-factor sign-in is off for {$u['name']}. They can sign in with their password alone and set it up again under My account.");
        go(admin_url('people', ['id' => $id]));
    }
    if ($do === 'remove') {
        if ($lastOwner) {
            flash('You can’t remove the only owner. Make someone else an owner first.', 'error');
            go(admin_url('people', ['id' => $id]));
        }
        delete_user($id);
        activity_log('removed a person', "{$u['name']} ({$u['email']})");
        flash("Removed {$u['name']}. If they were signed in, they have been signed out.");
        if ($id === ($me['id'] ?? '')) {
            go(admin_url('logout'));
        }
        go(admin_url('people'));
    }
    not_found_screen();
}

/* =====================================================================
   Activity (owner)
   ===================================================================== */

function activity_screen(): never
{
    $person = (string) ($_GET['person'] ?? '');
    if ($person !== '' && !find_user($person)) {
        $person = '';
    }
    $options = '<option value="">Everyone</option>';
    foreach (users() as $u) {
        $options .= '<option value="' . h((string) $u['id']) . '"' . ($u['id'] === $person ? ' selected' : '') . '>' . h((string) $u['name']) . '</option>';
    }
    $rows = '';
    foreach (activity_entries(200, $person) as $e) {
        $who = trim((string) ($e['name'] ?? ''));
        $email = trim((string) ($e['email'] ?? ''));
        $rows .= '<tr><td>' . h(format_time((string) ($e['time'] ?? ''))) . '</td>'
            . '<td>' . ($who !== '' ? h($who) : '<em>Unknown</em>') . ($email !== '' ? ' <span class="hint">' . h($email) . '</span>' : '') . '</td>'
            . '<td>' . h((string) ($e['what'] ?? '')) . (($e['detail'] ?? '') !== '' ? ': ' . h((string) $e['detail']) : '') . '</td></tr>';
    }
    $body = '<p>What people have done in the control panel: sign-ins (and failed ones), pages and posts created, edited or deleted, settings changed, people added or removed, and backups downloaded. '
        . 'The 5,000 most recent entries are kept; this screen shows the last 200.</p>'
        . '<form method="get" action="' . h(ADMIN_URL) . '" class="form-actions"><input type="hidden" name="a" value="activity">'
        . '<div class="field"><label for="person">Show activity by</label><select id="person" name="person">' . $options . '</select></div>'
        . '<button type="submit" class="button button-secondary">Filter</button></form>'
        . ($rows === '' ? '<p>Nothing has been recorded yet.</p>'
            : '<div class="table-wrap"><table><caption>Recent activity, newest first</caption>'
                . '<thead><tr><th scope="col">When</th><th scope="col">Who</th><th scope="col">What</th></tr></thead><tbody>' . $rows . '</tbody></table></div>');
    admin_screen('Activity', $body, 'activity');
}

/* =====================================================================
   My account (everyone)
   ===================================================================== */

function account_screen(array $errors = []): never
{
    $u = current_user();
    if (!$u) {
        go(ADMIN_URL);
    }
    if (isset($_GET['download']) && $_GET['download'] === 'codes') {
        recovery_codes_download();
    }
    if (!isset($_GET['codes'])) {
        // The recovery codes were shown once; they aren't kept around.
        unset($_SESSION['recovery_show']);
    }
    $on = ($u['totp_secret'] ?? '') !== '';
    $body = '<dl><dt>Name</dt><dd>' . h((string) $u['name']) . '</dd><dt>Email address</dt><dd>' . h((string) $u['email']) . '</dd>'
        . '<dt>Role</dt><dd>' . h(role_label((string) $u['role'])) . '. ' . h(ROLE_HINTS[$u['role']] ?? '') . '</dd></dl>'
        . '<nav aria-label="On this screen"><ul><li><a href="#name-heading">Your name</a></li><li><a href="#password-heading">Change password</a></li><li><a href="#twofactor-heading">Two-factor sign-in</a></li></ul></nav>'

        . '<section aria-labelledby="name-heading"><h2 id="name-heading">Your name</h2><p>Shown next to the changes you make.</p>'
        . '<form method="post" action="' . h(admin_url('account')) . '#name-heading" class="narrow">' . csrf_field() . '<input type="hidden" name="do" value="name">'
        . text_field('name', 'Your name', (string) $u['name'], ['required' => true, 'autocomplete' => 'name', 'error' => $errors['name'] ?? null])
        . '<button type="submit" class="button">Save name</button></form></section>'

        . '<section aria-labelledby="password-heading"><h2 id="password-heading">Change password</h2>'
        . '<form method="post" action="' . h(admin_url('account')) . '#password-heading" class="narrow">' . csrf_field() . '<input type="hidden" name="do" value="password">'
        . '<input type="text" name="username" value="' . h((string) $u['email']) . '" autocomplete="username" aria-label="Email address" hidden>'
        . text_field('current_password', 'Current password', '', ['type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'error' => $errors['current_password'] ?? null])
        . text_field('new_password', 'New password', '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'error' => $errors['new_password'] ?? null, 'hint' => 'At least 12 characters.'])
        . text_field('confirm_password', 'Type the new password again', '', ['type' => 'password', 'autocomplete' => 'new-password', 'required' => true, 'error' => $errors['confirm_password'] ?? null])
        . '<button type="submit" class="button">Change password</button></form></section>'

        . '<section aria-labelledby="twofactor-heading"><h2 id="twofactor-heading">Two-factor sign-in</h2>';
    if (isset($_GET['codes']) && !empty($_SESSION['recovery_show'])) {
        $body .= recovery_codes_html((array) $_SESSION['recovery_show']);
    } elseif (isset($_GET['setup']) && !$on && !empty($_SESSION['totp_setup'])) {
        $body .= totp_setup_html((string) $_SESSION['totp_setup'], (string) $u['email'], $errors);
    } elseif ($on) {
        $left = count((array) ($u['recovery_codes'] ?? []));
        $body .= '<p>Status: <strong>On</strong>. After your password, you type a six-digit code from your authenticator app. You have ' . $left . ' unused recovery ' . ($left === 1 ? 'code' : 'codes') . '.</p>'
            . '<form method="post" action="' . h(admin_url('account')) . '#twofactor-heading" class="narrow">' . csrf_field()
            . text_field('password_2fa', 'Your password', '', ['type' => 'password', 'autocomplete' => 'current-password', 'required' => true, 'error' => $errors['password_2fa'] ?? null,
                'hint' => 'Needed for either button below.'])
            . '<div class="form-actions"><button type="submit" class="button button-secondary" name="do" value="codes_new">Make new recovery codes</button>'
            . '<button type="submit" class="button button-danger" name="do" value="totp_off">Turn off two-factor sign-in</button></div></form>';
    } else {
        $body .= '<p>Status: <strong>Off</strong>. Two-factor sign-in adds a second step after your password: a six-digit code from an authenticator app on your phone or computer '
            . '(such as Microsoft Authenticator, Google Authenticator, 1Password or Authy). It keeps your account safe even if someone learns your password. You will also get ten recovery codes to keep somewhere safe.</p>'
            . '<form method="post" action="' . h(admin_url('account')) . '">' . csrf_field() . '<input type="hidden" name="do" value="totp_begin">'
            . '<button type="submit" class="button">Set up two-factor sign-in</button></form>';
    }
    $body .= '</section>';
    admin_screen('My account', $body, 'account', ['errors' => $errors]);
}

/** The set-up step: the secret in text form, the otpauth address, and a box for the first code. */
function totp_setup_html(string $secret, string $email, array $errors): string
{
    $uri = totp_uri($secret, $email);
    return '<h3>Step 1: add ' . h(site_name()) . ' to your authenticator app</h3>'
        . '<p>In the app, choose to add an account by typing a key (sometimes called “Enter a setup key” or “Manual entry”). Give it the name <strong>' . h(site_name()) . '</strong> and type this key; spaces don’t matter and letters can be in either case:</p>'
        . '<p class="totp-secret" aria-label="Secret key"><code>' . h(totp_secret_groups($secret)) . '</code></p>'
        . '<p>Some apps take the full address instead. If yours does, this is it:</p><p><code>' . h($uri) . '</code></p>'
        . '<h3>Step 2: type the code the app shows</h3>'
        . '<form method="post" action="' . h(admin_url('account', ['setup' => 1])) . '#twofactor-heading" class="narrow">' . csrf_field() . '<input type="hidden" name="do" value="totp_confirm">'
        . text_field('code', 'Six-digit code from the app', '', ['required' => true, 'autocomplete' => 'one-time-code', 'inputmode' => 'numeric', 'spellcheck' => 'false', 'error' => $errors['code'] ?? null,
            'hint' => 'The code changes every 30 seconds. Typing it proves the app is set up right before two-factor is turned on.'])
        . '<div class="form-actions"><button type="submit" class="button">Turn on two-factor sign-in</button>'
        . '<a class="button button-secondary" href="' . h(admin_url('account')) . '#twofactor-heading">Cancel</a></div></form>';
}

function recovery_codes_html(array $codes): string
{
    $items = implode('', array_map(fn($c) => '<li><code>' . h($c) . '</code></li>', $codes));
    return '<div class="notice" role="status" tabindex="-1" id="recovery-codes"><p>Two-factor sign-in is on. Save these recovery codes now; they are shown only this once.</p></div>'
        . '<h3>Your recovery codes</h3><p>If you lose your authenticator app, one of these codes signs you in instead. Each works once. Keep them somewhere safe, away from your password.</p>'
        . '<ol class="recovery-codes">' . $items . '</ol>'
        . '<p><a class="button button-secondary" href="' . h(admin_url('account', ['download' => 'codes'])) . '">Download the codes as a text file</a> '
        . '<a class="button" href="' . h(admin_url('account')) . '">I have saved them</a></p>';
}

/** The recovery codes as a plain text file, available while they are still on screen. */
function recovery_codes_download(): never
{
    $codes = (array) ($_SESSION['recovery_show'] ?? []);
    if (!$codes) {
        go(admin_url('account'));
    }
    $text = 'Recovery codes for the ' . site_name() . " control panel\r\nAccount: " . (current_user()['email'] ?? '') . "\r\nMade: " . date('F j, Y g:i a') . "\r\n\r\n"
        . "Each code signs you in once if you lose your authenticator app.\r\n\r\n" . implode("\r\n", $codes) . "\r\n";
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/', '-', strtolower(site_host())) . '-recovery-codes.txt"');
    header('Cache-Control: no-store');
    echo $text;
    exit;
}

function account_action(): never
{
    $u = current_user();
    if (!$u) {
        go(ADMIN_URL);
    }
    $do = (string) ($_POST['do'] ?? '');
    $errors = [];
    if ($do === 'name') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            account_screen(['name' => 'Enter your name.']);
        }
        update_user((string) $u['id'], ['name' => $name]);
        $_SESSION['admin_name'] = $name;
        flash('Saved your name.');
        go(admin_url('account') . '#name-heading');
    }
    if ($do === 'password') {
        $new = (string) ($_POST['new_password'] ?? '');
        if (!password_verify((string) ($_POST['current_password'] ?? ''), (string) $u['password_hash'])) {
            $errors['current_password'] = 'Your current password is not right.';
        }
        if (($why = password_problem($new)) !== null) {
            $errors['new_password'] = $why;
        } elseif ($new !== ($_POST['confirm_password'] ?? '')) {
            $errors['confirm_password'] = 'The two new passwords do not match.';
        }
        if ($errors) {
            account_screen($errors);
        }
        // Anywhere else you were signed in is signed out; this browser carries on.
        set_user_password((string) $u['id'], $new);
        session_regenerate_id(true);
        $_SESSION['login_at'] = time();
        activity_log('changed their password');
        flash('Your password has been changed. If you were signed in anywhere else, you have been signed out there.');
        go(admin_url('account') . '#password-heading');
    }
    if ($do === 'totp_begin') {
        if (($u['totp_secret'] ?? '') !== '') {
            go(admin_url('account') . '#twofactor-heading');
        }
        $_SESSION['totp_setup'] = new_totp_secret();
        go(admin_url('account', ['setup' => 1]) . '#twofactor-heading');
    }
    if ($do === 'totp_confirm') {
        $secret = (string) ($_SESSION['totp_setup'] ?? '');
        if ($secret === '' || ($u['totp_secret'] ?? '') !== '') {
            go(admin_url('account') . '#twofactor-heading');
        }
        if (!totp_verify($secret, (string) ($_POST['code'] ?? ''))) {
            $_GET['setup'] = '1';
            account_screen(['code' => 'That code doesn’t match. Check that the key was typed into the app exactly, wait for a fresh code, and try again.']);
        }
        $codes = new_recovery_codes();
        update_user((string) $u['id'], ['totp_secret' => $secret, 'recovery_codes' => array_map('recovery_code_hash', $codes)]);
        unset($_SESSION['totp_setup']);
        $_SESSION['recovery_show'] = $codes;
        activity_log('turned on two-factor sign-in');
        go(admin_url('account', ['codes' => 1]) . '#recovery-codes');
    }
    if ($do === 'codes_new' || $do === 'totp_off') {
        if (!password_verify((string) ($_POST['password_2fa'] ?? ''), (string) $u['password_hash'])) {
            account_screen(['password_2fa' => 'Your password is not right.']);
        }
        if ($do === 'totp_off') {
            update_user((string) $u['id'], ['totp_secret' => null, 'recovery_codes' => []]);
            unset($_SESSION['recovery_show']);
            activity_log('turned off two-factor sign-in');
            flash('Two-factor sign-in is off. You sign in with your password alone.');
            go(admin_url('account') . '#twofactor-heading');
        }
        $codes = new_recovery_codes();
        update_user((string) $u['id'], ['recovery_codes' => array_map('recovery_code_hash', $codes)]);
        $_SESSION['recovery_show'] = $codes;
        activity_log('made new recovery codes');
        go(admin_url('account', ['codes' => 1]) . '#recovery-codes');
    }
    not_found_screen();
}
