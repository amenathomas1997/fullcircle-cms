<?php
/**
 * Footer: a form-based footer editor, so nobody has to write HTML for the
 * bottom of the site.
 *
 * Settings hold the footer as "footer_groups" (and "footer_groups_es" for
 * pages in the site's second language, whatever it is):
 *   ['contact' => true,                      show a Contact column from the site's contact details
 *    'groups' => [['heading' => 'More', 'links' => [['label' => 'About', 'url' => '/about/'], …]], …],
 *    'small_print' => 'Registered charity 123456']   one plain-text line under the columns
 * The copyright line stays in the site template ({{copyright}} and {{year}}).
 *
 * render_footer() builds the {{footer}} placeholder from those groups. A site
 * that has never used the Footer screen keeps its old raw "footer_html".
 */

declare(strict_types=1);

const FOOTER_MAX_GROUPS = 6;
const FOOTER_MAX_LINKS = 20;

register_admin_screen('footer', fn (bool $post) => $post ? footer_save() : footer_form(), 'Footer', null);

/* ---------- Reading the settings ---------- */

/** The footer groups for a language, in a clean shape, or null when that language has none saved. */
function footer_groups(?string $lang = null): ?array
{
    $raw = settings()[is_second_lang($lang ?? primary_lang()) ? 'footer_groups_es' : 'footer_groups'] ?? null;
    if (!is_array($raw)) {
        return null;
    }
    $groups = [];
    foreach ((array) ($raw['groups'] ?? []) as $g) {
        $links = [];
        foreach ((array) ($g['links'] ?? []) as $l) {
            $links[] = ['label' => trim((string) ($l['label'] ?? '')), 'url' => trim((string) ($l['url'] ?? ''))];
        }
        $groups[] = ['heading' => trim((string) ($g['heading'] ?? '')), 'links' => $links];
    }
    return ['contact' => !empty($raw['contact']), 'groups' => $groups, 'small_print' => trim((string) ($raw['small_print'] ?? ''))];
}

/** True when the Footer screen has something to show for this language: a contact column or at least one group. */
function footer_groups_active(?string $lang = null): bool
{
    $fg = footer_groups($lang);
    return $fg !== null && ($fg['contact'] || $fg['groups']);
}

/* ---------- Public rendering ---------- */

/**
 * The HTML for {{footer}}. Groups from the Footer screen come first; a site without them keeps the
 * raw footer HTML from Site template. Second-language pages use their own footer when there is one.
 */
function render_footer(string $lang): string
{
    $s = settings();
    if (is_second_lang($lang)) {
        if (footer_groups_active($lang)) {
            return footer_groups_html($lang);
        }
        if (($s['footer_html_es'] ?? '') !== '') {
            return (string) $s['footer_html_es'];
        }
    }
    return footer_groups_active(primary_lang()) ? footer_groups_html(primary_lang()) : (string) ($s['footer_html'] ?? '');
}

function footer_groups_html(string $lang): string
{
    $fg = footer_groups($lang) ?? ['contact' => false, 'groups' => [], 'small_print' => ''];
    $out = '';
    if ($fg['contact']) {
        $out .= '<section aria-labelledby="footer-contact"><h2 id="footer-contact">' . h(t('footer_contact')) . '</h2>' . footer_contact_html($lang) . '</section>';
    }
    foreach ($fg['groups'] as $i => $group) {
        $items = '';
        foreach ($group['links'] as $link) {
            if ($link['label'] === '' || $link['url'] === '') {
                continue;
            }
            $items .= '<li><a href="' . h($link['url']) . '"' . footer_link_lang($link['url'], $lang) . '>' . h($link['label']) . '</a></li>';
        }
        if ($items === '' && $group['heading'] === '') {
            continue;
        }
        $id = 'footer-group-' . ($i + 1);
        $out .= '<section aria-labelledby="' . $id . '"><h2 id="' . $id . '">' . h($group['heading'] !== '' ? $group['heading'] : t('footer_links')) . '</h2>'
            . ($items !== '' ? "<ul>$items</ul>" : '') . '</section>';
    }
    $html = $out !== '' ? '<div class="footer-columns">' . $out . '</div>' : '';
    if ($fg['small_print'] !== '') {
        $html .= '<p class="small-print">' . h($fg['small_print']) . '</p>';
    }
    return $html;
}

