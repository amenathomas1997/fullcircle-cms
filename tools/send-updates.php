<?php
// Sends queued email updates, a few at a time. Run by cron every 10 minutes:
//   */10 * * * * /usr/local/bin/php /home/YOURUSER/public_html/tools/send-updates.php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require __DIR__ . '/../lib/bootstrap.php';
purge_pending_subscribers();
run_cron('often');
if (!glob(OUTBOX_DIR . '/*.json')) {
    exit(0);
}
$result = process_outbox(40);
if (!empty($result['busy'])) {
    echo date('Y-m-d H:i'), " the previous run is still sending; nothing done\n";
    exit(0);
}
echo date('Y-m-d H:i'), " sent {$result['sent']}, failed {$result['failed']}\n";
