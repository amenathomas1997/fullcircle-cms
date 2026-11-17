<?php
/**
 * The Deploy screen: a form for a new site, then a step-by-step report of the deployment.
 * The work itself is in lib/features/deploy.php. Owner only; feature "deploy".
 */

declare(strict_types=1);

function deploy_screen(bool $post): never
{
    $do = (string) ($_GET['do'] ?? '');
    $id = (string) ($_GET['id'] ?? '');
    if ($post) {
        match ($do) {
            'start' => deploy_start(),
            'resume' => deploy_resume($id),
            'link' => deploy_new_link($id),
            'forget' => deploy_forget($id),
            default => not_found_screen(),
        };
    }
    if ($id !== '') {
        deploy_job_screen($id);
    }
    deploy_list_screen();
}

function deploy_defaults(): array
{
    $s = settings();
    return ['cpanel_host' => '', 'cpanel_user' => '', 'cred_type' => 'password', 'cpanel_password' => '', 'cpanel_token' => '', 'ssh_port' => '22', 'domain' => '',
        'site_name' => '', 'legal_name' => '', 'tagline' => '', 'email' => '', 'phone' => '', 'timezone' => (string) ($s['timezone'] ?? 'America/New_York'),
        'palette' => 'purple', 'primary_language' => 'en', 'second_language' => '', 'owner_name' => '', 'owner_email' => '', 'send_link' => '1', 'move_aside' => '',
        'kit_repo' => (string) (config()['kit_repo'] ?? DEPLOY_DEFAULT_REPO)];
}

/* ---------- The list and the form ---------- */

