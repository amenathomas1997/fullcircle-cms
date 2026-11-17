<?php
/**
 * Deploy new sites: for the person who hosts several sites built from the kit.
 *
 * The Deploy screen (owner only, feature "deploy", off by default) takes the cPanel
 * sign-in of the account that will hold the new site, the domain, the site's details
 * and the owner's name and email, then does the whole set-up from here:
 *
 *   1. cPanel   sign in with the cPanel API; find the home folder, the web folder for
 *               the domain and the PHP version the domain runs; move it to 8.1+ if needed.
 *   2. key      put this site's own SSH key on the account (with the password over
 *               SSH, or with an API token through the cPanel API), so nothing else
 *               needs the password. The password is used for this one step and never kept.
 *   3. server   over SSH: PHP, git, and whether the web folder is empty.
 *   4. clone    turn the web folder into a clone of the kit repository.
 *   5. setup    run tools/setup.php with the details given; the owner gets a random
 *               password nobody sees and a set-password link instead.
 *   6. cron     the three cron lines.
 *   7. finish   a fleet key on the new site, listed on this site's Sites screen; the
 *               owner's set-password link, emailed if asked.
 *   8. check    open the new site's home page and sign-in screen.
 *
 * Every step is logged in data/deploys/<id>.json, so a failed deployment can be read
 * and carried on from the step that stopped. Nothing secret is written there: the
 * SSH key pair lives in data/deploy-key (mode 600), and cPanel passwords and tokens
 * are only ever held in memory during the request that uses them.
 */

declare(strict_types=1);

const DEPLOY_DIR = DATA_DIR . '/deploys';
// Tests may define DEPLOY_KEY before the kit loads, to use a key of their own.
if (!defined('DEPLOY_KEY')) {
    define('DEPLOY_KEY', DATA_DIR . '/deploy-key');
}
const DEPLOY_KNOWN_HOSTS = DATA_DIR . '/deploy-known-hosts';
const DEPLOY_DEFAULT_REPO = 'https://github.com/amenathomas1997/fullcircle-cms.git';
const DEPLOY_STEPS = [
    'cpanel' => 'Sign in to cPanel and find the web folder',
    'key' => 'Put this site’s SSH key on the account',
    'server' => 'Check PHP, git and the web folder',
    'clone' => 'Get the kit from its repository',
    'setup' => 'Set up the site and the owner’s account',
    'cron' => 'Add the cron jobs',
    'finish' => 'Keys and the owner’s sign-in link',
    'check' => 'Open the new site',
];
// Files cPanel puts in a new web folder; anything else means a website is already there.
const DEPLOY_DEFAULT_FILES = ['cgi-bin', '.well-known', '.ftpquota', '.DS_Store'];

register_feature('deploy', 'Deploy new sites', 'For the person who hosts several sites: a Deploy screen that builds a new site from the kit on any cPanel account, given its sign-in, the domain and the site’s details.');

$deployScreen = function (bool $post): never {
    require_once ROOT . '/admin/deploy.php';
    if (function_exists('is_owner') && !is_owner()) {
        not_found_screen();
    }
    deploy_screen($post);
};
register_admin_screen('deploy', $deployScreen, 'Deploy', 'deploy');
unset($deployScreen);

register_dashboard_card(function (): string {
    if (!feature_on('deploy')) {
        return '';
    }
    $running = array_filter(deploy_jobs(), fn($j) => $j['state'] === 'failed');
    if (!$running) {
        return '';
    }
    $n = count($running);
    return '<li><h2><a href="' . h(admin_url('deploy')) . '">Deploy</a></h2><p>' . $n . ' deployment' . ($n === 1 ? '' : 's') . ' stopped part way. Open the Deploy screen to see why and carry on.</p></li>';
});

/* =====================================================================
   Jobs on disk
   ===================================================================== */

class DeployFailed extends RuntimeException
{
    public array $lines;

    public function __construct(string $message, array $lines = [])
    {
        parent::__construct($message);
        $this->lines = $lines;
    }
}

function deploy_jobs(): array
{
    $out = [];
    foreach (glob(DEPLOY_DIR . '/*.json') ?: [] as $file) {
        $job = read_json($file);
        if ($job) {
            $out[$job['id']] = $job;
        }
    }
    krsort($out);
    return $out;
}

function deploy_job(string $id): ?array
{
    if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $id)) {
        return null;
    }
    return read_json(DEPLOY_DIR . "/$id.json");
}

function save_deploy_job(array $job): void
{
    if (!is_dir(DEPLOY_DIR)) {
        mkdir(DEPLOY_DIR, 0700, true);
    }
    write_json(DEPLOY_DIR . '/' . $job['id'] . '.json', $job);
    @chmod(DEPLOY_DIR . '/' . $job['id'] . '.json', 0600);
}

function delete_deploy_job(string $id): void
{
    if (deploy_job($id)) {
        unlink(DEPLOY_DIR . "/$id.json");
    }
}

