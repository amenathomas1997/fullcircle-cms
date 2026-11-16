<?php
/**
 * Uncontracted (grade 1) Unified English Braille, written as North American
 * ASCII Braille for .brf files. Output matches liblouis's en-ueb-g1 table for
 * the kinds of text in the cheat sheets: capitals, capitalized words and
 * passages, numbers, and common punctuation and symbols.
 */

declare(strict_types=1);

const BRF_LINE = 40;   // cells per line
const BRF_PAGE = 25;   // lines per page

const BRAILLE_SYMBOLS = [
    '.' => '4', ',' => '1', '?' => '8', '!' => '6', ';' => '2', ':' => '3',
    "'" => "'", '’' => "'", '‘' => "'", '“' => '8', '”' => '0',
    '-' => '-', '‐' => '-', '–' => ',-', '—' => ',-', '/' => '_/', '\\' => '_*',
    '(' => '"<', ')' => '">', '[' => '.<', ']' => '.>', '{' => '_<', '}' => '_>', '<' => '`<', '>' => '`>',
    '+' => '"6', '=' => '"7', '*' => '"9', '#' => '_?', '~' => '`9', '^' => '`5', '|' => '_|',
    '%' => '.0', '_' => '.-', '@' => '`a', '&' => '`&', '$' => '`s', '€' => '`e', '£' => '`l',
    '©' => '~c', '•' => '_4', '…' => '444', '×' => '"8', '°' => '~j',
];
const BRAILLE_DIGITS = ['1' => 'a', '2' => 'b', '3' => 'c', '4' => 'd', '5' => 'e', '6' => 'f', '7' => 'g', '8' => 'h', '9' => 'i', '0' => 'j'];

function braille_is_upper(string $c): bool
{
    return $c >= 'A' && $c <= 'Z';
}

function braille_is_lower(string $c): bool
{
    return $c >= 'a' && $c <= 'z';
}

function braille_is_letter(string $c): bool
{
    return braille_is_upper($c) || braille_is_lower($c);
}

/** A word whose letters are all capitals (used to find capitalized passages). */
function braille_all_caps_word(string $word): bool
{
    $letters = preg_replace('/[^A-Za-z]/', '', $word);
    return $letters !== '' && strtoupper($letters) === $letters;
}

/** Translate one word (no spaces). $inPassage: capitals are already covered by a capitalized passage. */
function braille_word(string $word, bool $inPassage = false): string
{
    $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
    $out = '';
    $n = count($chars);
    for ($i = 0; $i < $n; $i++) {
        $c = $chars[$i];

        if (ctype_digit($c)) {
            $out .= '#';
            while ($i < $n) {
                $c = $chars[$i];
                if (ctype_digit($c)) {
                    $out .= BRAILLE_DIGITS[$c];
                } elseif (($c === ',' || $c === '.') && $i + 1 < $n && ctype_digit($chars[$i + 1])) {
                    $out .= $c === ',' ? '1' : '4';
                } else {
                    break;
                }
                $i++;
            }
            // A letter a–j straight after a number would read as a digit: add the grade 1 indicator.
            if ($i < $n && $chars[$i] >= 'a' && $chars[$i] <= 'j') {
                $out .= ';';
            }
            $i--;
            continue;
        }

        if (braille_is_letter($c)) {
            $run = '';
            while ($i < $n && braille_is_letter($chars[$i])) {
                $run .= $chars[$i++];
            }
            $i--;
            $out .= braille_letters($run, $inPassage);
            // "JAWS's": the capitalized word ends before the apostrophe, so the "s" isn't read as a capital.
            $endsInCaps = !$inPassage && preg_match('/[A-Z]{2}$/', $run) === 1;
            if ($endsInCaps && isset($chars[$i + 2]) && in_array($chars[$i + 1], ["'", '’'], true) && braille_is_lower($chars[$i + 2])) {
                $out .= ",'";
            }
            continue;
        }

        if ($c === '"') {
            // Opening at the start of a word or after a bracket; closing anywhere else.
            $out .= $i === 0 || in_array($chars[$i - 1], ['(', '[', '{', '-', '/'], true) ? '8' : '0';
            continue;
        }
        if (isset(BRAILLE_SYMBOLS[$c])) {
            $sym = BRAILLE_SYMBOLS[$c];
            // Lower punctuation directly before a letter needs the grade 1 indicator.
            if (in_array($c, ['?', ';', ':', '!'], true) && $i + 1 < $n && braille_is_letter($chars[$i + 1])) {
                $sym = ';' . $sym;
            }
            $out .= $sym;
            continue;
        }
        if (($accented = braille_accented($c, $inPassage)) !== null) {
            $out .= $accented;
            continue;
        }
        // Anything else: the plain letters it's made of ("ß" becomes "ss"), or nothing.
        $plain = @iconv('UTF-8', 'ASCII//TRANSLIT', $c);
        if ($plain !== false && $plain !== '' && ctype_alpha($plain) && $plain !== $c) {
            $out .= braille_word($plain, $inPassage);
        }
    }
    return $out;
}