function deploy_list_screen(array $errors = [], array $v = []): never
{
    $v += deploy_defaults();
    $jobs = deploy_jobs();
    $body = '<p>Build a new website from the kit on a cPanel account. Give the account’s sign-in, the domain (already added to that account), the site’s details and the owner’s name and email. '
        . 'The set-up runs from here over the cPanel API and SSH, usually in under two minutes, and each step is reported below as it goes.</p>'
        . '<p>Nothing typed here is kept: the password or token is used once to put this site’s own SSH key on the account, and that key does the rest. '
        . 'The owner never gets a password by email; they get a link to choose one.</p>';

    if ($jobs) {
        $body .= '<h2 id="past">Deployments</h2><div class="table-wrap"><table><caption class="visually-hidden">Deployments, newest first</caption>'
            . '<thead><tr><th scope="col">Site</th><th scope="col">Domain</th><th scope="col">State</th><th scope="col">Started</th></tr></thead><tbody>';
        foreach ($jobs as $job) {
            $body .= '<tr><th scope="row"><a href="' . h(admin_url('deploy', ['id' => $job['id']])) . '">' . h($job['site']['site_name']) . '</a></th><td>' . h($job['domain']) . '</td>'
                . '<td>' . h(deploy_state_words($job)) . '</td><td>' . h(format_time((string) $job['created'])) . '</td></tr>';
        }
        $body .= '</tbody></table></div>';
    }

    $palettes = [];
    foreach (PALETTES as $key => $p) {
        $palettes[$key] = $p['name'];
    }
    $body .= '<h2 id="new">Deploy a new site</h2>'
        . '<form method="post" action="' . h(admin_url('deploy', ['do' => 'start'])) . '" autocomplete="off">' . csrf_field()
        . '<fieldset><legend>The cPanel account</legend>'
        . text_field('cpanel_host', 'cPanel address', $v['cpanel_host'], ['required' => true, 'autocomplete' => 'off', 'spellcheck' => 'false',
            'hint' => 'The server’s host name, as in the address bar when you sign in to cPanel, for example webserver1.example.com. Port 2083 is assumed.', 'error' => $errors['cpanel_host'] ?? ''])
        . text_field('cpanel_user', 'cPanel user name', $v['cpanel_user'], ['required' => true, 'autocomplete' => 'off', 'spellcheck' => 'false', 'error' => $errors['cpanel_user'] ?? ''])
        . radio_field('cred_type', 'Sign in to cPanel with', ['password' => 'The account’s password', 'token' => 'A cPanel API token'], $v['cred_type'],
            ['hint' => 'A password lets the set-up sign in over SSH too. A token (cPanel, Security, Manage API Tokens) works when password sign-in over SSH is turned off on the server.'])
        . text_field('cpanel_password', 'cPanel password', '', ['type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Used once, never kept. Leave empty if you chose a token.', 'error' => $errors['cpanel_password'] ?? ''])
        . text_field('cpanel_token', 'cPanel API token', '', ['autocomplete' => 'off', 'spellcheck' => 'false', 'hint' => 'Used once, never kept. Leave empty if you chose the password.', 'error' => $errors['cpanel_token'] ?? ''])
        . text_field('ssh_port', 'SSH port', $v['ssh_port'], ['inputmode' => 'numeric', 'hint' => 'Usually 22. Some hosts use another; it is on cPanel’s SSH Access screen.', 'error' => $errors['ssh_port'] ?? ''])
        . text_field('domain', 'Domain', $v['domain'], ['required' => true, 'autocomplete' => 'off', 'spellcheck' => 'false',
            'hint' => 'For example example.com. It must already be on the account: its main domain, or one added under Domains in cPanel.', 'error' => $errors['domain'] ?? ''])
        . checkbox_field('move_aside', 'Move anything already in the web folder aside', $v['move_aside'] === '1',
            ['hint' => 'Normally the web folder must be empty. Tick this when an old website is there: it is moved to a folder next to the web folder, not deleted.'])
        . '</fieldset>'
        . '<fieldset><legend>The website</legend>'
        . text_field('site_name', 'Site name', $v['site_name'], ['required' => true, 'error' => $errors['site_name'] ?? ''])
        . text_field('legal_name', 'Legal name', $v['legal_name'], ['hint' => 'The business or person behind the site, for the footer and legal pages. Leave empty to use the site name.', 'error' => $errors['legal_name'] ?? ''])
        . text_field('tagline', 'Tagline', $v['tagline'], ['hint' => 'One line, shown under the site name. Optional.', 'error' => $errors['tagline'] ?? ''])
        . text_field('email', 'Public contact email', $v['email'], ['type' => 'email', 'autocomplete' => 'off', 'hint' => 'Shown on the site and where its contact form sends messages.', 'error' => $errors['email'] ?? ''])
        . text_field('phone', 'Public phone number', $v['phone'], ['type' => 'tel', 'autocomplete' => 'off', 'hint' => 'Optional.', 'error' => $errors['phone'] ?? ''])
        . timezone_select('timezone', 'Time zone', $v['timezone'])
        . radio_field('palette', 'Colors', $palettes, $v['palette'], ['hint' => 'Every set has been checked for contrast. The owner can change it later under Settings.'])
        . language_select('primary_language', 'The site is written in', $v['primary_language'])
        . language_select('second_language', 'Second language', $v['second_language'], 'Spanish gives translated starter pages; other languages get English starter pages marked as waiting for translation.', 'None')
        . '</fieldset>'
        . '<fieldset><legend>The owner</legend>'
        . text_field('owner_name', 'Owner’s name', $v['owner_name'], ['required' => true, 'autocomplete' => 'off', 'error' => $errors['owner_name'] ?? ''])
        . text_field('owner_email', 'Owner’s sign-in email', $v['owner_email'], ['type' => 'email', 'required' => true, 'autocomplete' => 'off', 'hint' => 'They sign in to their control panel with this address.', 'error' => $errors['owner_email'] ?? ''])
        . checkbox_field('send_link', 'Email the owner a link to choose their password', $v['send_link'] === '1',
            ['hint' => 'Sent from this site when the set-up finishes. The link is also shown to you, and a new one can be sent any time.'])
        . '</fieldset>'
        . '<fieldset><legend>The kit</legend>'
        . text_field('kit_repo', 'Kit repository', $v['kit_repo'], ['required' => true, 'autocomplete' => 'off', 'spellcheck' => 'false',
            'hint' => 'The git address the new site is cloned from and takes updates from. Set kit_repo in data/config.php to change the default.', 'error' => $errors['kit_repo'] ?? ''])
        . '</fieldset>'
        . '<div class="form-actions"><button type="submit" class="button">Deploy this site</button> <span>This takes a minute or two; the page comes back when it is done.</span></div></form>';
    admin_screen('Deploy', $body, 'deploy', ['errors' => $errors]);
}

