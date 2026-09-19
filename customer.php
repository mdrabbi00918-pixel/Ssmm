<?php
// Separate customer-panel entry point. Login/session is handled by index.php.
$_GET['page']='dashboard';
require __DIR__.'/index.php';