/** A new job from the checked form values. The credential is not part of it. */
function new_deploy_job(array $v): array
{
    return [
        'id' => date('Ymd-His') . '-' . bin2hex(random_bytes(3)),
        'created' => date('c'),
        'state' => 'running',
        'step' => 0,
        'server' => ['host' => $v['cpanel_host'], 'api' => $v['cpanel_api'], 'port' => (int) $v['ssh_port'], 'user' => $v['cpanel_user'],
            'home' => '', 'docroot' => '', 'php' => '', 'php_web' => ''],
        'domain' => $v['domain'],
        'site' => ['site_name' => $v['site_name'], 'legal_name' => $v['legal_name'], 'tagline' => $v['tagline'], 'email' => $v['email'],
            'phone' => $v['phone'], 'timezone' => $v['timezone'], 'palette' => $v['palette'],
            'primary_language' => $v['primary_language'], 'second_language' => $v['second_language']],
        'owner' => ['name' => $v['owner_name'], 'email' => $v['owner_email'], 'send_link' => !empty($v['send_link'])],
        'move_aside' => !empty($v['move_aside']),
        'kit_repo' => $v['kit_repo'],
        'log' => [],
        'result' => [],
    ];
}

/** Does carrying on from where this job stopped need the cPanel password or token again? */
function deploy_needs_credential(array $job): bool
{
    return $job['step'] < 2;
}

/* =====================================================================
   Running commands here: ssh, sshpass, ssh-keygen, curl through PHP
   ===================================================================== */

/** Run a program with arguments (no shell), with a time limit. Returns [exit code, stdout, stderr]. */
function deploy_exec(array $argv, array $env = [], int $timeout = 300, string $stdin = ''): array
{
    $base = array_filter(getenv(), fn($v, $k) => in_array($k, ['HOME', 'USER', 'LANG', 'TMPDIR'], true), ARRAY_FILTER_USE_BOTH);
    $base['PATH'] = '/usr/local/bin:/usr/bin:/bin:' . ((string) getenv('PATH'));
    if (empty($base['HOME'])) {
        // Web PHP on cPanel has no HOME; ssh wants one for its own files.
        $base['HOME'] = function_exists('posix_getpwuid') ? ((posix_getpwuid(posix_geteuid())['dir'] ?? '') ?: sys_get_temp_dir()) : sys_get_temp_dir();
    }
    $proc = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT, $env + $base);
    if (!is_resource($proc)) {
        return [127, '', 'Could not start ' . $argv[0] . '.'];
    }
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $deadline = microtime(true) + $timeout;
    $timedOut = false;
    while (true) {
        $read = [$pipes[1], $pipes[2]];
        $w = $e = null;
        if (@stream_select($read, $w, $e, 1) > 0) {
            foreach ($read as $stream) {
                $chunk = (string) fread($stream, 65536);
                if ($stream === $pipes[1]) {
                    $out .= $chunk;
                } else {
                    $err .= $chunk;
                }
            }
        }
        $status = proc_get_status($proc);
        if (!$status['running'] && feof($pipes[1]) && feof($pipes[2])) {
            break;
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            proc_terminate($proc, 9);
            break;
        }
    }
    $out .= (string) stream_get_contents($pipes[1]);
    $err .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($timedOut) {
        return [124, $out, trim($err . "\nStopped after $timeout seconds.")];
    }
    return [$code, $out, $err];
}

/** Quote a value for a remote POSIX shell. */
function deploy_q(string $s): string
{
    return "'" . str_replace("'", "'\\''", $s) . "'";
}

function deploy_ssh_args(array $server, bool $withKey): array
{
    $args = ['ssh', '-p', (string) ($server['port'] ?: 22), '-o', 'StrictHostKeyChecking=accept-new', '-o', 'UserKnownHostsFile=' . DEPLOY_KNOWN_HOSTS,
        '-o', 'ConnectTimeout=20', '-o', 'LogLevel=ERROR', '-o', 'IdentitiesOnly=yes', '-o', 'ServerAliveInterval=30'];
    if ($withKey) {
        array_push($args, '-i', DEPLOY_KEY, '-o', 'BatchMode=yes', '-o', 'PreferredAuthentications=publickey');
    } else {
        array_push($args, '-o', 'PubkeyAuthentication=no', '-o', 'PreferredAuthentications=password,keyboard-interactive', '-o', 'NumberOfPasswordPrompts=1');
    }
    $args[] = $server['user'] . '@' . $server['host'];
    return $args;
}

/** Run a shell script on the account with this site's key. */
function deploy_ssh(array $server, string $script, int $timeout = 300): array
{
    $args = deploy_ssh_args($server, true);
    $args[] = $script;
    return deploy_exec($args, [], $timeout);
}

/** Run a shell script on the account with the cPanel password, through sshpass. */
function deploy_ssh_password(array $server, string $password, string $script, int $timeout = 120): array
{
    $args = array_merge(['sshpass', '-e'], deploy_ssh_args($server, false));
    $args[] = $script;
    return deploy_exec($args, ['SSHPASS' => $password], $timeout);
}