function deploy_state_words(array $job): string
{
    return match ($job['state']) {
        'done' => 'Done',
        'failed' => 'Stopped at step ' . ($job['step'] + 1) . ' of ' . count(DEPLOY_STEPS),
        default => 'Running step ' . ($job['step'] + 1) . ' of ' . count(DEPLOY_STEPS),
    };
}

/** Check the form. Returns [errors, values]; the credential is returned apart from the values that are kept. */
function deploy_check_form(array $in): array
{
    $v = deploy_defaults();
    foreach ($v as $k => $default) {
        $v[$k] = in_array($k, ['send_link', 'move_aside'], true) ? (!empty($in[$k]) ? '1' : '') : trim((string) ($in[$k] ?? $default));
    }
    $errors = [];
    $address = deploy_cpanel_address($v['cpanel_host']);
    if (!$address) {
        $errors['cpanel_host'] = 'Enter the cPanel server’s host name, like webserver1.example.com.';
    } else {
        [$v['cpanel_api'], $v['cpanel_host']] = $address;
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_.-]{0,31}$/i', $v['cpanel_user'])) {
        $errors['cpanel_user'] = 'Enter the cPanel user name (letters and numbers).';
    }
    $v['cred_type'] = $v['cred_type'] === 'token' ? 'token' : 'password';
    $credential = $v['cred_type'] === 'token' ? trim((string) ($in['cpanel_token'] ?? '')) : (string) ($in['cpanel_password'] ?? '');
    if ($credential === '') {
        $errors[$v['cred_type'] === 'token' ? 'cpanel_token' : 'cpanel_password'] = $v['cred_type'] === 'token' ? 'Paste the API token.' : 'Enter the cPanel password.';
    }
    if (!ctype_digit($v['ssh_port']) || (int) $v['ssh_port'] < 1 || (int) $v['ssh_port'] > 65535) {
        $errors['ssh_port'] = 'The SSH port is a number, usually 22.';
    }
    $v['domain'] = strtolower(preg_replace('#^https?://|/.*$#', '', $v['domain']));
    if (!preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $v['domain'])) {
        $errors['domain'] = 'Enter the domain on its own, like example.com.';
    }
    if ($v['site_name'] === '' || mb_strlen($v['site_name']) > 80) {
        $errors['site_name'] = 'Enter the site’s name (up to 80 characters).';
    }
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter the contact email address in the right format, like name@example.com, or leave it empty.';
    }
    if (!in_array($v['timezone'], DateTimeZone::listIdentifiers(), true)) {
        $v['timezone'] = 'America/New_York';
    }
    if (!isset(PALETTES[$v['palette']])) {
        $v['palette'] = 'purple';
    }
    if (!isset(SUPPORTED_LANGUAGES[$v['primary_language']])) {
        $v['primary_language'] = 'en';
    }
    if ($v['second_language'] !== '' && (!isset(SUPPORTED_LANGUAGES[$v['second_language']]) || $v['second_language'] === $v['primary_language'])) {
        $v['second_language'] = '';
    }
    if ($v['owner_name'] === '' || mb_strlen($v['owner_name']) > 80) {
        $errors['owner_name'] = 'Enter the owner’s name.';
    }
    $v['owner_email'] = strtolower($v['owner_email']);
    if (!filter_var($v['owner_email'], FILTER_VALIDATE_EMAIL)) {
        $errors['owner_email'] = 'Enter the owner’s email address in the right format, like name@example.com.';
    }
    if (!preg_match('#^(https://[a-z0-9.-]+/[A-Za-z0-9._/-]+|[a-z0-9._-]+@[a-z0-9.-]+:[A-Za-z0-9._/-]+)$#i', $v['kit_repo'])) {
        $errors['kit_repo'] = 'Enter the repository’s https:// or git@ address.';
    }
    return [$errors, $v, $credential];
}

/* ---------- Doing it ---------- */

function deploy_start(): never
{
    [$errors, $v, $credential] = deploy_check_form($_POST);
    if ($errors) {
        deploy_list_screen($errors, $v);
    }
    $job = new_deploy_job($v);
    save_deploy_job($job);
    deploy_activity('started deploying ' . $job['domain']);
    // The browser waits for the redirect; the session is released so other tabs keep working meanwhile.
    session_write_close();
    run_deploy_job($job, $v['cred_type'], $credential);
    unset($credential);
    if ($job['state'] === 'done') {
        deploy_activity('deployed ' . $job['domain']);
    }
    go(admin_url('deploy', ['id' => $job['id']]));
}

