<?php
/**
 * [screen-reader-guide]: "Which screen reader is right for me?" Three questions
 * (device, how much vision you use on screen, whether you need free options),
 * then suggestions with links to the matching cheat sheets. Works without
 * JavaScript; the answers travel in the page address.
 */

declare(strict_types=1);

function chooser_text(): array
{
    $es = current_lang() === 'es';
    return $es ? [
        'title' => 'Encuentre su herramienta',
        'q_device' => '¿En qué dispositivo quiere usarla?',
        'q_vision' => '¿Cuánto usa la vista en la pantalla?',
        'q_cost' => '¿Necesita opciones gratuitas?',
        'devices' => ['windows' => 'Computadora con Windows', 'mac' => 'Mac', 'iphone' => 'iPhone o iPad', 'android' => 'Teléfono o tableta Android', 'chromebook' => 'Chromebook'],
        'visions' => ['none' => 'Uso solo voz o braille', 'some' => 'Veo algo, pero leer cansa: me gustaría voz y ampliación', 'magnify' => 'Sobre todo necesito ver las cosas más grandes'],
        'costs' => ['free' => 'Sí, solo opciones gratuitas', 'any' => 'No: puedo pagar o una agencia podría pagar'],
        'button' => 'Ver mis sugerencias',
        'missing' => 'Conteste las tres preguntas para ver sus sugerencias.',
        'result' => 'Nuestras sugerencias',
        'built_in' => 'Incluido, gratis',
        'free' => 'Gratis',
        'paid' => 'De pago (las agencias a menudo lo pagan)',
        'cheat' => 'Ver la guía rápida',
        'again' => 'Empezar de nuevo',
        'next' => '¿Quiere ayuda para elegir y aprender? Cada persona es diferente, y probar es la mejor manera de decidir.',
        'book' => 'Contáctenos',
    ] : [
        'title' => 'Find your tool',
        'q_device' => 'Which device do you want to use it on?',
        'q_vision' => 'How much do you use your vision on screen?',
        'q_cost' => 'Do you need free options?',
        'devices' => ['windows' => 'Windows computer', 'mac' => 'Mac', 'iphone' => 'iPhone or iPad', 'android' => 'Android phone or tablet', 'chromebook' => 'Chromebook'],
        'visions' => ['none' => 'I use speech or braille only', 'some' => 'I can see some, but reading is tiring: I’d like speech and magnification', 'magnify' => 'I mainly need things bigger'],
        'costs' => ['free' => 'Yes, free options only', 'any' => 'No: I can pay, or an agency might'],
        'button' => 'Show my suggestions',
        'missing' => 'Answer all three questions to see your suggestions.',
        'result' => 'Our suggestions',
        'built_in' => 'Built in, free',
        'free' => 'Free',
        'paid' => 'Paid (agencies often fund it)',
        'cheat' => 'See the cheat sheet',
        'again' => 'Start again',
        'next' => 'Want help choosing and learning? Everyone is different, and trying them is the best way to decide.',
        'book' => 'Contact us',
    ];
}

/** Each tool: name, cost, a one-line description in both languages, and its cheat sheet if there is one. */
function chooser_tools(): array
{
    return [
        'nvda' => ['NVDA', 'free', 'A full-featured screen reader for Windows, used at home, school and work.', 'Un lector de pantalla completo para Windows, para el hogar, la escuela y el trabajo.', 'nvda'],
        'jaws' => ['JAWS', 'paid', 'A screen reader for Windows that’s common in workplaces, with strong support for Microsoft Office.', 'Un lector de pantalla para Windows muy usado en el trabajo, con buen soporte para Microsoft Office.', 'jaws'],
        'narrator' => ['Narrator', 'built_in', 'The screen reader built into Windows. A good way to start or to use any computer.', 'El lector de pantalla incluido en Windows. Una buena forma de empezar o de usar cualquier computadora.', null],
        'fusion' => ['Fusion', 'paid', 'JAWS and ZoomText together: speech and magnification in one, for people with some usable vision.', 'JAWS y ZoomText juntos: voz y magnificación en uno, para personas con algo de visión útil.', 'jaws'],
        'zoomtext' => ['ZoomText', 'paid', 'Magnification for Windows with color and pointer options, and speech if you want it.', 'Magnificación para Windows con opciones de color y puntero, y voz si la desea.', null],
        'winmag' => ['Windows Magnifier', 'built_in', 'Zoom built into Windows. Press Windows + Plus to start it.', 'La ampliación incluida en Windows. Presione Windows + Más para iniciarla.', null],
        'voiceover_mac' => ['VoiceOver', 'built_in', 'The screen reader built into every Mac. Press Command + F5 to turn it on.', 'El lector de pantalla incluido en todas las Mac. Presione Comando + F5 para activarlo.', 'voiceover-mac'],
        'zoom_mac' => ['Zoom and Hover Text', 'built_in', 'Magnification built into the Mac, plus large text under the pointer. Find them in Accessibility settings.', 'La ampliación incluida en la Mac, y texto grande bajo el puntero. Están en la configuración de Accesibilidad.', null],
        'voiceover_ios' => ['VoiceOver', 'built_in', 'The screen reader built into iPhone and iPad, controlled with gestures.', 'El lector de pantalla incluido en iPhone y iPad, que se controla con gestos.', null],
        'zoom_ios' => ['Zoom and Magnifier', 'built_in', 'Screen zoom built into iPhone and iPad, and the Magnifier app that turns the camera into a video magnifier.', 'La ampliación de pantalla de iPhone y iPad, y la app Lupa que convierte la cámara en una lupa.', null],
        'talkback' => ['TalkBack', 'built_in', 'The screen reader built into Android, controlled with gestures.', 'El lector de pantalla incluido en Android, que se controla con gestos.', null],
        'mag_android' => ['Magnification', 'built_in', 'Screen zoom built into Android, in Accessibility settings.', 'La ampliación de pantalla incluida en Android, en la configuración de Accesibilidad.', null],
        'chromevox' => ['ChromeVox', 'built_in', 'The screen reader built into Chromebooks. Press Control + Alt + Z to turn it on.', 'El lector de pantalla incluido en las Chromebook. Presione Control + Alt + Z para activarlo.', null],
        'mag_chrome' => ['Screen magnifier', 'built_in', 'Zoom built into Chromebooks, in Accessibility settings.', 'La ampliación incluida en las Chromebook, en la configuración de Accesibilidad.', null],
    ];
}