/** This site's deploy key pair, made the first time it is needed. Returns the public key line. */
function deploy_public_key(): string
{
    if (!is_file(DEPLOY_KEY) || !is_file(DEPLOY_KEY . '.pub')) {
        @unlink(DEPLOY_KEY);
        @unlink(DEPLOY_KEY . '.pub');
        [$code, , $err] = deploy_exec(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', DEPLOY_KEY, '-C', 'deploy from ' . site_host()], [], 60);
        if ($code !== 0 || !is_file(DEPLOY_KEY . '.pub')) {
            throw new DeployFailed('Could not make an SSH key on this server: ' . trim($err));
        }
        @chmod(DEPLOY_KEY, 0600);
        @chmod(DEPLOY_KEY . '.pub', 0600);
    }
    return trim((string) file_get_contents(DEPLOY_KEY . '.pub'));
}

/* =====================================================================
   The cPanel API
   ===================================================================== */

/** "webserver1.example.com" → ['https://webserver1.example.com:2083', 'webserver1.example.com']. */
function deploy_cpanel_address(string $given): ?array
{
    $given = trim($given);
    if (!preg_match('#^(?:(https?)://)?([a-z0-9.-]+)(?::(\d+))?/?$#i', $given, $m)) {
        return null;
    }
    $scheme = strtolower($m[1] ?: 'https');
    $host = strtolower($m[2]);
    $port = (int) ($m[3] ?: ($scheme === 'https' ? 2083 : 2082));
    return ["$scheme://$host:$port", $host];
}

/**
 * Call the cPanel API as the account. $path is "Module/function" for UAPI, or "api2/Module/function"
 * for the older API 2 (the SSH key functions only exist there). Returns the decoded reply's data.
 */
function deploy_cpanel(array $server, string $credType, string $credential, string $path, array $params = []): array
{
    $user = $server['user'];
    $auth = $credType === 'token' ? "Authorization: cpanel $user:$credential" : 'Authorization: Basic ' . base64_encode("$user:$credential");
    if (str_starts_with($path, 'api2/')) {
        [, $module, $func] = explode('/', $path, 3);
        $url = $server['api'] . '/json-api/cpanel?' . http_build_query(['cpanel_jsonapi_user' => $user, 'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module' => $module, 'cpanel_jsonapi_func' => $func] + $params);
    } else {
        $url = $server['api'] . '/execute/' . $path . ($params ? '?' . http_build_query($params) : '');
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER => [$auth, 'Accept: application/json'], CURLOPT_USERAGENT => 'Full Circle CMS deploy']);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        if (str_contains($curlErr, 'certificate')) {
            throw new DeployFailed('The cPanel address needs a certificate that matches its name. Use the server’s host name (the one in the browser’s address bar when you sign in to cPanel), not an IP address.', [$curlErr]);
        }
        throw new DeployFailed('Could not reach cPanel at ' . $server['api'] . ': ' . $curlErr);
    }
    if ($code === 401 || $code === 403) {
        throw new DeployFailed($credType === 'token' ? 'cPanel did not accept the API token for that user name.' : 'cPanel did not accept that user name and password.');
    }
    $data = json_decode((string) $body, true);
    if (!is_array($data)) {
        throw new DeployFailed("cPanel answered with status $code and no readable reply (is that the cPanel address, with its port 2083?).", [mb_substr(strip_tags((string) $body), 0, 300)]);
    }
    if (str_starts_with($path, 'api2/')) {
        $r = $data['cpanelresult'] ?? [];
        if (!empty($r['error'])) {
            throw new DeployFailed('cPanel said: ' . $r['error']);
        }
        $first = $r['data'][0] ?? [];
        if (isset($first['result']) && !$first['result'] && !empty($first['reason'] ?? $first['error'] ?? '')) {
            throw new DeployFailed('cPanel said: ' . ($first['reason'] ?? $first['error']));
        }
        return is_array($r['data'] ?? null) ? $r['data'] : [];
    }
    if (empty($data['status'])) {
        throw new DeployFailed('cPanel said: ' . implode(' ', (array) ($data['errors'] ?? ['no reason given'])));
    }
    return is_array($data['data'] ?? null) ? $data['data'] : ['value' => $data['data'] ?? null];
}

/* =====================================================================
   The steps
   ===================================================================== */

/**
 * Run the job from the step it is on until it is done or a step fails.
 * $credType is 'password' or 'token'; $credential may be '' once the key is in place.
 */