function deploy_resume(string $id): never
{
    $job = deploy_job($id);
    if (!$job || $job['state'] === 'done') {
        not_found_screen();
    }
    $credType = ($_POST['cred_type'] ?? '') === 'token' ? 'token' : 'password';
    $credential = $credType === 'token' ? trim((string) ($_POST['cpanel_token'] ?? '')) : (string) ($_POST['cpanel_password'] ?? '');
    if (deploy_needs_credential($job) && $credential === '') {
        flash('Enter the cPanel password or token to carry on from this step.', 'error');
        go(admin_url('deploy', ['id' => $id]) . '#carry-on');
    }
    // A failed step is tried again from the start of that step.
    deploy_activity('carried on deploying ' . $job['domain']);
    session_write_close();
    run_deploy_job($job, $credType, $credential);
    unset($credential);
    if ($job['state'] === 'done') {
        deploy_activity('deployed ' . $job['domain']);
    }
    go(admin_url('deploy', ['id' => $job['id']]));
}

function deploy_new_link(string $id): never
{
    $job = deploy_job($id);
    if (!$job || $job['state'] !== 'done') {
        not_found_screen();
    }
    try {
        $info = deploy_remote_owner_link($job, false);
        $job['result']['owner_link'] = $info['link'];
        $job['result']['owner_link_made'] = date('c');
        save_deploy_job($job);
        deploy_email_owner($job);
        flash('Sent a new link to ' . $job['owner']['email'] . '. It works once and for one hour.');
    } catch (Throwable $e) {
        flash('Could not send a new link: ' . $e->getMessage(), 'error');
    }
    go(admin_url('deploy', ['id' => $id]) . '#owner');
}

function deploy_forget(string $id): never
{
    delete_deploy_job($id);
    flash('Forgot that deployment’s record. The site itself is untouched.');
    go(admin_url('deploy'));
}

function deploy_activity(string $what): void
{
    if (function_exists('activity_log')) {
        activity_log($what);
    }
}

/* ---------- One deployment ---------- */

