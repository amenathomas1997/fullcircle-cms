<?php
/**
 * Practice quizzes at /resources/<cheat sheet>/quiz/, made from the cheat
 * sheet's own tables, so they always match it. Each quiz asks which keys do
 * five random actions, with four choices each. Nothing is stored: the quiz
 * travels in the form (its random seed and the answers), like a worksheet.
 */

declare(strict_types=1);

const QUIZ_QUESTIONS = 5;

/** Every "action → keys" row in a cheat sheet's tables. */
function quiz_rows(array $resource): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?><div>' . ($resource['body'] ?? '') . '</div>');
    libxml_clear_errors();
    $rows = [];
    foreach ($dom->getElementsByTagName('tr') as $tr) {
        if ($tr->parentNode instanceof DOMElement && strtolower($tr->parentNode->tagName) === 'thead') {
            continue;
        }
        $cells = [];
        foreach ($tr->childNodes as $c) {
            if ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['td', 'th'], true)) {
                $cells[] = trim((string) preg_replace('/\s+/u', ' ', $c->textContent));
            }
        }
        if (count($cells) === 2 && $cells[0] !== '' && $cells[1] !== '' && mb_strlen($cells[1]) <= 80) {
            $rows[] = ['action' => $cells[0], 'keys' => $cells[1]];
        }
    }
    // The same keys twice would make two "right" answers; keep the first.
    $seen = [];
    return array_values(array_filter($rows, function ($r) use (&$seen) {
        $k = mb_strtolower($r['keys']);
        if (isset($seen[$k])) {
            return false;
        }
        return $seen[$k] = true;
    }));
}

/** The same seed always gives the same questions and choices. */
function quiz_questions(array $rows, int $seed): array
{
    mt_srand($seed);
    $order = range(0, count($rows) - 1);
    for ($i = count($order) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
    }
    $questions = [];
    foreach (array_slice($order, 0, min(QUIZ_QUESTIONS, count($rows))) as $ri) {
        $others = array_values(array_filter(range(0, count($rows) - 1), fn($x) => $x !== $ri));
        for ($i = count($others) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$others[$i], $others[$j]] = [$others[$j], $others[$i]];
        }
        $choices = array_merge([$ri], array_slice($others, 0, 3));
        for ($i = count($choices) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$choices[$i], $choices[$j]] = [$choices[$j], $choices[$i]];
        }
        $questions[] = ['row' => $ri, 'choices' => $choices];
    }
    mt_srand();
    return $questions;
}

function quiz_page(string $slug): never
{
    $r = resource_for($slug);
    $es = current_lang() === 'es';
    $rows = quiz_rows($r);
    if (count($rows) < 4) {
        not_found();
    }
    $sheetUrl = '/resources/' . $slug . '/';
    $quizUrl = $sheetUrl . 'quiz/';
    $checking = isset($_GET['seed']) && isset($_GET['check']);
    $seed = $checking ? max(1, (int) $_GET['seed']) : random_int(1, 2_000_000_000);
    $questions = quiz_questions($rows, $seed);
    $total = count($questions);

    $out = '<h1>' . h(($es ? 'Práctica: ' : 'Practice quiz: ') . $r['title']) . '</h1>';
    if ($checking) {
        $score = 0;
        $feedback = '';
        foreach ($questions as $n => $q) {
            $given = (string) ($_GET["q$n"] ?? '');
            $right = $given !== '' && (int) $given === $q['row'];
            $score += $right ? 1 : 0;
            $answer = $rows[$q['row']];
            $feedback .= '<li><p><strong>' . h($answer['action']) . '</strong></p><p>'
                . ($right ? ($es ? 'Correcto: ' : 'Right: ') . h($answer['keys'])
                    : ($given === '' ? ($es ? 'Sin respuesta. ' : 'Not answered. ') : ($es ? 'Todavía no. ' : 'Not quite. '))
                        . ($es ? 'La respuesta es ' : 'The answer is ') . h($answer['keys']) . '.')
                . '</p></li>';
        }
        $praise = $score === $total ? ($es ? '¡Excelente!' : 'Excellent!') : ($score >= $total - 1 ? ($es ? '¡Muy bien!' : 'Well done!') : ($es ? 'Siga practicando.' : 'Keep practicing.'));
        $out .= '<section class="notice notice-success" aria-labelledby="score-heading" tabindex="-1" data-focus-on-load>'
            . '<h2 id="score-heading">' . h($es ? "Acertó $score de $total. $praise" : "You got $score out of $total. $praise") . '</h2>'
            . '<ol class="quiz-feedback">' . $feedback . '</ol>'
            . '<div class="buttons"><a class="button" href="' . h($quizUrl) . '">' . ($es ? 'Hacer otra práctica' : 'Try another quiz') . '</a>'
            . '<a class="button button-outline" href="' . h($sheetUrl) . '">' . ($es ? 'Volver a la guía' : 'Back to the cheat sheet') . '</a></div></section>';
        send_html(render_layout(($es ? 'Resultado: ' : 'Your score: ') . $r['title'], $out, (string) ($r['summary'] ?? '')));
    }

    $out .= '<p class="lede">' . h($es ? "Elija las teclas correctas para cada acción. Son $total preguntas, y al final verá las respuestas."
            : "Choose the right keys for each action. There are $total questions, and you’ll see the answers at the end.") . '</p>'
        . '<form class="contact-form quiz" method="get" action="' . h($quizUrl) . '">'
        . '<input type="hidden" name="seed" value="' . $seed . '"><input type="hidden" name="check" value="1">';
    foreach ($questions as $n => $q) {
        $out .= '<fieldset class="field"><legend>' . ($n + 1) . '. ' . h(($es ? '¿Qué teclas hacen esto? ' : 'Which keys do this? ') . $rows[$q['row']]['action']) . '</legend>';
        foreach ($q['choices'] as $ci => $rowIndex) {
            $id = "q$n-$ci";
            $out .= '<div class="option"><input type="radio" id="' . $id . '" name="q' . $n . '" value="' . $rowIndex . '">'
                . '<label for="' . $id . '">' . h($rows[$rowIndex]['keys']) . '</label></div>';
        }
        $out .= '</fieldset>';
    }
    $out .= '<button type="submit" class="button">' . ($es ? 'Ver mi resultado' : 'Check my answers') . '</button></form>'
        . '<p><a href="' . h($sheetUrl) . '">' . ($es ? 'Volver a la guía' : 'Back to the cheat sheet') . '</a></p>';
    header('Cache-Control: no-store');
    send_html(render_layout(($es ? 'Práctica: ' : 'Practice quiz: ') . $r['title'], $out, (string) ($r['summary'] ?? '')));
}
