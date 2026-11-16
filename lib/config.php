<?php
/**
 * Private settings: the control panel password and the Microsoft Graph
 * credentials for the contact form. Stored as a PHP file in data/ so the
 * values can never be read over the web, even if .htaccess rules are ignored.
 */

declare(strict_types=1);

const CONFIG_FILE = DATA_DIR . '/config.php';

function config(bool $reload = false): array
{
    static $config = null;
    if ($config === null || $reload) {
        $loaded = is_file(CONFIG_FILE) ? include CONFIG_FILE : [];
        $config = (is_array($loaded) ? $loaded : []) + [
            'password_hash' => '',
            'graph_tenant_id' => '',
            'graph_client_id' => '',
            'graph_client_secret' => '',
            'graph_sender' => '',
            'mail_method' => 'php',
            'mail_from' => '',
            'mail_from_name' => '',
            'contact_recipients' => '',
            'contact_subject' => 'New message from the website',
        ];
    }
    return $config;
}

function save_config(array $changes): void
{
    $config = array_merge(config(), $changes);
    write_file(CONFIG_FILE, "<?php\n// Written by the control panel. Keep this file private.\nreturn " . var_export($config, true) . ";\n");
    @chmod(CONFIG_FILE, 0600);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate(CONFIG_FILE, true);
    }
    config(true);
}
