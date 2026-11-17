<?php
/**
 * Alt text help from Claude. An uploaded image is sent to Claude, which answers
 * with a one-sentence alt text and a longer description in the site's language.
 * Used by the Blocks editor's image block ("Suggest a description") and by the
 * Images and files screen ("Describe"), which also remembers descriptions in
 * data/alt-text.json so the editor can offer them later. Needs the Anthropic
 * API key from Settings and the SDK in vendor/ (the same two things the Ask a
 * question assistant needs); without them the buttons are not shown. Calls are
 * counted in data/alt-text-usage.json and capped at 200 a day.
 */

declare(strict_types=1);

use Anthropic\Client as AnthropicClient;

const ALT_TEXT_FILE = DATA_DIR . '/alt-text.json';
const ALT_TEXT_USAGE_FILE = DATA_DIR . '/alt-text-usage.json';
const ALT_TEXT_DAILY_LIMIT = 200;
const ALT_TEXT_MAX_BYTES = 5 * 1024 * 1024;
const ALT_TEXT_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

// A control panel address that answers JSON: GET says whether help is available and lists saved descriptions;
// POST {url, lang} describes one uploaded image. No menu label, so it never appears in the navigation.
register_admin_screen('alt_text', fn (bool $post) => alt_text_endpoint($post));

/** Why descriptions from Claude aren't available, in one line, or null when they are. */
function alt_text_unavailable_reason(): ?string
{
    if (!is_file(ROOT . '/vendor/autoload.php')) {
        return 'Descriptions from Claude are off because the Anthropic PHP SDK is not installed. Whoever hosts the site can run composer install.';
    }
    if ((config()['anthropic_api_key'] ?? '') === '') {
        return 'Descriptions from Claude are off. To turn them on, add an Anthropic API key under Settings, then Ask a question assistant.';
    }
    return null;
}

function alt_text_available(): bool
{
    return alt_text_unavailable_reason() === null;
}

/* ---------- Saved descriptions ---------- */

/** Everything remembered so far: [url => ['alt' => …, 'description' => …, 'time' => …]]. */
function alt_text_saved(): array
{
    return read_json(ALT_TEXT_FILE) ?? [];
}

/** Just the alt text for each image, for the Blocks editor to pre-fill. */
function alt_text_known(): array
{
    $out = [];
    foreach (alt_text_saved() as $url => $entry) {
        if (is_array($entry) && ($entry['alt'] ?? '') !== '') {
            $out[(string) $url] = (string) $entry['alt'];
        }
    }
    return $out;
}

function alt_text_remember(string $url, string $alt, string $description): void
{
    $all = alt_text_saved();
    $all[$url] = ['alt' => $alt, 'description' => $description, 'time' => date('c')];
    write_json(ALT_TEXT_FILE, $all);
    @chmod(ALT_TEXT_FILE, 0600);
}

/** Count one call against today's limit. False when the day's 200 are used up. */
function alt_text_count_call(): bool
{
    $fh = @fopen(ALT_TEXT_USAGE_FILE, 'c+');
    if (!$fh) {
        return true;
    }
    try {
        flock($fh, LOCK_EX);
        $usage = json_decode((string) stream_get_contents($fh), true);
        $usage = is_array($usage) ? $usage : [];
        $today = date('Y-m-d');
        // Keep a week of daily counts so the hosting person can see how much it is used.
        $usage = array_filter($usage, fn($k) => $k >= date('Y-m-d', strtotime('-7 days')), ARRAY_FILTER_USE_KEY);
        if (($usage[$today] ?? 0) >= ALT_TEXT_DAILY_LIMIT) {
            return false;
        }
        $usage[$today] = ($usage[$today] ?? 0) + 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($usage, JSON_PRETTY_PRINT) . "\n");
        fflush($fh);
        flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    @chmod(ALT_TEXT_USAGE_FILE, 0600);
    return true;
}

/* ---------- Asking Claude ---------- */