/** UEB accent indicators, keyed by the combining mark that follows the base letter when the character is decomposed. */
const BRAILLE_ACCENTS = ["\u{0301}" => '^/', "\u{0300}" => '^*', "\u{0302}" => '^%', "\u{0303}" => '^]', "\u{0308}" => '^3', "\u{0327}" => '^&', "\u{030A}" => '^0'];

/** An accented Latin letter such as é or Ñ as its UEB accent indicator plus letter, or null if it isn't one. */
function braille_accented(string $c, bool $inPassage): ?string
{
    if (!class_exists('Normalizer')) {
        return null;
    }
    $parts = preg_split('//u', (string) Normalizer::normalize($c, Normalizer::FORM_D), -1, PREG_SPLIT_NO_EMPTY);
    if (count($parts) !== 2 || !braille_is_letter($parts[0]) || !isset(BRAILLE_ACCENTS[$parts[1]])) {
        return null;
    }
    $letter = strtolower($parts[0]);
    return (braille_is_upper($parts[0]) && !$inPassage ? ',' : '') . BRAILLE_ACCENTS[$parts[1]] . $letter;
}

/** A run of letters: capital letters get ",", capitalized runs get ",,", and ",'" ends them before lower case. */
function braille_letters(string $run, bool $inPassage): string
{
    if ($inPassage) {
        return strtolower($run);
    }
    $out = '';
    $len = strlen($run);
    for ($i = 0; $i < $len;) {
        if (braille_is_upper($run[$i])) {
            $j = $i;
            while ($j < $len && braille_is_upper($run[$j])) {
                $j++;
            }
            $caps = substr($run, $i, $j - $i);
            if (strlen($caps) >= 2) {
                $out .= ',,' . strtolower($caps);
                if ($j < $len) {
                    $out .= ",'";
                }
            } else {
                $out .= ',' . strtolower($caps);
            }
            $i = $j;
        } else {
            $out .= $run[$i++];
        }
    }
    return $out;
}

/** Translate a line of text, using a capitalized passage for three or more capitalized words in a row. */
function braille_text(string $text): string
{
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    $count = count($words);
    for ($i = 0; $i < $count; $i++) {
        $j = $i;
        while ($j < $count && braille_all_caps_word($words[$j])) {
            $j++;
        }
        if ($j - $i >= 3) {
            for ($k = $i; $k < $j; $k++) {
                $out[] = ($k === $i ? ',,,' : '') . braille_word($words[$k], true) . ($k === $j - 1 ? ",'" : '');
            }
            $i = $j - 1;
            continue;
        }
        $out[] = braille_word($words[$i]);
    }
    return implode(' ', $out);
}

/** Wrap braille to the line length. Run-over lines are indented by $indent cells. */
function braille_wrap(string $braille, int $first = 0, int $indent = 0): array
{
    $lines = [];
    $line = str_repeat(' ', $first);
    foreach (explode(' ', $braille) as $word) {
        while (strlen($word) > BRF_LINE - $indent) {
            // A word longer than a line: break it with a hyphen.
            $room = BRF_LINE - strlen($line) - ($line === '' || trim($line) === '' ? 0 : 1) - 1;
            if ($room < 4) {
                $lines[] = rtrim($line);
                $line = str_repeat(' ', $indent);
                continue;
            }
            $line .= (trim($line) === '' ? '' : ' ') . substr($word, 0, $room) . '-';
            $lines[] = $line;
            $line = str_repeat(' ', $indent);
            $word = substr($word, $room);
        }
        $candidate = trim($line) === '' ? $line . $word : $line . ' ' . $word;
        if (strlen($candidate) > BRF_LINE) {
            $lines[] = rtrim($line);
            $line = str_repeat(' ', $indent) . $word;
        } else {
            $line = $candidate;
        }
    }
    if (trim($line) !== '') {
        $lines[] = rtrim($line);
    }
    return $lines;
}

/** Join lines into pages of BRF_PAGE lines, separated by form feeds, with Windows line endings. */
function braille_pages(array $lines): string
{
    $pages = array_chunk($lines, BRF_PAGE);
    return implode("\f", array_map(fn($p) => implode("\r\n", $p) . "\r\n", $pages));
}
