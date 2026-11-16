<?php
// Loads everything the public site and the control panel share.
declare(strict_types=1);

require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/graph-mail.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/contact.php';
require_once __DIR__ . '/testimonials.php';
require_once __DIR__ . '/blog.php';
require_once __DIR__ . '/resources.php';
require_once __DIR__ . '/quiz.php';
require_once __DIR__ . '/chooser.php';
require_once __DIR__ . '/collections.php';
require_once __DIR__ . '/linkcheck.php';
require_once __DIR__ . '/assistant.php';
require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/search.php';
require_once __DIR__ . '/subscribers.php';
require_once __DIR__ . '/bookings.php';
require_once __DIR__ . '/shortcodes.php';
require_once __DIR__ . '/starter.php';

// Optional features (lib/features/) and this site's own code (site/) register themselves last.
load_features();
