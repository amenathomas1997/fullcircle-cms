<?php
/**
 * The pages a brand-new site starts with. Used once, by tools/setup.php or
 * the first-run screen in the control panel, and only when there are no
 * pages yet. Everything here can be edited or deleted in the control panel
 * afterwards.
 */

declare(strict_types=1);

/** Create the starter pages, a first blog post, the menu and the footer. Does nothing if pages already exist. */
function create_starter_content(bool $spanish = false): void
{
    if (all_items('pages')) {
        return;
    }
    $s = settings();
    $name = (string) $s['site_name'];
    $contactList = [];
    if (contact_phone() !== '') {
        $contactList[] = 'Call or text: <a href="tel:' . h(preg_replace('/[^0-9+]/', '', contact_phone())) . '">' . h(contact_phone()) . '</a>';
    }
    if (contact_email() !== '') {
        $contactList[] = 'Email: <a href="mailto:' . h(contact_email()) . '">' . h(contact_email()) . '</a>';
    }
    $contactLines = $contactList ? '<p>' . implode('<br>', $contactList) . '</p>' : '';

    $pages = [
        'home' => ['title' => $name, 'hide_title' => true, 'description' => (string) $s['description'], 'body' =>
            '<h1>Welcome to ' . h($name) . '</h1>'
            . '<p class="lede">' . ($s['tagline'] !== '' ? h($s['tagline']) : 'This is your new website. Replace this paragraph with a sentence or two about what you do and who it is for.') . '</p>'
            . '<h2>What you can do here</h2>'
            . '<ul><li><a href="/about/">Find out more about ' . h($name) . '</a></li><li><a href="/blog/">Read the latest posts</a></li><li><a href="/contact/">Get in touch</a></li></ul>'
            . '<p>To change this page, sign in to the control panel at <a href="/admin/">/admin/</a>, open Pages and choose Home.</p>'],
        'about' => ['title' => 'About', 'description' => 'About ' . $name . '.', 'body' =>
            '<p>Tell visitors who you are, what you do and why. A few short paragraphs work best, with a heading for each topic so screen reader users can jump between them.</p>'
            . '<h2>Our story</h2><p>Write about how ' . h($name) . ' started and what matters to you.</p>'
            . '<h2>Who we work with</h2><p>Describe the people you help or the customers you serve.</p>'
            . '<p><a class="button" href="/contact/">Contact us</a></p>'],
        'contact' => ['title' => 'Contact us', 'description' => 'How to reach ' . $name . '.', 'body' =>
            '<p>Send a message with the form below' . ($contactList ? ', or reach us directly:' : '.') . '</p>' . $contactLines . '[contact-form]'],
        'accessibility' => ['title' => 'Accessibility statement', 'description' => 'How ' . $name . ' makes this website accessible and how to report a problem.', 'body' =>
            '<p>' . h($name) . ' wants everyone to be able to use this website, including people who use screen readers, magnification, speech input, switch access or a keyboard alone.</p>'
            . '<h2>What we do</h2><ul>'
            . '<li>Every page has a logical heading structure, a skip link to the main content and visible keyboard focus.</li>'
            . '<li>Text, colors and spacing can be changed with the Display options button at the top of every page, including large text, dark colors and a high contrast setting.</li>'
            . '<li>Forms tell you what went wrong in plain words and move you to the problem.</li>'
            . '<li>Images that carry meaning have text descriptions; decorative images are hidden from screen readers.</li>'
            . '<li>Nothing moves, flashes or plays on its own.</li></ul>'
            . '<p>We aim to meet the Web Content Accessibility Guidelines (WCAG) 2.2 at level AA.</p>'
            . '<h2>Tell us about a problem</h2><p>If any part of this website is hard to use with your assistive technology, please tell us which page and what happened, and we will fix it or give you the information another way.</p>'
            . ($contactLines !== '' ? $contactLines : '<p><a href="/contact/">Contact us</a>.</p>')],
        'privacy' => ['title' => 'Privacy', 'description' => 'What this website collects and what it does with it.', 'body' =>
            '<h2>Visitor counts</h2><p>This website counts visits on its own server without cookies, advertising trackers or any outside analytics company. The count can’t identify you.</p>'
            . '<h2>Contact form</h2><p>When you send a message, we keep your name, email address, phone number if you give it, and the message, so that we can reply. We don’t share them with anyone else.</p>'
            . '<h2>Email updates</h2><p>If you sign up for updates, we keep your email address until you unsubscribe. Every email has an unsubscribe link.</p>'
            . '<h2>Display options</h2><p>Your text size and color choices are saved in your own browser only, so the site remembers them next time. Nothing about them is sent to us.</p>'
            . '<h2>Questions</h2><p>Ask us anything about your information through the <a href="/contact/">contact page</a>.</p>'],
    ];
    if ($spanish) {
        $pages += [
            'es' => ['title' => $name, 'hide_title' => true, 'description' => (string) $s['description'], 'translation' => 'home', 'body' =>
                '<h1>Bienvenido a ' . h($name) . '</h1>'
                . '<p class="lede">' . ($s['tagline'] !== '' ? h($s['tagline']) : 'Este es su nuevo sitio web. Reemplace este párrafo con una o dos frases sobre lo que hace y para quién.') . '</p>'
                . '<ul><li><a href="/es/acerca-de/">Conozca más sobre ' . h($name) . '</a></li><li><a href="/es/blog/">Lea las últimas publicaciones</a></li><li><a href="/es/contacto/">Comuníquese con nosotros</a></li></ul>'],
            'es-acerca-de' => ['title' => 'Acerca de', 'translation' => 'about', 'body' => '<p>Cuente a los visitantes quién es usted, qué hace y por qué.</p><p><a class="button" href="/es/contacto/">Contáctenos</a></p>'],
            'es-contacto' => ['title' => 'Contacto', 'translation' => 'contact', 'body' => '<p>Envíe un mensaje con el formulario' . ($contactList ? ', o comuníquese directamente:' : '.') . '</p>' . $contactLines . '[contact-form]'],
            'es-accesibilidad' => ['title' => 'Declaración de accesibilidad', 'translation' => 'accessibility', 'body' =>
                '<p>' . h($name) . ' quiere que todas las personas puedan usar este sitio web, incluidas las que usan lectores de pantalla, ampliación, entrada por voz o solo el teclado.</p>'
                . '<p>Nuestro objetivo es cumplir las Pautas de Accesibilidad para el Contenido Web (WCAG) 2.2 en el nivel AA. Si alguna parte de este sitio es difícil de usar con su tecnología de asistencia, díganos qué página y qué pasó, y lo arreglaremos.</p>'
                . ($contactLines !== '' ? $contactLines : '<p><a href="/es/contacto/">Contáctenos</a>.</p>')],
            'es-privacidad' => ['title' => 'Privacidad', 'translation' => 'privacy', 'body' =>
                '<h2>Conteo de visitas</h2><p>Este sitio cuenta las visitas en su propio servidor, sin cookies ni rastreadores publicitarios. El conteo no puede identificarle.</p>'
                . '<h2>Formulario de contacto</h2><p>Cuando envía un mensaje, guardamos su nombre, correo electrónico, teléfono si lo indica y el mensaje, para poder responderle. No los compartimos con nadie.</p>'
                . '<h2>Actualizaciones por correo</h2><p>Si se suscribe, guardamos su correo hasta que cancele la suscripción. Cada correo tiene un enlace para cancelarla.</p>'],
        ];
        $pages['home']['translation'] = 'es';
        $pages['about']['translation'] = 'es-acerca-de';
        $pages['contact']['translation'] = 'es-contacto';
        $pages['accessibility']['translation'] = 'es-accesibilidad';
        $pages['privacy']['translation'] = 'es-privacidad';
    }
    foreach ($pages as $slug => $page) {
        save_item('pages', $slug, $page + ['status' => 'published']);
    }

    save_item('posts', 'welcome', [
        'title' => 'Welcome to the new ' . $name . ' website',
        'status' => 'published',
        'date' => date('Y-m-d\TH:i'),
        'excerpt' => 'A new website, built to work well with screen readers and on every device.',
        'categories' => ['News'],
        'body' => '<p>This is the first post on the new ' . h($name) . ' website. New posts appear here and in the <a href="/blog/feed.xml">RSS feed</a>.</p>'
            . '<p>To write your own, sign in to the control panel, choose Blog posts, then Write a blog post. You can delete this one from the same screen.</p>',
    ]);

    $menu = [['label' => 'Home', 'url' => '/'], ['label' => 'About', 'url' => '/about/'], ['label' => 'Blog', 'url' => '/blog/'], ['label' => 'Contact', 'url' => '/contact/']];
    $menuEs = $spanish ? [['label' => 'Inicio', 'url' => '/es/'], ['label' => 'Acerca de', 'url' => '/es/acerca-de/'], ['label' => 'Blog', 'url' => '/es/blog/'], ['label' => 'Contacto', 'url' => '/es/contacto/']] : [];
    $footer = '<div class="footer-columns"><section aria-labelledby="footer-contact"><h2 id="footer-contact">Contact</h2>'
        . ($contactLines !== '' ? $contactLines : '<p><a href="/contact/">Contact us</a></p>') . '</section>'
        . '<section aria-labelledby="footer-more"><h2 id="footer-more">More</h2><ul>'
        . '<li><a href="/about/">About</a></li><li><a href="/blog/">Blog</a></li><li><a href="/accessibility/">Accessibility statement</a></li><li><a href="/privacy/">Privacy</a></li>'
        . ($spanish ? '<li><a href="/es/" lang="es" hreflang="es">En español</a></li>' : '') . '</ul></section></div>';
    $footerEs = $spanish ? '<div class="footer-columns"><section aria-labelledby="footer-contact"><h2 id="footer-contact">Contacto</h2>'
        . ($contactLines !== '' ? $contactLines : '<p><a href="/es/contacto/">Contáctenos</a></p>') . '</section>'
        . '<section aria-labelledby="footer-more"><h2 id="footer-more">Más</h2><ul>'
        . '<li><a href="/es/acerca-de/">Acerca de</a></li><li><a href="/es/blog/">Blog</a></li><li><a href="/es/accesibilidad/">Declaración de accesibilidad</a></li><li><a href="/es/privacidad/">Privacidad</a></li>'
        . '<li><a href="/" lang="en" hreflang="en">English</a></li></ul></section></div>' : '';
    save_settings(array_merge(settings(), ['menu' => $menu, 'menu_es' => $menuEs, 'footer_html' => $footer, 'footer_html_es' => $footerEs,
        'contact_page' => '/contact/', 'contact_page_es' => '/es/contacto/']));
}