/** The Contact column: phone and email from Settings, or a link to the contact page when neither is set. */
function footer_contact_html(string $lang): string
{
    $lines = [];
    if (contact_phone() !== '') {
        $lines[] = h(t('footer_call')) . ' <a href="tel:' . h(preg_replace('/[^0-9+]/', '', contact_phone())) . '">' . h(contact_phone()) . '</a>';
    }
    if (contact_email() !== '') {
        $lines[] = h(t('footer_email')) . ' <a href="mailto:' . h(contact_email()) . '">' . h(contact_email()) . '</a>';
    }
    if (!$lines) {
        return '<p><a href="' . h(contact_path($lang)) . '">' . h(t('footer_contact_us')) . '</a></p>';
    }
    return '<p>' . implode('<br>', $lines) . '</p>';
}

/** lang and hreflang for a link to the other language of this site, so screen readers switch voices. */
function footer_link_lang(string $url, string $lang): string
{
    if (!str_starts_with($url, '/')) {
        return '';
    }
    $second = second_lang();
    $target = $second !== '' && ($url === "/$second/" || str_starts_with($url, "/$second/")) ? $second : primary_lang();
    return $target !== $lang ? ' lang="' . $target . '" hreflang="' . $target . '"' : '';
}

/* ---------- Control panel: the Footer screen ---------- */

function footer_lang(): string
{
    return second_lang_on() && ($_GET['lang'] ?? '') === second_lang() ? second_lang() : primary_lang();
}

