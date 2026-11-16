<?php
// Microsoft 365 sends people back here after they sign in (the redirect address set in Microsoft Entra).
$_GET['a'] = 'oauth';
require __DIR__ . '/index.php';