function run_deploy_job(array &$job, string $credType = 'password', string $credential = ''): void
{
    @set_time_limit(1200);
    ignore_user_abort(true);
    $names = array_keys(DEPLOY_STEPS);
    $job['state'] = 'running';
    while ($job['step'] < count($names)) {
        $name = $names[$job['step']];
        $started = microtime(true);
        try {
            $lines = ('deploy_step_' . $name)($job, $credType, $credential);
            $job['log'][] = ['step' => $name, 'ok' => true, 'lines' => array_values($lines), 'at' => date('c'), 'seconds' => round(microtime(true) - $started, 1)];
            $job['step']++;
        } catch (DeployFailed $e) {
            $job['log'][] = ['step' => $name, 'ok' => false, 'message' => $e->getMessage(), 'lines' => array_values($e->lines), 'at' => date('c'), 'seconds' => round(microtime(true) - $started, 1)];
            $job['state'] = 'failed';
            save_deploy_job($job);
            return;
        } catch (Throwable $e) {
            $job['log'][] = ['step' => $name, 'ok' => false, 'message' => 'Something went wrong on this server: ' . $e->getMessage(), 'lines' => [], 'at' => date('c'), 'seconds' => round(microtime(true) - $started, 1)];
            $job['state'] = 'failed';
            save_deploy_job($job);
            error_log('Deploy ' . $job['id'] . ' step ' . $name . ': ' . $e);
            return;
        }
        save_deploy_job($job);
    }
    $job['state'] = 'done';
    $job['finished'] = date('c');
    save_deploy_job($job);
}

/** Short, readable lines from a command's output. */
function deploy_tail(string $text, int $max = 12): array
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", trim($text))), fn($l) => $l !== '' && !str_contains($l, 'ea_php_cli.pm')));
    return count($lines) > $max ? array_merge(['…'], array_slice($lines, -$max)) : $lines;
}

/** The PHP versions cPanel calls "ea-php81" and so on, as "8.1". */
function deploy_php_version(string $eaName): string
{
    return preg_match('/php(\d)(\d+)/', $eaName, $m) ? "$m[1].$m[2]" : '';
}

function deploy_step_cpanel(array &$job, string $credType, string $credential): array
{
    if ($credential === '') {
        throw new DeployFailed('The cPanel password or API token is needed for this step. Enter it and try again.');
    }
    $s = &$job['server'];
    $info = deploy_cpanel($s, $credType, $credential, 'Variables/get_user_information');
    $s['home'] = rtrim((string) ($info['home'] ?? ''), '/');
    $shell = (string) ($info['shell'] ?? '');
    if ($s['home'] === '') {
        throw new DeployFailed('cPanel did not say where the account’s home folder is.');
    }
    $lines = ['Signed in to cPanel as ' . $s['user'] . ' on ' . $s['host'] . '.', 'Home folder: ' . $s['home'] . '.'];
    if ($shell !== '' && str_ends_with($shell, 'noshell')) {
        throw new DeployFailed('Shell access is turned off for this cPanel account, and the set-up works over SSH. In WHM, under Account Functions, Manage Shell Access, turn on shell access for ' . $s['user'] . ' (jailed shell is fine), then try again.', $lines);
    }

    $want = strtolower(preg_replace('/^www\./', '', $job['domain']));
    $domains = deploy_cpanel($s, $credType, $credential, 'DomainInfo/domains_data', ['format' => 'hash']);
    $found = null;
    $known = [];
    $main = $domains['main_domain'] ?? [];
    foreach (array_merge([$main], $domains['addon_domains'] ?? [], $domains['sub_domains'] ?? []) as $d) {
        $name = strtolower((string) ($d['domain'] ?? ''));
        if ($name === '') {
            continue;
        }
        $known[] = $name;
        if ($name === $want || $name === "www.$want") {
            $found = $d;
        }
    }
    foreach ($domains['parked_domains'] ?? [] as $d) {
        $name = strtolower(is_array($d) ? (string) ($d['domain'] ?? '') : (string) $d);
        $known[] = $name;
        if ($name === $want && $main) {
            $found = $main;
        }
    }
    if (!$found) {
        throw new DeployFailed('cPanel does not have ' . $job['domain'] . ' on this account. Its domains are: ' . implode(', ', array_unique($known)) . '. Add the domain in cPanel first (Domains, Create a new domain), or check the spelling.', $lines);
    }
    $s['docroot'] = rtrim((string) ($found['documentroot'] ?? ''), '/');
    if ($s['docroot'] === '') {
        throw new DeployFailed('cPanel did not say which folder serves ' . $job['domain'] . '.', $lines);
    }
    $lines[] = 'Web folder for ' . $job['domain'] . ': ' . $s['docroot'] . '.';

    // The PHP version the domain's web pages run. 8.1 or newer, or the kit will not start.
    try {
        $versions = deploy_cpanel($s, $credType, $credential, 'LangPHP/php_get_vhost_versions');
    } catch (DeployFailed $e) {
        $versions = [];
        $lines[] = 'Could not read the domain’s PHP version (' . $e->getMessage() . '); it will be checked over SSH instead.';
    }
    $current = '';
    foreach ($versions as $v) {
        if (strtolower((string) ($v['vhost'] ?? '')) === strtolower((string) $found['domain'])) {
            $current = (string) ($v['version'] ?? '');
        }
    }
    if ($current !== '') {
        $num = deploy_php_version($current);
        if ($num !== '' && version_compare($num, '8.1', '>=')) {
            $s['php_web'] = $num;
            $lines[] = "The domain runs PHP $num.";
        } else {
            $installed = deploy_cpanel($s, $credType, $credential, 'LangPHP/php_get_installed_versions');
            $choices = array_filter((array) ($installed['versions'] ?? $installed), fn($n) => is_string($n) && version_compare(deploy_php_version($n) ?: '0', '8.1', '>='));
            usort($choices, fn($a, $b) => version_compare(deploy_php_version($b), deploy_php_version($a)));
            if (!$choices) {
                throw new DeployFailed('The domain runs PHP ' . ($num ?: $current) . ' and the server has no PHP 8.1 or newer installed. Ask the host to install one, then try again.', $lines);
            }
            $pick = (string) $choices[0];
            deploy_cpanel($s, $credType, $credential, 'LangPHP/php_set_vhost_versions', ['vhost' => (string) $found['domain'], 'version' => $pick]);
            $s['php_web'] = deploy_php_version($pick);
            $lines[] = 'The domain ran PHP ' . ($num ?: $current) . '; changed it to PHP ' . $s['php_web'] . ' in MultiPHP Manager.';
        }
    }
    return $lines;
}