function footer_form(array $errors = [], ?array $fg = null): never
{
    $lang = footer_lang();
    $fg ??= footer_groups($lang) ?? ['contact' => true, 'groups' => [], 'small_print' => ''];
    $groupCount = count($fg['groups']);

    $contactNote = contact_phone() !== '' || contact_email() !== ''
        ? 'It shows the phone number and email address from Settings, so it stays right when they change.'
        : 'No phone number or email address is set in Settings yet, so it shows a link to the contact page.';
    $body = '<p>The footer is the bottom of every page: an optional Contact column, then your own groups of links, then a line of small print and the copyright line. '
        . 'Moving, adding or removing anything saves the footer straight away.</p>'
        . '<form method="post" action="' . h(admin_url('footer', is_second_lang($lang) ? ['lang' => $lang] : [])) . '">' . csrf_field()
        . '<h2>Contact column</h2>'
        . checkbox_field('contact', 'Show a Contact column with the site’s contact details', $fg['contact'], ['hint' => $contactNote]);

    $body .= '<h2>Link groups</h2>';
    if ($groupCount === 0) {
        $body .= '<p>There are no link groups yet. Add one below.</p>';
    }
    foreach ($fg['groups'] as $g => $group) {
        $gn = $g + 1;
        $heading = $group['heading'] !== '' ? $group['heading'] : 'untitled';
        $linkCount = count($group['links']);
        $body .= '<fieldset class="menu-item"><legend>Group ' . $gn . ' of ' . $groupCount . ': ' . h($heading) . '</legend>'
            . text_field("groups[$g][heading]", 'Group heading', $group['heading'], ['id' => "group-$g-heading", 'error' => $errors["group-$g-heading"] ?? null,
                'hint' => 'Shown above the links, for example “More” or “Our services”.']);
        if ($linkCount === 0) {
            $body .= '<p>This group has no links yet.</p>';
        }
        foreach ($group['links'] as $l => $link) {
            $ln = $l + 1;
            $name = $link['label'] !== '' ? $link['label'] : 'untitled';
            $body .= '<fieldset class="menu-item"><legend>Link ' . $ln . ' of ' . $linkCount . ' in group ' . $gn . ': ' . h($name) . '</legend><div class="menu-fields">'
                . text_field("groups[$g][links][$l][label]", 'Link text', $link['label'], ['id' => "group-$g-link-$l-label", 'error' => $errors["group-$g-link-$l-label"] ?? null])
                . text_field("groups[$g][links][$l][url]", 'Link address', $link['url'], ['id' => "group-$g-link-$l-url", 'spellcheck' => 'false', 'error' => $errors["group-$g-link-$l-url"] ?? null])
                . '</div><div class="menu-buttons">'
                . ($l > 0 ? '<button type="submit" class="button button-secondary" name="move" value="link-up:' . $g . ':' . $l . '">Move up<span class="visually-hidden">: ' . h($name) . ' in group ' . $gn . '</span></button>' : '')
                . ($l < $linkCount - 1 ? '<button type="submit" class="button button-secondary" name="move" value="link-down:' . $g . ':' . $l . '">Move down<span class="visually-hidden">: ' . h($name) . ' in group ' . $gn . '</span></button>' : '')
                . '<button type="submit" class="button button-danger" name="move" value="link-remove:' . $g . ':' . $l . '">Remove<span class="visually-hidden">: ' . h($name) . ' from group ' . $gn . '</span></button>'
                . '</div></fieldset>';
        }
        $body .= '<div class="menu-buttons">'
            . '<button type="submit" class="button button-secondary" name="move" value="link-add:' . $g . '">Add link<span class="visually-hidden"> to group ' . $gn . '</span></button>'
            . ($g > 0 ? '<button type="submit" class="button button-secondary" name="move" value="group-up:' . $g . '">Move group up<span class="visually-hidden">: ' . h($heading) . '</span></button>' : '')
            . ($g < $groupCount - 1 ? '<button type="submit" class="button button-secondary" name="move" value="group-down:' . $g . '">Move group down<span class="visually-hidden">: ' . h($heading) . '</span></button>' : '')
            . '<button type="submit" class="button button-danger" name="move" value="group-remove:' . $g . '">Remove group<span class="visually-hidden">: ' . h($heading) . '</span></button>'
            . '</div></fieldset>';
    }
    if ($groupCount < FOOTER_MAX_GROUPS) {
        $body .= '<p><button type="submit" class="button button-secondary" name="move" value="group-add">Add a link group</button></p>';
    }
    $body .= '<h2>Small print</h2>'
        . text_field('small_print', 'Small print (optional)', $fg['small_print'], ['maxlength' => 300, 'error' => $errors['small_print'] ?? null,
            'hint' => 'One line of plain text under the columns, for example a registration number. The copyright line with the year and your name is added on its own.'])
        . '<div class="form-actions"><button type="submit" class="button" name="move" value="save">Save footer</button>'
        . '<a href="' . h(home_path($lang)) . '#footer">See the footer on the website</a></div></form>';

    $switch = '';
    if (second_lang_on()) {
        $primaryName = language_english_name(primary_lang());
        $secondName = second_lang_name();
        $switch = '<nav aria-label="Which footer"><ul class="period-list">'
            . '<li><a href="' . h(admin_url('footer')) . '"' . (!is_second_lang($lang) ? ' aria-current="page"' : '') . '>' . h($primaryName) . ' footer</a></li>'
            . '<li><a href="' . h(admin_url('footer', ['lang' => second_lang()])) . '"' . (is_second_lang($lang) ? ' aria-current="page"' : '') . '>' . h($secondName) . ' footer</a></li></ul></nav>'
            . (is_second_lang($lang) ? '<p>This footer is shown on ' . h($secondName) . ' pages (addresses starting with ' . h(home_path($lang)) . '). When it is empty, ' . h($secondName) . ' pages use the ' . h($primaryName) . ' footer.</p>' : '');
    }
    $raw = is_second_lang($lang) ? settings()['footer_html_es'] : settings()['footer_html'];
    $note = '';
    if (!footer_groups_active($lang) && $raw !== '') {
        $note = '<p class="notice" role="note">The website is still using the raw footer HTML from Site template. '
            . 'As soon as you turn on the Contact column or add a group here, this screen takes over.</p>';
    }
    admin_screen(is_second_lang($lang) ? second_lang_name() . ' footer' : 'Footer', $switch . $note . $body, 'footer', ['errors' => $errors]);
}

