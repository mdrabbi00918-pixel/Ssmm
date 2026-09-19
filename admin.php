<?php
// Separate admin-panel entry point. Authentication and authorization remain in index.php.
$_GET['page']='admin';
require __DIR__.'/index.php';