function deploy_step_key(array &$job, string $credType, string $credential): array
{
    $s = $job['server'];
    $pub = deploy_public_key();
    [$code, $out] = deploy_ssh($s, 'echo fc-key-ok', 60);
    if ($code === 0 && str_contains($out, 'fc-key-ok')) {
        return ['This site’s key already opens that account; nothing to add.'];
    }
    if ($credential === '') {
        throw new DeployFailed('The cPanel password or API token is needed to put the key on the account. Enter it and try again.');
    }
    $lines = [];
    if ($credType === 'password') {
        $script = 'umask 077; mkdir -p "$HOME/.ssh" && touch "$HOME/.ssh/authorized_keys" && (grep -qF ' . deploy_q($pub) . ' "$HOME/.ssh/authorized_keys" || printf \'%s\n\' ' . deploy_q($pub) . ' >> "$HOME/.ssh/authorized_keys") && chmod 700 "$HOME/.ssh" && chmod 600 "$HOME/.ssh/authorized_keys" && echo fc-key-added';
        [$code, $out, $err] = deploy_ssh_password($s, $credential, $script);
        if ($code === 127 || str_contains($err, 'Could not start sshpass')) {
            throw new DeployFailed('This server has no sshpass program, so the key cannot be added with a password. Use a cPanel API token instead (cPanel, Security, Manage API Tokens).');
        }
        if ($code === 5) {
            throw new DeployFailed('SSH did not accept that password for ' . $s['user'] . '@' . $s['host'] . '. cPanel accepted it, so SSH may use a different port (ask the host) or password sign-in may be turned off on the server; an API token gets round that.', deploy_tail($err));
        }
        if (str_contains($out . $err, 'Shell access is not enabled') || str_contains($err, 'not enabled')) {
            throw new DeployFailed('Shell access is turned off for this cPanel account. In WHM, under Account Functions, Manage Shell Access, turn it on for ' . $s['user'] . ', then try again.', deploy_tail($err));
        }
        if ($code !== 0 || !str_contains($out, 'fc-key-added')) {
            throw new DeployFailed('Could not add the key over SSH (exit code ' . $code . ').', deploy_tail($out . "\n" . $err));
        }
        $lines[] = 'Added this site’s key to the account’s authorized keys.';
    } else {
        $name = 'fullcircle-deploy-' . preg_replace('/[^a-z0-9]+/', '-', site_host());
        deploy_cpanel($s, $credType, $credential, 'api2/SSH/importkey', ['name' => $name, 'key' => $pub]);
        deploy_cpanel($s, $credType, $credential, 'api2/SSH/authkey', ['key' => $name, 'action' => 'authorize']);
        $lines[] = 'Imported this site’s key through the cPanel API as "' . $name . '" and authorized it.';
    }
    [$code, $out, $err] = deploy_ssh($s, 'echo fc-key-ok', 60);
    if ($code !== 0 || !str_contains($out, 'fc-key-ok')) {
        throw new DeployFailed('The key was added but signing in with it did not work (exit code ' . $code . ').', array_merge($lines, deploy_tail($err)));
    }
    $lines[] = 'Signing in with the key works.';
    return $lines;
}

