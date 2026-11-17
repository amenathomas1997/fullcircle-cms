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
        // Forms (lib/features/forms.php)
        'form_send' => 'Send',
        'form_optional' => '(optional)',
        'form_choose' => 'Choose one',
        'form_error_title' => 'There is a problem with the form',
        'form_sent_title' => 'Thank you',
        'form_sent_body' => 'Your form was sent.',
        'form_err_required' => 'Fill in “%s”.',
        'form_err_choose' => 'Choose an answer for “%s”.',
        'form_err_check' => 'Tick the box “%s”.',
        'form_err_email' => 'Enter “%s” as an email address, like name@example.com.',
        'form_err_phone' => 'Enter “%s” using only numbers, spaces, brackets, dashes or a plus sign.',
        'form_err_number' => 'Enter “%s” as a number.',
        'form_err_date' => 'Enter “%s” as a date.',
        'form_err_long' => '“%s” must be 5,000 characters or fewer.',
        'form_err_option' => 'Choose one of the answers offered for “%s”.',
        // Events (lib/features/events.php)
        'events' => 'Events',
        'upcoming_events' => 'Upcoming events',
        'past_events' => 'Past events',
        'no_upcoming_events' => 'There are no upcoming events right now. Check back soon.',
        'all_events' => 'All events',
        'add_to_calendar' => 'Add to my calendar',
        'events_feed' => 'Subscribe to all events (calendar feed)',
        'event_when' => 'When',
        'event_where' => 'Where',
        'event_online' => 'Online',
        'join_online' => 'Join online',
        'all_day' => 'All day',
        'time_to' => 'to',
        'event_repeats_weekly' => 'Repeats every week',
        'event_repeats_monthly' => 'Repeats every month',
        'event_until' => 'until',
        'next_dates' => 'Next dates',
        'back_to_events' => 'Back to all events',
        'weekday_0' => 'Sunday', 'weekday_1' => 'Monday', 'weekday_2' => 'Tuesday', 'weekday_3' => 'Wednesday', 'weekday_4' => 'Thursday', 'weekday_5' => 'Friday', 'weekday_6' => 'Saturday',
        'month_1' => 'January', 'month_2' => 'February', 'month_3' => 'March', 'month_4' => 'April', 'month_5' => 'May', 'month_6' => 'June',
        'month_7' => 'July', 'month_8' => 'August', 'month_9' => 'September', 'month_10' => 'October', 'month_11' => 'November', 'month_12' => 'December',
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
        'form_send' => 'Enviar',
        'form_optional' => '(opcional)',
        'form_choose' => 'Elija una opción',
        'form_error_title' => 'Hay un problema con el formulario',
        'form_sent_title' => 'Gracias',
        'form_sent_body' => 'Su formulario fue enviado.',
        'form_err_required' => 'Complete “%s”.',
        'form_err_choose' => 'Elija una respuesta para “%s”.',
        'form_err_check' => 'Marque la casilla “%s”.',
        'form_err_email' => 'Escriba “%s” como un correo electrónico, por ejemplo nombre@ejemplo.com.',
        'form_err_phone' => 'Escriba “%s” usando solo números, espacios, paréntesis, guiones o el signo más.',
        'form_err_number' => 'Escriba “%s” como un número.',
        'form_err_date' => 'Escriba “%s” como una fecha.',
        'form_err_long' => '“%s” debe tener 5,000 caracteres o menos.',
        'form_err_option' => 'Elija una de las respuestas ofrecidas para “%s”.',
        'events' => 'Eventos',
        'upcoming_events' => 'Próximos eventos',
        'past_events' => 'Eventos pasados',
        'no_upcoming_events' => 'No hay eventos próximos por ahora. Vuelva pronto.',
        'all_events' => 'Todos los eventos',
        'add_to_calendar' => 'Añadir a mi calendario',
        'events_feed' => 'Suscribirse a todos los eventos (fuente de calendario)',
        'event_when' => 'Cuándo',
        'event_where' => 'Dónde',
        'event_online' => 'En línea',
        'join_online' => 'Participar en línea',
        'all_day' => 'Todo el día',
        'time_to' => 'a',
        'event_repeats_weekly' => 'Se repite cada semana',
        'event_repeats_monthly' => 'Se repite cada mes',
        'event_until' => 'hasta el',
        'next_dates' => 'Próximas fechas',
        'back_to_events' => 'Volver a todos los eventos',
        'weekday_0' => 'domingo', 'weekday_1' => 'lunes', 'weekday_2' => 'martes', 'weekday_3' => 'miércoles', 'weekday_4' => 'jueves', 'weekday_5' => 'viernes', 'weekday_6' => 'sábado',
        'month_1' => 'enero', 'month_2' => 'febrero', 'month_3' => 'marzo', 'month_4' => 'abril', 'month_5' => 'mayo', 'month_6' => 'junio',
        'month_7' => 'julio', 'month_8' => 'agosto', 'month_9' => 'septiembre', 'month_10' => 'octubre', 'month_11' => 'noviembre', 'month_12' => 'diciembre',
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
