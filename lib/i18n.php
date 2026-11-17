<?php
/**
 * English and Spanish text used by the site itself (not page content).
 * Spanish pages have addresses starting with /es/ and their files are
 * named "es" (the Spanish home page) or "es-<name>".
 */

declare(strict_types=1);

const LANGUAGES = ['en' => 'English', 'es' => 'Español'];

const STRINGS = [
    'en' => [
        'skip' => 'Skip to main content',
        'menu_label' => 'Main',
        'display' => 'Display options',
        'text_size' => 'Text size',
        'size_default' => 'Standard',
        'size_large' => 'Large',
        'size_larger' => 'Extra large',
        'colors' => 'Colors',
        'theme_auto' => 'Match my device',
        'theme_light' => 'Light',
        'theme_dark' => 'Dark',
        'theme_contrast' => 'High contrast (yellow on black)',
        'display_reset' => 'Reset to standard',
        'display_close' => 'Close display options',
        'language_switch' => 'Language',
        'required_note' => 'All fields are required unless marked optional.',
        'error_title' => 'There is a problem with your message',
        // Contact form
        'contact_name' => 'Your name',
        'contact_email' => 'Email address',
        'contact_phone' => 'Phone number (optional)',
        'contact_message' => 'How can we help?',
        'contact_send' => 'Send message',
        'contact_sent_title' => 'Thank you, your message has been sent',
        'contact_sent_body' => 'We will get back to you as soon as we can, usually within one business day.',
        'err_name' => 'Enter your name.',
        'err_name_long' => 'Your name must be 200 characters or fewer.',
        'err_email' => 'Enter your email address.',
        'err_email_format' => 'Enter an email address in the right format, like name@example.com.',
        'err_phone' => 'Enter a phone number using only numbers, spaces, brackets, dashes or a plus sign.',
        'err_message' => 'Enter your message.',
        'err_message_long' => 'Your message must be 5,000 characters or fewer.',
        'err_rate' => 'You have sent several messages in the last hour. Please {contact}, or try again later.',
        'error_prefix' => 'Error: ',
        'yes' => 'Yes',
        'no' => 'No',
        'not_sure' => 'I’m not sure',
        // Testimonials
        'testi_name' => 'Your name, as you’d like it shown',
        'testi_name_hint' => 'For example “Tasha W.” Your first name and last initial is fine.',
        'testi_role' => 'About you (optional)',
        'testi_role_hint' => 'For example “Vocational Rehabilitation Counselor” or “VoiceOver user”.',
        'testi_text' => 'Your testimonial',
        'testi_email' => 'Email address (optional, never shown)',
        'testi_email_hint' => 'Only so we can contact you about your testimonial.',
        'testi_consent' => 'I agree that {site} can publish my testimonial and the name above on this website.',
        'testi_send' => 'Send testimonial',
        'testi_sent_title' => 'Thank you for sharing your experience',
        'testi_sent_body' => 'We read every testimonial before it appears on the website.',
        'err_testi_text' => 'Write your testimonial.',
        'err_testi_long' => 'Your testimonial must be 3,000 characters or fewer.',
        'err_consent' => 'Tick the box to agree that we can publish your testimonial.',
        'testi_error_title' => 'There is a problem with your testimonial',
        // Blog, search and cheat sheets
        'blog' => 'Blog',
        'blog_search' => 'Search the blog',
        'search' => 'Search',
        'site_search' => 'Search',
        'minute_read' => 'minute read',
        'audio_available' => 'Audio version available',
        'listen' => 'Listen to this post',
        'download_audio' => 'Download the audio',
        'transcript_note' => 'The text below is the full transcript.',
        'more_from_blog' => 'More from the blog',
        'back_to_blog' => 'Back to all blog posts',
        'browse_topics' => 'Browse by topic',
        'follow_blog' => 'Follow the blog',
        'rss_feed' => 'Blog RSS feed',
        'no_posts' => 'No posts yet. Check back soon.',
        'no_results' => 'No posts found. Try different words, or browse by topic below.',
        'posts_about' => 'Blog posts about',
        'all_posts' => 'See all blog posts',
        'newer' => 'Newer posts',
        'older' => 'Older posts',
        'page_of' => 'Page %d of %d',
        'topics' => 'Topics:',
        'search_results_for' => 'Search results for',
        'found_one' => '1 post found.',
        'found_many' => '%d posts found.',
        // Payments (lib/features/payments.php)
        'pay_button' => 'Pay %s for %s',
        'pay_quantity' => 'Quantity',
        'pay_note' => 'Note (optional)',
        'pay_note_hint' => 'Anything we should know, up to 500 characters.',
        'pay_secure' => 'You will pay on a secure page run by Stripe. We never see your card details.',
        'pay_cancelled' => 'Your payment was cancelled and nothing was charged. You can try again below.',
        'pay_error' => 'Sorry, we could not start the payment. Please try again in a few minutes, or {contact}.',
        'pay_unavailable' => 'This item is not available right now.',
        'pay_thanks_title' => 'Thank you, your payment went through',
        'pay_thanks_body' => 'You paid %s for %s. We will be in touch soon.',
        'pay_not_confirmed_title' => 'We could not confirm your payment yet',
        'pay_not_confirmed_body' => 'If money left your account, please {contact} and we will sort it out.',
        'pay_home' => 'Go to the home page',
    ],
    'es' => [
        'skip' => 'Saltar al contenido principal',
        'menu_label' => 'Principal',
        'display' => 'Opciones de pantalla',
        'text_size' => 'Tamaño del texto',
        'size_default' => 'Normal',
        'size_large' => 'Grande',
        'size_larger' => 'Muy grande',
        'colors' => 'Colores',
        'theme_auto' => 'Igual que mi dispositivo',
        'theme_light' => 'Claro',
        'theme_dark' => 'Oscuro',
        'theme_contrast' => 'Alto contraste (amarillo sobre negro)',
        'display_reset' => 'Volver a lo normal',
        'display_close' => 'Cerrar opciones de pantalla',
        'language_switch' => 'Idioma',
        'required_note' => 'Todos los campos son obligatorios, salvo los marcados como opcionales.',
        'error_title' => 'Hay un problema con su mensaje',
        'contact_name' => 'Su nombre',
        'contact_email' => 'Correo electrónico',
        'contact_phone' => 'Número de teléfono (opcional)',
        'contact_message' => '¿Cómo podemos ayudarle?',
        'contact_send' => 'Enviar mensaje',
        'contact_sent_title' => 'Gracias, su mensaje fue enviado',
        'contact_sent_body' => 'Le responderemos lo antes posible, normalmente en un día hábil.',
        'err_name' => 'Escriba su nombre.',
        'err_name_long' => 'Su nombre debe tener 200 caracteres o menos.',
        'err_email' => 'Escriba su correo electrónico.',
        'err_email_format' => 'Escriba un correo electrónico válido, por ejemplo nombre@ejemplo.com.',
        'err_phone' => 'Escriba un número de teléfono usando solo números, espacios, paréntesis, guiones o el signo más.',
        'err_message' => 'Escriba su mensaje.',
        'err_message_long' => 'Su mensaje debe tener 5,000 caracteres o menos.',
        'err_rate' => 'Ha enviado varios mensajes en la última hora. Por favor {contact}, o inténtelo más tarde.',
        'error_prefix' => 'Error: ',
        'yes' => 'Sí',
        'no' => 'No',
        'not_sure' => 'No estoy seguro',
        'testi_name' => 'Su nombre, como quiere que aparezca',
        'testi_name_hint' => 'Por ejemplo “Tasha W.” Basta con su nombre y la inicial de su apellido.',
        'testi_role' => 'Sobre usted (opcional)',
        'testi_role_hint' => 'Por ejemplo “Consejera de rehabilitación vocacional” o “usuario de VoiceOver”.',
        'testi_text' => 'Su testimonio',
        'testi_email' => 'Correo electrónico (opcional, nunca se muestra)',
        'testi_email_hint' => 'Solo para comunicarnos con usted sobre su testimonio.',
        'testi_consent' => 'Acepto que {site} publique mi testimonio y el nombre de arriba en este sitio web.',
        'testi_send' => 'Enviar testimonio',
        'testi_sent_title' => 'Gracias por compartir su experiencia',
        'testi_sent_body' => 'Leemos cada testimonio antes de publicarlo en el sitio web.',
        'err_testi_text' => 'Escriba su testimonio.',
        'err_testi_long' => 'Su testimonio debe tener 3,000 caracteres o menos.',
        'err_consent' => 'Marque la casilla para aceptar que publiquemos su testimonio.',
        'testi_error_title' => 'Hay un problema con su testimonio',
        'blog' => 'Blog',
        'blog_search' => 'Buscar en el blog',
        'search' => 'Buscar',
        'site_search' => 'Buscar',
        'minute_read' => 'minutos de lectura',
        'audio_available' => 'Versión en audio disponible',
        'listen' => 'Escuche esta publicación',
        'download_audio' => 'Descargar el audio',
        'transcript_note' => 'El texto de abajo es la transcripción completa.',
        'more_from_blog' => 'Más del blog',
        'back_to_blog' => 'Volver a todas las publicaciones',
        'browse_topics' => 'Explorar por tema',
        'follow_blog' => 'Seguir el blog',
        'rss_feed' => 'Fuente RSS del blog',
        'no_posts' => 'Todavía no hay publicaciones. Vuelva pronto.',
        'no_results' => 'No se encontraron publicaciones. Pruebe con otras palabras, o explore por tema más abajo.',
        'posts_about' => 'Publicaciones sobre',
        'all_posts' => 'Ver todas las publicaciones',
        'newer' => 'Publicaciones más recientes',
        'older' => 'Publicaciones anteriores',
        'page_of' => 'Página %d de %d',
        'topics' => 'Temas:',
        'search_results_for' => 'Resultados para',
        'found_one' => 'Se encontró 1 publicación.',
        'found_many' => 'Se encontraron %d publicaciones.',
        // Payments (lib/features/payments.php)
        'pay_button' => 'Pagar %s por %s',
        'pay_quantity' => 'Cantidad',
        'pay_note' => 'Nota (opcional)',
        'pay_note_hint' => 'Algo que debamos saber, hasta 500 caracteres.',
        'pay_secure' => 'Pagará en una página segura de Stripe. Nunca vemos los datos de su tarjeta.',
        'pay_cancelled' => 'El pago se canceló y no se cobró nada. Puede intentarlo de nuevo abajo.',
        'pay_error' => 'Lo sentimos, no pudimos iniciar el pago. Inténtelo de nuevo en unos minutos o {contact}.',
        'pay_unavailable' => 'Este artículo no está disponible por ahora.',
        'pay_thanks_title' => 'Gracias, su pago se realizó',
        'pay_thanks_body' => 'Pagó %s por %s. Nos pondremos en contacto pronto.',
        'pay_not_confirmed_title' => 'Todavía no pudimos confirmar su pago',
        'pay_not_confirmed_body' => 'Si se le cobró, por favor {contact} y lo resolveremos.',
        'pay_home' => 'Ir a la página de inicio',
    ],
];

/** The language of the page being shown, set by show_page(). */
function current_lang(?string $set = null): string
{
    static $lang = 'en';
    if ($set !== null) {
        $lang = isset(LANGUAGES[$set]) ? $set : 'en';
    }
    return $lang;
}

function t(string $key): string
{
    $text = STRINGS[current_lang()][$key] ?? STRINGS['en'][$key] ?? $key;
    // {site} and {contact} come from Settings.
    return str_contains($text, '{') ? strtr($text, ['{site}' => site_name(), '{contact}' => contact_words()]) : $text;
}

/** Spanish page files are named "es" or "es-<name>". */
function slug_lang(string $slug): string
{
    return $slug === 'es' || str_starts_with($slug, 'es-') ? 'es' : 'en';
}

/** The web address of a page. */
function page_path(string $slug): string
{
    if ($slug === 'home') {
        return '/';
    }
    if ($slug === 'es') {
        return '/es/';
    }
    if (str_starts_with($slug, 'es-')) {
        return '/es/' . substr($slug, 3) . '/';
    }
    return "/$slug/";
}