function deploy_step_server(array &$job, string $credType, string $credential): array
{
    $s = &$job['server'];
    $doc = deploy_q($s['docroot']);
    $script = 'for v in 85 84 83 82 81; do if [ -x /opt/cpanel/ea-php$v/root/usr/bin/php ]; then echo "PHP=/opt/cpanel/ea-php$v/root/usr/bin/php"; break; fi; done; '
        . 'command -v php >/dev/null 2>&1 && echo "PHPDEFAULT=$(command -v php)"; '
        . 'command -v git >/dev/null 2>&1 && echo "GIT=$(git --version 2>/dev/null)"; '
        . 'if [ -d ' . $doc . ' ]; then echo "DOC=exists"; ls -A1 ' . $doc . '; else echo "DOC=missing"; fi';
    [$code, $out, $err] = deploy_ssh($s, $script, 120);
    if ($code !== 0) {
        throw new DeployFailed('Could not look around the server over SSH (exit code ' . $code . ').', deploy_tail($err));
    }
    $php = $phpDefault = $git = '';
    $docState = '';
    $files = [];
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'PHP=')) {
            $php = substr($line, 4);
        } elseif (str_starts_with($line, 'PHPDEFAULT=')) {
            $phpDefault = substr($line, 11);
        } elseif (str_starts_with($line, 'GIT=')) {
            $git = substr($line, 4);
        } elseif (str_starts_with($line, 'DOC=')) {
            $docState = substr($line, 4);
        } elseif ($docState !== '' && $line !== '') {
            $files[] = $line;
        }
    }
    $s['php'] = $php !== '' ? $php : $phpDefault;
    if ($s['php'] === '') {
        throw new DeployFailed('There is no php program on the server’s command line, which the set-up and the cron jobs need.');
    }
    [, $ver] = deploy_ssh($s, deploy_q($s['php']) . ' -r "echo PHP_VERSION;" 2>/dev/null', 60);
    $ver = trim(preg_replace('/[^0-9.].*/s', '', trim($ver)));
    if ($ver === '' || version_compare($ver, '8.1', '<')) {
        throw new DeployFailed('The command-line PHP on the server is ' . ($ver ?: 'unknown') . '; the kit needs 8.1 or newer. Ask the host for a newer PHP.');
    }
    $lines = ["Command-line PHP $ver at " . $s['php'] . '.'];
    if ($git === '') {
        throw new DeployFailed('git is not installed on the server, and sites must be clones of the kit so they can take updates. Ask the host to install git.', $lines);
    }
    $lines[] = ucfirst($git) . '.';

    $others = array_values(array_diff($files, DEPLOY_DEFAULT_FILES));
    if ($docState === 'missing') {
        $lines[] = 'The web folder does not exist yet; it will be made.';
    } elseif (!$others) {
        $lines[] = 'The web folder is empty' . ($files ? ' apart from cPanel’s own ' . implode(', ', $files) : '') . '.';
    } elseif (!$job['move_aside']) {
        throw new DeployFailed('The web folder already holds ' . count($others) . ' ' . (count($others) === 1 ? 'item' : 'items') . ': ' . implode(', ', array_slice($others, 0, 8)) . (count($others) > 8 ? '…' : '')
            . '. If that is an old website that should go, tick “Move anything already in the web folder aside” and try again; it is kept in a folder next to the web folder, not deleted.', $lines);
    } else {
        $dest = $s['home'] . '/before-fullcircle-' . date('Y-m-d-His');
        $move = 'mkdir -p ' . deploy_q($dest) . ' && chmod 700 ' . deploy_q($dest) . ' && cd ' . $doc . ' && for f in * .[!.]* ..?*; do [ -e "$f" ] || continue; case "$f" in cgi-bin|.well-known) continue;; esac; mv "$f" ' . deploy_q($dest) . '/ || exit 1; done; echo fc-moved';
        [$code, $out, $err] = deploy_ssh($s, $move, 300);
        if ($code !== 0 || !str_contains($out, 'fc-moved')) {
            throw new DeployFailed('Could not move the old files aside.', array_merge($lines, deploy_tail($err)));
        }
        $job['result']['moved_to'] = $dest;
        $lines[] = 'Moved ' . count($others) . ' ' . (count($others) === 1 ? 'item' : 'items') . ' from the web folder to ' . $dest . '.';
    }
    return $lines;
}

function deploy_step_clone(array &$job, string $credType, string $credential): array
{
    $s = $job['server'];
    $doc = deploy_q($s['docroot']);
    $repo = deploy_q($job['kit_repo']);
    $script = 'mkdir -p ' . $doc . ' && cd ' . $doc . ' && '
        . 'if [ -d .git ]; then cur=$(git remote get-url origin 2>/dev/null); if [ "$cur" != ' . $repo . ' ]; then echo "OTHER-REPO=$cur"; exit 3; fi; else git init -q && git remote add origin ' . $repo . '; fi && '
        . 'git fetch -q origin main && git checkout -q -f -B main origin/main && git branch -q --set-upstream-to=origin/main main 2>/dev/null; '
        . 'chmod 750 ' . $doc . ' && echo "VERSION=$(cat VERSION 2>/dev/null)"';
    [$code, $out, $err] = deploy_ssh($s, $script, 600);
    if ($code === 3) {
        throw new DeployFailed('The web folder is already a git clone of something else (' . trim(substr(trim($out), 11)) . '). Tick “Move anything already in the web folder aside” to start fresh.');
    }
    if ($code !== 0 || !preg_match('/VERSION=(\S+)/', $out, $m)) {
        throw new DeployFailed('Could not get the kit from ' . $job['kit_repo'] . ' (exit code ' . $code . '). If the repository is private, the server needs a deploy key; the public address works without one.', deploy_tail($out . "\n" . $err));
    }
    $job['result']['version'] = $m[1];
    return ['Cloned ' . $job['kit_repo'] . ' into ' . $s['docroot'] . '.', 'Kit version ' . $m[1] . '.'];
}