/** Map a /uploads/... address to a real image file, refusing anything outside uploads. */
function alt_text_image_path(string $url): ?string
{
    if (!preg_match('#^/uploads/[A-Za-z0-9/_.-]+$#', $url) || str_contains($url, '..') || preg_match('#/\.#', $url)) {
        return null;
    }
    $path = realpath(ROOT . $url);
    $root = realpath(UPLOAD_DIR);
    return $path && $root && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path) ? $path : null;
}

/**
 * Describe one image file. Returns ['alt' => …, 'description' => …] or ['error' => plain words].
 * $lang is 'en' or 'es': the language the words come back in.
 */
function describe_image(string $path, string $lang = 'en'): array
{
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    if (!in_array($mime, ALT_TEXT_TYPES, true)) {
        return ['error' => 'Only JPEG, PNG, GIF and WebP images can be described.'];
    }
    if (filesize($path) > ALT_TEXT_MAX_BYTES) {
        return ['error' => 'That image is bigger than 5 MB, so it can’t be described. Upload a smaller copy and describe that one.'];
    }
    if (!alt_text_count_call()) {
        return ['error' => 'Today’s limit of ' . ALT_TEXT_DAILY_LIMIT . ' descriptions has been reached. Try again tomorrow.'];
    }
    $language = $lang === 'es' ? 'Spanish' : 'English';
    $about = trim((string) settings()['description']);
    $prompt = "This image will appear on the website of " . legal_name() . ($about !== '' ? ", described as: $about" : '') . ". Many visitors use screen readers.\n\n"
        . "Write, in $language:\n"
        . "1. \"alt\": one sentence of alt text, under 125 characters, saying what the picture shows and why it matters on this page. "
        . "Do not start with \"Image of\" or \"Picture of\". If the picture is mostly text, give that text.\n"
        . "2. \"description\": a longer description of two to four sentences with the detail a person who cannot see the picture would want.\n\n"
        . "Do not guess names of people. Reply with JSON only, in exactly this shape and nothing else: {\"alt\": \"...\", \"description\": \"...\"}";

    require_once ROOT . '/vendor/autoload.php';
    $client = new AnthropicClient(apiKey: (string) config()['anthropic_api_key']);
    try {
        $message = $client->beta->messages->create(
            model: ASSISTANT_MODEL,
            maxTokens: 600,
            messages: [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode((string) file_get_contents($path))]],
                ['type' => 'text', 'text' => $prompt],
            ]]],
            outputConfig: ['effort' => 'low'],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
            requestOptions: ['timeout' => 45, 'maxRetries' => 1],
        );
    } catch (\Anthropic\Core\Exceptions\RateLimitException) {
        return ['error' => 'Claude is busy right now. Try again in a minute.'];
    } catch (\Throwable $e) {
        error_log('Alt text error: ' . $e->getMessage());
        return ['error' => 'The description could not be fetched just now. Try again in a moment; if it keeps failing, check the API key in Settings.'];
    }
    if (($message->stopReason ?? '') === 'refusal') {
        return ['error' => 'Claude would not describe this image. Write the description yourself.'];
    }
    $text = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $text .= $block->text;
        }
    }
    return alt_text_parse($text);
}

/** Pull the alt and description out of Claude's reply, which should be JSON but may be wrapped in a code fence. */
function alt_text_parse(string $text): array
{
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    $json = $start !== false && $end !== false && $end > $start ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
    $alt = trim((string) preg_replace('/\s+/u', ' ', (string) ($json['alt'] ?? '')));
    $description = trim((string) preg_replace('/\s+/u', ' ', (string) ($json['description'] ?? '')));
    if ($alt === '') {
        // Not JSON after all: use the first sentence as the alt text and the rest as the description.
        $plain = trim((string) preg_replace('/\s+/u', ' ', $text));
        $parts = preg_split('/(?<=[.!?])\s+/u', $plain, 2) ?: [];
        $alt = trim((string) ($parts[0] ?? ''));
        $description = trim((string) ($parts[1] ?? ''));
    }
    if ($alt === '') {
        return ['error' => 'No description came back. Try again in a moment.'];
    }
    return ['alt' => mb_substr($alt, 0, 300), 'description' => mb_substr($description, 0, 1500)];
}