function footer_save(): never
{
    $lang = footer_lang();
    $fg = ['contact' => !empty($_POST['contact']), 'groups' => [], 'small_print' => trim((string) ($_POST['small_print'] ?? ''))];
    foreach (array_slice(array_values((array) ($_POST['groups'] ?? [])), 0, FOOTER_MAX_GROUPS) as $g) {
        $links = [];
        foreach (array_slice(array_values((array) ($g['links'] ?? [])), 0, FOOTER_MAX_LINKS) as $l) {
            $links[] = ['label' => trim((string) ($l['label'] ?? '')), 'url' => trim((string) ($l['url'] ?? ''))];
        }
        $fg['groups'][] = ['heading' => trim((string) ($g['heading'] ?? '')), 'links' => $links];
    }
    [$op, $g, $l] = array_pad(explode(':', (string) ($_POST['move'] ?? 'save')), 3, '0');
    $g = (int) $g;
    $l = (int) $l;
    $message = 'Saved the footer.';
    $groups = &$fg['groups'];

    if ($op === 'group-add' && count($groups) < FOOTER_MAX_GROUPS) {
        $groups[] = ['heading' => '', 'links' => [['label' => '', 'url' => '']]];
        $message = 'Added group ' . count($groups) . '. Give it a heading and fill in its first link.';
    } elseif ($op === 'group-remove' && isset($groups[$g])) {
        $message = 'Removed the group “' . ($groups[$g]['heading'] ?: 'untitled') . '” and its links from the footer.';
        array_splice($groups, $g, 1);
    } elseif (($op === 'group-up' || $op === 'group-down') && isset($groups[$g])) {
        $j = $op === 'group-up' ? $g - 1 : $g + 1;
        if (isset($groups[$j])) {
            [$groups[$g], $groups[$j]] = [$groups[$j], $groups[$g]];
            $message = 'Moved the group “' . ($groups[$j]['heading'] ?: 'untitled') . '” ' . substr($op, 6) . '. It is now group ' . ($j + 1) . ' of ' . count($groups) . '.';
        }
    } elseif ($op === 'link-add' && isset($groups[$g]) && count($groups[$g]['links']) < FOOTER_MAX_LINKS) {
        $groups[$g]['links'][] = ['label' => '', 'url' => ''];
        $message = 'Added link ' . count($groups[$g]['links']) . ' to group ' . ($g + 1) . '. Fill in its text and address.';
    } elseif ($op === 'link-remove' && isset($groups[$g]['links'][$l])) {
        $message = 'Removed the link “' . ($groups[$g]['links'][$l]['label'] ?: 'untitled') . '” from group ' . ($g + 1) . '.';
        array_splice($groups[$g]['links'], $l, 1);
    } elseif (($op === 'link-up' || $op === 'link-down') && isset($groups[$g]['links'][$l])) {
        $j = $op === 'link-up' ? $l - 1 : $l + 1;
        $links = &$groups[$g]['links'];
        if (isset($links[$j])) {
            [$links[$l], $links[$j]] = [$links[$j], $links[$l]];
            $message = 'Moved the link “' . ($links[$j]['label'] ?: 'untitled') . '” ' . substr($op, 5) . '. It is now link ' . ($j + 1) . ' of ' . count($links) . ' in group ' . ($g + 1) . '.';
        }
        unset($links);
    }
    unset($groups);

    // A link that was just added is allowed to be empty until the next save; everything else must be complete.
    $errors = [];
    $adding = $op === 'group-add' || $op === 'link-add';
    foreach ($fg['groups'] as $gi => $group) {
        $gn = $gi + 1;
        foreach ($group['links'] as $li => $link) {
            $isNew = $adding && (($op === 'group-add' && $gi === count($fg['groups']) - 1) || ($op === 'link-add' && $gi === $g && $li === count($group['links']) - 1));
            if ($isNew) {
                continue;
            }
            if ($link['label'] === '') {
                $errors["group-$gi-link-$li-label"] = "Enter the link text for link " . ($li + 1) . " in group $gn.";
            }
            if ($link['url'] === '' || !preg_match('#^(/|https?://|mailto:|tel:)#', $link['url'])) {
                $errors["group-$gi-link-$li-url"] = "Enter an address for link " . ($li + 1) . " in group $gn that starts with /, https://, mailto: or tel:";
            }
        }
    }
    if (mb_strlen($fg['small_print']) > 300) {
        $errors['small_print'] = 'Keep the small print to 300 characters or fewer.';
    }
    if ($errors) {
        footer_form($errors, $fg);
    }
    $s = settings();
    $s[is_second_lang($lang) ? 'footer_groups_es' : 'footer_groups'] = $fg;
    save_settings($s);
    flash($message);
    go(admin_url('footer', is_second_lang($lang) ? ['lang' => $lang] : []));
}