function deploy_step_setup(array &$job, string $credType, string $credential): array
{
    $s = $job['server'];
    $site = $job['site'];
    $password = bin2hex(random_bytes(16));
    $env = ['SETUP_SITE_NAME' => $site['site_name'], 'SETUP_LEGAL_NAME' => $site['legal_name'] !== '' ? $site['legal_name'] : $site['site_name'],
        'SETUP_TAGLINE' => $site['tagline'], 'SETUP_SITE_URL' => 'https://' . $job['domain'], 'SETUP_EMAIL' => $site['email'], 'SETUP_PHONE' => $site['phone'],
        'SETUP_TIMEZONE' => $site['timezone'], 'SETUP_PALETTE' => $site['palette'], 'SETUP_PRIMARY_LANGUAGE' => $site['primary_language'],
        'SETUP_SECOND_LANGUAGE' => $site['second_language'], 'SETUP_OWNER_NAME' => $job['owner']['name'], 'SETUP_OWNER_EMAIL' => $job['owner']['email'],
        'SETUP_PASSWORD' => $password];
    $assign = '';
    foreach ($env as $k => $v) {
        $assign .= $k . '=' . deploy_q((string) $v) . ' ';
    }
    $script = 'cd ' . deploy_q($s['docroot']) . ' && if [ -f data/users.json ] && [ -n "$(ls content/pages 2>/dev/null)" ]; then echo fc-already-set-up; exit 0; fi; '
        . 'env ' . $assign . deploy_q($s['php']) . ' tools/setup.php --yes 2>&1 && test -f data/users.json && echo fc-setup-ok';
    [$code, $out, $err] = deploy_ssh($s, $script, 300);
    unset($password, $env, $assign, $script);
    if (str_contains($out, 'fc-already-set-up')) {
        return ['The site was already set up (it has pages and an owner account); left as it is.'];
    }
    if ($code !== 0 || !str_contains($out, 'fc-setup-ok')) {
        throw new DeployFailed('The set-up script did not finish (exit code ' . $code . ').', deploy_tail($out . "\n" . $err));
    }
    $lines = deploy_tail(str_replace('fc-setup-ok', '', $out), 8);
    array_unshift($lines, 'Set up "' . $site['site_name'] . '" with the owner account ' . $job['owner']['email'] . '. The owner sets their own password from the link made in a later step.');
    return $lines;
}

function deploy_step_cron(array &$job, string $credType, string $credential): array
{
    $s = $job['server'];
    $doc = $s['docroot'];
    $php = $s['php'];
    $logs = $s['home'] . '/site-backups';
    $lines = [
        "*/10 * * * * $php $doc/tools/send-updates.php >> $logs/updates.log 2>&1",
        "15 * * * * $php $doc/tools/check-links.php >> $logs/health.log 2>&1",
        "30 3 * * * /bin/sh $doc/tools/backup.sh >> $logs/backup.log 2>&1",
    ];
    $add = implode('', array_map(fn($l) => 'printf \'%s\n\' ' . deploy_q($l) . '; ', $lines));
    $script = 'mkdir -p ' . deploy_q($logs) . ' && chmod 700 ' . deploy_q($logs) . ' && '
        . '{ crontab -l 2>/dev/null | grep -vF ' . deploy_q("$doc/tools/") . '; ' . $add . '} | crontab - && echo "COUNT=$(crontab -l | grep -cF ' . deploy_q("$doc/tools/") . ')"';
    [$code, $out, $err] = deploy_ssh($s, $script, 120);
    if ($code !== 0 || !preg_match('/COUNT=(\d+)/', $out, $m) || (int) $m[1] < 3) {
        throw new DeployFailed('Could not add the cron jobs (exit code ' . $code . '). They can be added by hand in cPanel, Cron Jobs; the three lines are in the kit’s README.', deploy_tail($out . "\n" . $err));
    }
    return ['Added three cron jobs: email updates every 10 minutes, the hourly health and link check, and the nightly backup.', 'Their output goes to ' . $logs . '/.'];
}

