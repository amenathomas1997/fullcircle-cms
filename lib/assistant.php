<?php
/**
 * [ask-assistant]: visitors ask a question and get an answer written by Claude
 * from this website's own content (the FAQ, plus the pages, posts and cheat
 * sheets that best match the question). Off until an Anthropic API key is
 * added in Settings. Questions aren't stored. Uses Anthropic's PHP SDK.
 */

declare(strict_types=1);

use Anthropic\Client as AnthropicClient;

const ASSISTANT_MODEL = 'claude-opus-5-5';
const ASSISTANT_RATE_FILE = DATA_DIR . '/assistant-rate.json';

function assistant_enabled(): bool
{
    return feature_on('assistant') && !empty(config()['assistant_enabled']) && (config()['anthropic_api_key'] ?? '') !== '' && is_file(ROOT . '/vendor/autoload.php');
}

/** At most 10 questions an hour from one visitor, and 300 a day from everyone, so a flood can't run up the bill. */
function assistant_rate_limited(): bool
{
    $key = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . site_secret());
    $daily = max(1, (int) (config()['assistant_daily_limit'] ?? 300));
    return rate_limited(ASSISTANT_RATE_FILE, $key, 10, 3600) || rate_limited(ASSISTANT_RATE_FILE, '_everyone', $daily, 86400);
}

/** The website content most relevant to the question, as [url => text]. The FAQ is always included. */
function assistant_sources(string $question): array
{
    $sources = [];
    $faqSlug = current_lang() === 'es' ? 'es-preguntas-frecuentes' : 'faq';
    if ($faq = get_item('pages', $faqSlug)) {
        $sources[page_path($faqSlug)] = $faq['title'] . "\n" . plain_text((string) $faq['body']);
    }
    // Look for the question's longer words, so "how much does it cost" still finds the rates.
    $words = array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)), fn($w) => mb_strlen($w) > 3);
    $found = [];
    foreach ($words as $w) {
        foreach (site_search($w) as $r) {
            $found[$r['url']] = ($found[$r['url']] ?? 0) + $r['score'];
        }
    }
    arsort($found);
    foreach (array_slice(array_keys($found), 0, 4) as $url) {
        if (isset($sources[$url])) {
            continue;
        }
        $text = '';
        foreach (array_merge(all_items('pages'), all_items('posts'), all_items('resources')) as $item) {
            $itemUrl = isset($item['date']) ? post_url($item) : (str_starts_with($url, '/resources/') ? '/resources/' . $item['slug'] . '/' : page_path($item['slug']));
            if ($itemUrl === $url && ($item['status'] ?? '') === 'published') {
                $text = $item['title'] . "\n" . plain_text(preg_replace('/\[[a-z-]+\]/', '', (string) ($item['body'] ?? '')));
            }
        }
        if ($text !== '') {
            $sources[$url] = mb_substr($text, 0, 8000);
        }
    }
    return $sources;
}

/** What Claude is told about the site, built from Settings. */
function assistant_system(): string
{
    $about = trim((string) settings()['description']);
    return 'You answer questions from visitors to the website of ' . legal_name() . ($about !== '' ? ', described as: ' . $about : '') . ". Many visitors use screen readers.

"
        . "Answer only from the website content provided in the user's message. If the content doesn't answer the question, say plainly that you don't know, and suggest they "
        . contact_words('en') . ". Never guess prices, policies or availability, and never promise anything on the site owner's behalf.

"
        . "You can't book anything, see anyone's records or take personal details. If someone shares health or personal information, don't repeat it.

"
        . 'Write in the same language as the question (English or Spanish). Keep answers short: two to five sentences, or a short list using "- " at the start of each item. '
        . 'Plain text only: no headings, bold, tables or other formatting. When a page on the website helps, name it and give its address exactly as shown in the content, for example /about/.';
}

/** Ask Claude. Returns ['answer' => text, 'sources' => [url => title]] or ['error' => text]. */
function ask_assistant(string $question): array
{
    require_once ROOT . '/vendor/autoload.php';
    $sources = assistant_sources($question);
    $context = '';
    foreach ($sources as $url => $text) {
        $context .= "<page address=\"$url\">\n$text\n</page>\n";
    }
    $client = new AnthropicClient(apiKey: (string) config()['anthropic_api_key']);
    try {
        $message = $client->beta->messages->create(
            model: ASSISTANT_MODEL,
            maxTokens: 2000,
            system: [['type' => 'text', 'text' => assistant_system(), 'cacheControl' => ['type' => 'ephemeral']]],
            messages: [['role' => 'user', 'content' => "Website content:\n$context\nVisitor's question:\n$question"]],
            outputConfig: ['effort' => 'low'],
            fallbacks: 'default',
            betas: ['server-side-fallback-2026-07-01'],
            // Don't hold a PHP worker on the shared host for long: one retry, 45 seconds at most.
            requestOptions: ['timeout' => 45, 'maxRetries' => 1],
        );
    } catch (\Anthropic\Core\Exceptions\RateLimitException) {
        return ['error' => 'busy'];
    } catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
        error_log('Assistant error: ' . $e->getMessage());
        return ['error' => 'failed'];
    } catch (\Anthropic\Core\Exceptions\APIConnectionException $e) {
        error_log('Assistant connection error: ' . $e->getMessage());
        return ['error' => 'failed'];
    } catch (\Throwable $e) {
        // Anything else (a library problem, an unexpected answer shape) still gets the friendly message.
        error_log('Assistant unexpected error: ' . $e->getMessage());
        return ['error' => 'failed'];
    }
    if (($message->stopReason ?? '') === 'refusal') {
        return ['error' => 'refused'];
    }
    $answer = '';
    foreach ($message->content as $block) {
        if ($block->type === 'text') {
            $answer .= $block->text;
        }
    }
    $titles = [];
    foreach (array_keys($sources) as $url) {
        $titles[$url] = strtok($sources[$url], "\n");
    }
    return trim($answer) === '' ? ['error' => 'failed'] : ['answer' => trim($answer), 'sources' => $titles];
}

