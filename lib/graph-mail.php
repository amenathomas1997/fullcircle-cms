<?php
/**
 * Talking to Microsoft Graph with an app registration (client credentials
 * flow): the token, plain requests, and whether the app details are filled in.
 * Mail.Send permission is needed to send email this way; see README.md.
 */

declare(strict_types=1);

/** @return array{int, array} HTTP status and decoded JSON body. */
function graph_http(string $method, string $url, array $headers, string $body): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        throw new RuntimeException("Could not reach Microsoft: $error");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$status, json_decode((string) $response, true) ?? []];
}

function graph_token(): string
{
    $cacheFile = DATA_DIR . '/graph-token.php';
    $cached = is_file($cacheFile) ? include $cacheFile : null;
    $c = config();
    if (is_array($cached) && $cached['expires'] > time() + 60 && $cached['client'] === $c['graph_client_id']) {
        return $cached['token'];
    }
    [$status, $json] = graph_http(
        'POST',
        'https://login.microsoftonline.com/' . rawurlencode($c['graph_tenant_id']) . '/oauth2/v2.0/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'client_id' => $c['graph_client_id'],
            'client_secret' => $c['graph_client_secret'],
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ])
    );
    if ($status !== 200 || empty($json['access_token'])) {
        throw new RuntimeException('Microsoft sign-in failed: ' . ($json['error_description'] ?? "HTTP $status"));
    }
    write_file($cacheFile, '<?php return ' . var_export([
        'token' => $json['access_token'],
        'expires' => time() + (int) ($json['expires_in'] ?? 3000),
        'client' => $c['graph_client_id'],
    ], true) . ';');
    @chmod($cacheFile, 0600);
    return $json['access_token'];
}

/** Whether the Microsoft app details are filled in (enough to call Graph, not necessarily to email). */
function graph_app_configured(): bool
{
    $c = config();
    return $c['graph_tenant_id'] !== '' && $c['graph_client_id'] !== '' && $c['graph_client_secret'] !== '';
}

/** Call Microsoft Graph as the app. Returns the decoded answer; throws with Microsoft's reason. */
function graph_request(string $method, string $path, ?array $body = null): array
{
    [$status, $json] = graph_http(
        $method,
        'https://graph.microsoft.com/v1.0' . $path,
        ['Authorization: Bearer ' . graph_token(), 'Content-Type: application/json'],
        $body === null ? '' : json_encode($body)
    );
    if ($status >= 300) {
        throw new RuntimeException('Microsoft Graph: ' . ($json['error']['message'] ?? "HTTP $status"));
    }
    return $json;
}