/** Which tools to suggest, in order. */
function chooser_pick(string $device, string $vision, string $cost): array
{
    $free = $cost === 'free';
    return match ($device) {
        'windows' => match ($vision) {
            'none' => $free ? ['nvda', 'narrator'] : ['nvda', 'jaws', 'narrator'],
            'some' => $free ? ['nvda', 'winmag', 'narrator'] : ['fusion', 'nvda', 'winmag'],
            default => $free ? ['winmag', 'nvda'] : ['zoomtext', 'winmag', 'nvda'],
        },
        'mac' => $vision === 'magnify' ? ['zoom_mac', 'voiceover_mac'] : ($vision === 'some' ? ['voiceover_mac', 'zoom_mac'] : ['voiceover_mac']),
        'iphone' => $vision === 'magnify' ? ['zoom_ios', 'voiceover_ios'] : ($vision === 'some' ? ['voiceover_ios', 'zoom_ios'] : ['voiceover_ios']),
        'android' => $vision === 'magnify' ? ['mag_android', 'talkback'] : ($vision === 'some' ? ['talkback', 'mag_android'] : ['talkback']),
        default => $vision === 'magnify' ? ['mag_chrome', 'chromevox'] : ($vision === 'some' ? ['chromevox', 'mag_chrome'] : ['chromevox']),
    };
}

function render_screen_reader_guide(string $path): string
{
    $t = chooser_text();
    $es = current_lang() === 'es';
    $device = (string) ($_GET['device'] ?? '');
    $vision = (string) ($_GET['vision'] ?? '');
    $cost = (string) ($_GET['cost'] ?? '');
    $asked = isset($_GET['guide']);
    $result = '';
    if ($asked) {
        if (!isset($t['devices'][$device], $t['visions'][$vision], $t['costs'][$cost])) {
            $result = '<div class="checker-result error-summary" role="alert" tabindex="-1" data-focus-on-load><p>' . h($t['missing']) . '</p></div>';
        } else {
            $tools = chooser_tools();
            $items = '';
            foreach (chooser_pick($device, $vision, $cost) as $key) {
                [$name, $price, $en, $esText, $sheet] = $tools[$key];
                // Built-in tools go by their Spanish names on Spanish-language devices.
                $esNames = ['narrator' => 'Narrador', 'winmag' => 'Lupa de Windows', 'zoom_mac' => 'Zoom y Hover Text', 'zoom_ios' => 'Zoom y Lupa',
                    'mag_android' => 'Ampliación', 'mag_chrome' => 'Lupa de pantalla'];
                if ($es && isset($esNames[$key])) {
                    $name = $esNames[$key];
                }
                if ($sheet && $es && get_item('resources', 'es-' . $sheet)) {
                    $sheet = 'es-' . $sheet;
                }
                $items .= '<li><h4>' . h($name) . '</h4><p>' . h($es ? $esText : $en) . '</p><p class="post-meta">' . h($t[$price]) . '</p>'
                    . ($sheet && get_item('resources', $sheet) ? '<p><a href="/resources/' . h($sheet) . '/">' . h($t['cheat']) . '<span class="visually-hidden">: ' . h($name) . '</span></a></p>' : '')
                    . '</li>';
            }
            $result = '<div class="checker-result" role="status" tabindex="-1" data-focus-on-load aria-labelledby="guide-result">'
                . '<h3 id="guide-result">' . h($t['result']) . '</h3><ol class="tool-list">' . $items . '</ol>'
                . '<p>' . h($t['next']) . '</p><div class="buttons">'
                . apply_filters('chooser_result_buttons', '<a class="button" href="' . h(contact_path($es ? 'es' : 'en')) . '">' . h($t['book']) . '</a>') . '</div>'
                . '<p><a href="' . h($path) . '#guide">' . h($t['again']) . '</a></p></div>';
        }
    }
    $group = function (string $name, string $legend, array $options, string $current) {
        $out = '<fieldset><legend>' . h($legend) . '</legend>';
        foreach ($options as $value => $label) {
            $id = "guide-$name-$value";
            $out .= '<div class="option"><input type="radio" id="' . $id . '" name="' . $name . '" value="' . $value . '"' . ($current === $value ? ' checked' : '') . '>'
                . '<label for="' . $id . '">' . h($label) . '</label></div>';
        }
        return $out . '</fieldset>';
    };
    return '<section class="checker" id="guide" aria-labelledby="guide-title"><h2 id="guide-title">' . h($t['title']) . '</h2>' . $result
        . '<form method="get" action="' . h($path) . '#guide"><input type="hidden" name="guide" value="1">'
        . $group('device', '1. ' . $t['q_device'], $t['devices'], $device)
        . $group('vision', '2. ' . $t['q_vision'], $t['visions'], $vision)
        . $group('cost', '3. ' . $t['q_cost'], $t['costs'], $cost)
        . '<button type="submit" class="button">' . h($t['button']) . '</button></form></section>';
}
