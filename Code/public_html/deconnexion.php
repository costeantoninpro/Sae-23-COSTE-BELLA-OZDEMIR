<?php
require_once __DIR__ . '/session_utils.php';

startAppSession();

$_SESSION = [];
session_destroy();

header('Location: ' . appUrl('index.php'));
exit;
?>