/* ---------- The control panel address ---------- */

function alt_text_endpoint(bool $post): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$post) {
        echo json_encode(['available' => alt_text_available(), 'reason' => alt_text_unavailable_reason(), 'known' => (object) alt_text_known()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    // admin/index.php has already checked the CSRF token (from the X-CSRF-Token header for JSON requests).
    $input = json_decode((string) file_get_contents('php://input'), true);
    $url = trim((string) (is_array($input) ? ($input['url'] ?? '') : ($_POST['url'] ?? '')));
    $lang = (is_array($input) ? ($input['lang'] ?? '') : ($_POST['lang'] ?? '')) === 'es' ? 'es' : 'en';
    $fail = function (string $message, int $code = 400): never {
        http_response_code($code);
        echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (($reason = alt_text_unavailable_reason()) !== null) {
        $fail($reason, 503);
    }
    // Only the site's own uploads can be described: a full address on this site is trimmed to its path.
    if (preg_match('#^https?://#i', $url)) {
        $url = (string) parse_url($url, PHP_URL_PATH);
    }
    $path = alt_text_image_path($url);
    if (!$path) {
        $fail('That image is not in Images and files. Upload it first, then ask for a description.', 404);
    }
    $result = describe_image($path, $lang);
    if (isset($result['error'])) {
        $fail($result['error'], 502);
    }
    alt_text_remember($url, $result['alt'], $result['description']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- The Images and files screen (media_list() in admin/index.php calls these when they exist) ---------- */

/** One line under the upload form: how to use Describe, or why it is off. Also the live region the buttons announce into. */
function alt_text_media_note(): string
{
    $reason = alt_text_unavailable_reason();
    return '<p>' . h($reason ?? 'Choose Describe next to a picture and Claude suggests a description. It is kept here and offered as the alt text when you add that picture in the Blocks editor.') . '</p>'
        . '<p id="alt-text-status" class="visually-hidden" role="status"></p>';
}

/** The Description cell for one file: the saved description in a text field to copy from, and the Describe button. */
function alt_text_media_cell(array $file): string
{
    if (empty($file['image'])) {
        return '';
    }
    $url = (string) $file['url'];
    $name = (string) $file['name'];
    $id = 'alt-' . substr(md5($url), 0, 8);
    $saved = alt_text_saved()[$url] ?? [];
    $alt = (string) ($saved['alt'] ?? '');
    $more = (string) ($saved['description'] ?? '');
    return '<div class="alt-describe">'
        . '<label for="' . $id . '" class="visually-hidden">Description of ' . h($name) . '</label>'
        . '<textarea id="' . $id . '" rows="2" readonly data-alt-for="' . h($url) . '" aria-describedby="' . $id . '-more">' . h($alt) . '</textarea>'
        . '<p class="hint" id="' . $id . '-more">' . h($more) . '</p>'
        . (alt_text_available() ? '<button type="button" class="button-link" data-describe="' . h($url) . '" data-alt-target="' . $id . '">Describe<span class="visually-hidden"> ' . h($name) . '</span></button> ' : '')
        . '<button type="button" class="button-link" data-copy="' . h($alt) . '" data-copy-target="' . $id . '"' . ($alt === '' ? ' hidden' : '') . '>Copy description<span class="visually-hidden"> of ' . h($name) . '</span></button>'
        . '</div>';
}

/** The script that makes the Describe buttons work. */
function alt_text_media_scripts(): string
{
    return alt_text_available() ? '<script src="/admin/alt-text.js?v=' . (@filemtime(ROOT . '/admin/alt-text.js') ?: 1) . '" defer></script>' : '';
}
