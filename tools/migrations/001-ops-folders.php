<?php
/**
 * Migration 001: make sure the folders the operations layer writes to exist.
 * Run once by tools/update.php --apply and noted in data/migrations.json; safe to run again.
 */

declare(strict_types=1);

foreach ([DATA_DIR . '/cache', DATA_DIR . '/stats', UPLOAD_DIR . '/audio'] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException("Could not create $dir.");
    }
}