function deploy_step_finish(array &$job, string $credType, string $credential): array
{
    $s = $job['server'];
    $info = deploy_remote_owner_link($job, true);
    $lines = [];
    $url = 'https://' . $job['domain'];
    $sites = fleet_sites();
    if (!in_array($url, array_column($sites, 'url'), true)) {
        $sites[] = ['name' => $job['site']['site_name'], 'url' => $url, 'secret' => $info['secret']];
        save_config(['fleet_sites' => $sites]);
        $lines[] = 'Made a key on the new site and listed it on this site’s Sites screen, so it is checked with the others.';
    } else {
        foreach ($sites as &$site) {
            if ($site['url'] === $url) {
                $site['secret'] = $info['secret'];
            }
        }
        unset($site);
        save_config(['fleet_sites' => $sites]);
        $lines[] = 'The new site was already on this site’s Sites screen; its key was refreshed.';
    }
    $job['result']['owner_link'] = $info['link'];
    $job['result']['owner_link_made'] = date('c');
    $lines[] = 'Made a set-password link for ' . $job['owner']['email'] . '; it works once and for one hour.';
    if ($job['owner']['send_link']) {
        try {
            deploy_email_owner($job);
            $lines[] = 'Emailed the link to ' . $job['owner']['email'] . ' from this site.';
        } catch (Throwable $e) {
            $lines[] = 'Could not email the link (' . $e->getMessage() . '). Copy it from below instead.';
        }
    }
    return $lines;
}

/** On the new site: a fleet key (new or kept) and a fresh set-password link for the owner. */
function deploy_remote_owner_link(array $job, bool $newSecret): array
{
    $code = 'require "lib/bootstrap.php"; $u = null; foreach (users() as $x) { if (($x["role"] ?? "") === "owner") { $u = $x; break; } }'
        . 'if (!$u) { fwrite(STDERR, "The new site has no owner account."); exit(1); }'
        . '$secret = (string) (config()["fleet_secret"] ?? ""); if (' . ($newSecret ? 'true' : 'false') . ' || $secret === "") { $secret = bin2hex(random_bytes(24)); save_config(["fleet_secret" => $secret]); }'
        . 'echo json_encode(["secret" => $secret, "link" => create_password_link((string) $u["id"], "invite")]);';
    [$exit, $out, $err] = deploy_ssh($job['server'], 'cd ' . deploy_q($job['server']['docroot']) . ' && ' . deploy_q($job['server']['php']) . ' -r ' . deploy_q($code), 120);
    $info = json_decode(trim((string) strrchr("\n" . trim($out), "\n")), true);
    if ($exit !== 0 || !is_array($info) || empty($info['link'])) {
        throw new DeployFailed('Could not make the keys and the owner’s link on the new site (exit code ' . $exit . ').', deploy_tail($out . "\n" . $err));
    }
    return $info;
}

/** Email the owner their set-password link from this site (the new site's own email may not be set up yet). */
function deploy_email_owner(array $job): void
{
    $site = h($job['site']['site_name']);
    $link = h((string) $job['result']['owner_link']);
    $admin = h('https://' . $job['domain'] . '/admin/');
    send_mail_to([$job['owner']['email']], 'Your website ' . $job['site']['site_name'] . ' is ready',
        '<p>Hello ' . h($job['owner']['name']) . ',</p>'
        . "<p>Your website <strong>$site</strong> is set up at <a href=\"" . h('https://' . $job['domain'] . '/') . '">' . h($job['domain']) . '</a>. '
        . 'You are its owner in the control panel, which is at <a href="' . $admin . '">' . $admin . '</a>.</p>'
        . '<p>Choose your password with this link:</p><p><a href="' . $link . '">' . $link . '</a></p>'
        . '<p>The link works once and for one hour. If it has expired, open the control panel address above, choose “Forgot your password?”, and enter ' . h($job['owner']['email']) . ' to get a new one.</p>'
        . '<p>' . h(site_name()) . (contact_phone() !== '' ? '<br>' . h(contact_phone()) : '') . '</p>');
}

function deploy_step_check(array &$job, string $credType, string $credential): array
{
    $lines = [];
    foreach (['/' => 'home page', '/admin/' => 'sign-in screen'] as $path => $what) {
        $url = 'https://' . $job['domain'] . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_USERAGENT => 'Full Circle CMS deploy']);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);
        $key = $path === '/' ? 'home_status' : 'admin_status';
        if ($errno === CURLE_SSL_CACERT || $errno === CURLE_SSL_CONNECT_ERROR || $errno === CURLE_PEER_FAILED_VERIFICATION || $errno === 60 || $errno === 35) {
            $job['result'][$key] = 'no certificate yet';
            $lines[] = "The $what is not on https yet: the domain has no certificate. cPanel’s AutoSSL usually issues one within an hour of the domain pointing at the server.";
        } elseif ($errno !== 0) {
            $job['result'][$key] = 'unreachable';
            $lines[] = "Could not open the $what at $url (" . curl_strerror($errno) . '). If the domain was just pointed at this server, DNS may take a while.';
        } elseif ($code === 200 && is_string($body) && str_contains($body, $path === '/' ? '<html' : 'Sign in')) {
            $job['result'][$key] = 'ok';
            $lines[] = ucfirst($what) . " opens at $url.";
        } else {
            $job['result'][$key] = "status $code";
            $lines[] = "The $what at $url answered with status $code. Open it in a browser to see what is there.";
        }
    }
    return $lines;
}