/** Turn the plain-text answer into safe HTML: paragraphs, "- " lists, and links to this site's own pages. */
function assistant_answer_html(string $answer): string
{
    $html = '';
    foreach (preg_split('/\R{2,}/', $answer) as $block) {
        $lines = preg_split('/\R/', trim($block));
        $isList = count(array_filter($lines, fn($l) => preg_match('/^\s*[-•]\s+/', $l))) === count($lines);
        $render = function (string $text): string {
            $text = h($text);
            return (string) preg_replace_callback('#(?<![\w/])(/[a-z0-9][a-z0-9/-]*/)#', fn($m) => '<a href="' . $m[1] . '">' . $m[1] . '</a>', $text);
        };
        if ($isList) {
            $html .= '<ul>' . implode('', array_map(fn($l) => '<li>' . $render(preg_replace('/^\s*[-•]\s+/', '', $l)) . '</li>', $lines)) . '</ul>';
        } else {
            $html .= '<p>' . implode('<br>', array_map($render, $lines)) . '</p>';
        }
    }
    return $html;
}

function render_assistant(string $path): string
{
    $es = current_lang() === 'es';
    if (!assistant_enabled()) {
        return '<p>' . ($es ? 'Este asistente todavía no está disponible. Mientras tanto, lea las <a href="/es/preguntas-frecuentes/">preguntas frecuentes</a> o <a href="/es/contacto/">contáctenos</a>.'
            : 'This assistant isn’t available yet. In the meantime, read the <a href="/faq/">frequently asked questions</a> or <a href="/contact-us/">contact us</a>.') . '</p>';
    }
    $question = '';
    $result = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ask_form'])) {
        $question = trim(mb_substr((string) ($_POST['question'] ?? ''), 0, 600));
        $botOrForged = ($_POST['website'] ?? '') !== '' || !contact_token_ok((string) ($_POST['form_token'] ?? ''));
        $reply = match (true) {
            $botOrForged => ['error' => 'failed'],
            $question === '' => ['error' => 'empty'],
            assistant_rate_limited() => ['error' => 'limit'],
            default => ask_assistant($question),
        };
        if (isset($reply['answer'])) {
            $links = '';
            foreach ($reply['sources'] as $url => $title) {
                $links .= '<li><a href="' . h($url) . '">' . h($title) . '</a></li>';
            }
            $result = '<section class="checker-result" id="answer" tabindex="-1" data-focus-on-load aria-labelledby="answer-heading">'
                . '<h2 id="answer-heading">' . ($es ? 'Respuesta' : 'Answer') . '</h2>' . assistant_answer_html($reply['answer'])
                . ($links ? '<h3>' . ($es ? 'Páginas usadas para responder' : 'Pages used for this answer') . '</h3><ul>' . $links . '</ul>' : '')
                . '<p class="post-meta">' . ($es ? 'Respuesta escrita por IA a partir de este sitio web. Si algo es importante, confírmelo con nosotros.'
                    : 'Written by AI from this website. If something matters, please check with us.') . '</p></section>';
        } else {
            $msg = match ($reply['error']) {
                'empty' => $es ? 'Escriba su pregunta.' : 'Type your question.',
                'limit' => $es ? 'Ha hecho muchas preguntas en la última hora. Intente más tarde, o ' . contact_words('es') . '.' : 'You’ve asked several questions in the last hour. Please try again later, or ' . contact_words('en') . '.',
                'busy' => $es ? 'El asistente está ocupado. Intente de nuevo en un minuto.' : 'The assistant is busy. Please try again in a minute.',
                'refused' => $es ? 'El asistente no puede responder esa pregunta. Por favor ' . contact_words('es') . '.' : 'The assistant can’t answer that question. Please ' . contact_words('en') . '.',
                default => $es ? 'No se pudo obtener una respuesta. Intente de nuevo, o ' . contact_words('es') . '.' : 'We couldn’t get an answer just now. Please try again, or ' . contact_words('en') . '.',
            };
            $result = '<div class="error-summary" id="answer" role="alert" tabindex="-1" data-focus-on-load><p>' . h($msg) . '</p></div>';
        }
    }
    return $result
        . '<form class="contact-form" method="post" action="' . h($path) . '#answer">'
        . '<div class="field"><label for="question">' . ($es ? 'Su pregunta' : 'Your question') . '</label>'
        . '<p class="hint" id="question-hint">' . ($es ? 'Por ejemplo: “¿Mi capacitación es gratis?” No incluya información personal ni de salud.'
            : 'For example: “Is my training free?” Please don’t include personal or health information.') . '</p>'
        . '<textarea id="question" name="question" rows="4" maxlength="600" aria-describedby="question-hint">' . h($question) . '</textarea></div>'
        . '<div class="hp-field" aria-hidden="true"><label for="a-website">Leave this empty</label><input id="a-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
        . '<input type="hidden" name="form_token" value="' . h(contact_form_token()) . '"><input type="hidden" name="ask_form" value="1">'
        . '<button type="submit" class="button">' . ($es ? 'Preguntar' : 'Ask') . '</button></form>';
}
