<?php
/**
 * Migration 002, kit 1.6.0: the folders the new pieces write to, and the appointment
 * "gap between appointments" setting split into minutes before and minutes after.
 * Run once by tools/update.php --apply and noted in data/migrations.json; safe to run again.
 */

declare(strict_types=1);

foreach ([DATA_DIR . '/pending/pages'] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        throw new RuntimeException("Could not create $dir.");
    }
}

// One "buffer" became "buffer_before" and "buffer_after"; a site that set the old one keeps it on both sides.
$s = settings();
$a = (array) ($s['appointments'] ?? []);
if ($a && array_key_exists('buffer', $a) && !array_key_exists('buffer_before', $a) && !array_key_exists('buffer_after', $a)) {
    $a['buffer_before'] = (int) $a['buffer'];
    $a['buffer_after'] = (int) $a['buffer'];
    $s['appointments'] = $a;
    save_settings($s);
}
