<?php
// Microsoft sends the owner back here after they allow calendar access (the redirect address set in Microsoft Entra).
$_GET['a'] = 'appointments';
$_GET['do'] = 'ms-callback';
require __DIR__ . '/index.php';