function deploy_job_screen(string $id): never
{
    $job = deploy_job($id);
    if (!$job) {
        not_found_screen();
    }
    $site = h($job['site']['site_name']);
    $domain = h($job['domain']);
    $names = array_keys(DEPLOY_STEPS);
    $byStep = [];
    foreach ($job['log'] as $entry) {
        $byStep[$entry['step']] = $entry; // the latest try of each step
    }
    $body = '<p><a href="' . h(admin_url('deploy')) . '">All deployments</a></p>';
    $body .= match ($job['state']) {
        'done' => '<div class="notice notice-success" role="status"><p><strong>Done.</strong> ' . $site . ' is set up at <a href="https://' . $domain . '/">' . $domain . '</a>, finished ' . h(format_time((string) $job['finished'])) . '.</p></div>',
        'failed' => '<div class="notice notice-error" role="alert"><p><strong>Stopped at step ' . ($job['step'] + 1) . '.</strong> ' . h((string) ($byStep[$names[$job['step']]]['message'] ?? '')) . '</p></div>',
        default => '<div class="notice" role="status"><p>Running. If this page was opened while a deployment was going, refresh it in a minute.</p></div>',
    };

    $body .= '<h2 id="details">Details</h2><dl class="meta"><dt>Server</dt><dd>' . h($job['server']['user'] . '@' . $job['server']['host']) . '</dd>'
        . '<dt>Web folder</dt><dd>' . h($job['server']['docroot'] !== '' ? $job['server']['docroot'] : 'not found yet') . '</dd>'
        . '<dt>Owner</dt><dd>' . h($job['owner']['name'] . ', ' . $job['owner']['email']) . '</dd>'
        . '<dt>Kit</dt><dd>' . h($job['kit_repo']) . (!empty($job['result']['version']) ? ', version ' . h($job['result']['version']) : '') . '</dd>'
        . '<dt>Started</dt><dd>' . h(format_time((string) $job['created'])) . '</dd></dl>';

    $body .= '<h2 id="steps">Steps</h2><ol class="deploy-steps">';
    foreach ($names as $i => $name) {
        $entry = $byStep[$name] ?? null;
        $state = $entry === null ? 'Not started' : ($entry['ok'] ? 'Done' : 'Stopped');
        $body .= '<li><h3>' . h(DEPLOY_STEPS[$name]) . ' <span class="deploy-state">(' . $state . ($entry ? ', ' . h((string) $entry['seconds']) . ' s' : '') . ')</span></h3>';
        if ($entry) {
            if (!$entry['ok']) {
                $body .= '<p class="error-message">' . h((string) $entry['message']) . '</p>';
            }
            if ($entry['lines']) {
                $body .= '<ul>';
                foreach ($entry['lines'] as $line) {
                    $body .= '<li>' . h((string) $line) . '</li>';
                }
                $body .= '</ul>';
            }
        }
        $body .= '</li>';
    }
    $body .= '</ol>';

    if ($job['state'] === 'failed') {
        $body .= '<h2 id="carry-on">Carry on</h2><p>Fix what the message says, then try again. It starts again from the step that stopped; the steps already done are kept.</p>'
            . '<form method="post" action="' . h(admin_url('deploy', ['do' => 'resume', 'id' => $job['id']])) . '" autocomplete="off">' . csrf_field();
        if (deploy_needs_credential($job)) {
            $body .= radio_field('cred_type', 'Sign in to cPanel with', ['password' => 'The account’s password', 'token' => 'A cPanel API token'], 'password')
                . text_field('cpanel_password', 'cPanel password', '', ['type' => 'password', 'autocomplete' => 'new-password', 'hint' => 'Used once, never kept.'])
                . text_field('cpanel_token', 'cPanel API token', '', ['autocomplete' => 'off', 'spellcheck' => 'false']);
        }
        $body .= '<div class="form-actions"><button type="submit" class="button">Try again from step ' . ($job['step'] + 1) . '</button></div></form>';
    }

    if ($job['state'] === 'done') {
        $adminUrl = 'https://' . $job['domain'] . '/admin/';
        $body .= '<h2 id="owner">The owner’s sign-in</h2>'
            . '<p>' . h($job['owner']['name']) . ' signs in at <a href="' . h($adminUrl) . '">' . h($adminUrl) . '</a> as ' . h($job['owner']['email']) . '. '
            . 'This link lets them choose their password; it works once and for one hour from ' . h(format_time((string) ($job['result']['owner_link_made'] ?? $job['finished']))) . '.</p>'
            . '<div class="field"><label for="owner-link">Set-password link</label><input type="text" id="owner-link" value="' . h((string) ($job['result']['owner_link'] ?? '')) . '" readonly></div>'
            . '<form method="post" action="' . h(admin_url('deploy', ['do' => 'link', 'id' => $job['id']])) . '" class="form-actions">' . csrf_field()
            . '<button type="submit" class="button button-secondary">Send the owner a new link by email</button></form>'
            . '<p>If a link has expired, the owner can also choose “Forgot your password?” on their sign-in screen, which emails a new one from their own site.</p>';
        $body .= '<h2 id="next">What is left to do by hand</h2><ul>'
            . '<li>On the new site’s Settings screen, under Email sending, check that a test email arrives. PHP mail is on by default and works on most cPanel hosts.</li>'
            . '<li>Turn on the features the owner needs (Settings, Features), and fill in the home page, About, Contact and Footer.</li>'
            . '<li>If the owner has an old website, bring it in from the Import screen.</li>'
            . '<li>The site is listed on this site’s <a href="' . h(admin_url('sites')) . '">Sites screen</a>, so its health shows up with the others.</li>'
            . (!empty($job['result']['moved_to']) ? '<li>The old web folder’s contents are in ' . h((string) $job['result']['moved_to']) . ' on the server; delete that folder once nothing in it is missed.</li>' : '')
            . '</ul>';
    }

    $body .= '<h2 id="forget">This record</h2><p>This page is a record of the deployment and holds no passwords or keys. Forgetting it does nothing to the site.</p>'
        . '<form method="post" action="' . h(admin_url('deploy', ['do' => 'forget', 'id' => $job['id']])) . '" class="form-actions">' . csrf_field()
        . '<button type="submit" class="button button-secondary">Forget this deployment</button></form>';
    admin_screen('Deploy: ' . $job['site']['site_name'], $body, 'deploy');
}
