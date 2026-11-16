<?php
/**
 * Shortcodes: type these anywhere in a page's HTML.
 *   [contact-form]          the contact form
 *   [testimonials]          approved testimonials sent in by clients
 *   [testimonial-form]      a form for clients to send a testimonial
 *   [cheat-sheets]          the list of cheat sheets
 *   [practice-form]         a practice form for screen reader users (nothing is sent or saved)
 *   [subscribe-form]        sign up for new posts and cheat sheets by email
 *   [next-opening]          the next open time in the Microsoft Bookings calendar
 *   [screen-reader-guide]   "Which screen reader is right for me?" questions and suggestions
 *   [ask-assistant]         ask a question, answered by Claude from this website's content
 */

declare(strict_types=1);

const SHORTCODES = ['[contact-form]', '[testimonials]', '[testimonial-form]', '[cheat-sheets]', '[practice-form]', '[subscribe-form]', '[next-opening]', '[screen-reader-guide]', '[ask-assistant]'];

/** Handle form posts, then swap each shortcode for its HTML. */
function render_shortcodes(string $body, string $path): string
{
    if (!str_contains($body, '[')) {
        return $body;
    }
    $post = $_SERVER['REQUEST_METHOD'] === 'POST';
    $contactState = $testimonialState = [];
    if ($post && isset($_POST['contact_form']) && str_contains($body, '[contact-form]')) {
        $contactState = handle_contact_post($path);
    }
    if ($post && isset($_POST['testimonial_form']) && str_contains($body, '[testimonial-form]')) {
        $testimonialState = handle_testimonial_post($path);
    }
    $subscribeState = [];
    if ($post && isset($_POST['subscribe_form']) && str_contains($body, '[subscribe-form]')) {
        $subscribeState = handle_subscribe_post($path);
    }
    // Shortcodes for features that are turned off show nothing.
    $needs = ['[testimonials]' => 'testimonials', '[testimonial-form]' => 'testimonials', '[cheat-sheets]' => 'resources',
        '[subscribe-form]' => 'updates', '[next-opening]' => 'bookings', '[ask-assistant]' => 'assistant'];
    $replace = [];
    foreach (SHORTCODES as $code) {
        if (str_contains($body, $code) && isset($needs[$code]) && !feature_on($needs[$code])) {
            $replace[$code] = '';
        } elseif (str_contains($body, $code)) {
            $replace[$code] = match ($code) {
                '[contact-form]' => render_contact_form($contactState),
                '[testimonials]' => render_testimonials(),
                '[testimonial-form]' => render_testimonial_form($testimonialState),
                '[cheat-sheets]' => resource_list_html(published_resources()),
                '[practice-form]' => render_practice_form($path),
                '[subscribe-form]' => render_subscribe_form($subscribeState),
                '[next-opening]' => render_next_opening(),
                '[screen-reader-guide]' => render_screen_reader_guide($path),
                '[ask-assistant]' => render_assistant($path),
            };
        }
    }
    // Shortcodes on a line of their own are often wrapped in <p> by editors; drop the wrapper.
    // Each shortcode is shown once: a repeat would duplicate form ids, which breaks labels for screen readers.
    foreach (registered('shortcodes') as $code => $render) {
        if (str_contains($body, $code)) {
            $replace[$code] = (string) $render($path);
        }
    }
    foreach ($replace as $code => $html) {
        $body = str_replace("<p>$code</p>", $code, $body);
        $at = strpos($body, $code);
        if ($at !== false) {
            $body = substr_replace($body, $html, $at, strlen($code));
        }
        $body = str_replace($code, '', $body);
    }
    return $body;
}

/* ---------- Screen reader practice form ---------- */

/** Sent to the same page as a normal form; the answers are only shown back, never stored. */
function render_practice_form(string $path): string
{
    $sent = isset($_GET['practice']);
    $v = [
        'name' => trim((string) ($_GET['p_name'] ?? '')),
        'color' => (string) ($_GET['p_color'] ?? ''),
        'contact' => (string) ($_GET['p_contact'] ?? ''),
        'news' => !empty($_GET['p_news']),
        'note' => trim((string) ($_GET['p_note'] ?? '')),
    ];
    $colors = ['' => 'Choose a color', 'blue' => 'Blue', 'green' => 'Green', 'purple' => 'Purple', 'yellow' => 'Yellow'];
    $contacts = ['phone' => 'Phone call', 'text' => 'Text message', 'email' => 'Email'];
    $out = '<div id="practice-result">';
    if ($sent && $v['name'] === '') {
        $out .= '<div class="error-summary" role="alert" tabindex="-1" data-focus-on-load><h3>There is a problem (this is practice too)</h3>'
            . '<ul><li><a href="#p_name">Enter your first name.</a></li></ul>'
            . '<p>Follow the link to jump to the field. This is how most well-built forms tell you about a mistake.</p></div>';
    } elseif ($sent) {
        $out .= '<div class="notice notice-success" role="status" tabindex="-1" data-focus-on-load><h3>You sent the practice form</h3><ul>'
            . '<li>First name: ' . h(mb_substr($v['name'], 0, 60)) . '</li>'
            . '<li>Favorite color: ' . h($colors[$v['color']] ?? 'not chosen') . '</li>'
            . '<li>Best way to reach you: ' . h($contacts[$v['contact']] ?? 'not chosen') . '</li>'
            . '<li>Newsletter: ' . ($v['news'] ? 'yes' : 'no') . '</li></ul>'
            . '<p>Nothing was saved or sent. <a href="' . h($path) . '#practice-result">Try again</a>.</p></div>';
    }
    $nameErr = $sent && $v['name'] === '';
    $opts = '';
    foreach ($colors as $val => $label) {
        $opts .= '<option value="' . h($val) . '"' . ($v['color'] === $val ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $radios = '';
    foreach ($contacts as $val => $label) {
        $radios .= '<div class="option"><input type="radio" id="p_contact_' . $val . '" name="p_contact" value="' . $val . '"' . ($v['contact'] === $val ? ' checked' : '') . '>'
            . '<label for="p_contact_' . $val . '">' . h($label) . '</label></div>';
    }
    $out .= '</div><form class="contact-form practice-form" method="get" action="' . h($path) . '#practice-result">'
        . '<input type="hidden" name="practice" value="1">'
        . '<div class="field' . ($nameErr ? ' field-error' : '') . '"><label for="p_name">First name</label>'
        . ($nameErr ? '<p class="error-message" id="p_name-error"><span class="visually-hidden">Error: </span>Enter your first name.</p>' : '')
        . '<input type="text" id="p_name" name="p_name" autocomplete="off" value="' . h($v['name']) . '"' . ($nameErr ? ' aria-invalid="true" aria-describedby="p_name-error"' : '') . '></div>'
        . '<div class="field"><label for="p_color">Favorite color</label><select id="p_color" name="p_color">' . $opts . '</select></div>'
        . '<fieldset class="field"><legend>Best way to reach you</legend>' . $radios . '</fieldset>'
        . '<div class="field checkbox-field"><input type="checkbox" id="p_news" name="p_news" value="1"' . ($v['news'] ? ' checked' : '') . '>'
        . '<label for="p_news">Send me the practice newsletter</label></div>'
        . '<div class="field"><label for="p_note">Anything else?</label><textarea id="p_note" name="p_note" rows="4">' . h($v['note']) . '</textarea></div>'
        . '<button type="submit" class="button">Send practice form</button></form>';
    return $out;
}
