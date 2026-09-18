<?php
require_once __DIR__ . '/SmmsunClient.php';

$client = new SmmsunClient(
    getenv('SMM_API_URL') ?: '',
    getenv('SMM_API_KEY') ?: ''
);

$result = $client->services();
?>
<!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>সুমন ভাই Panel</title>
<style>
body{font-family:system-ui,sans-serif;max-width:900px;margin:40px auto;padding:0 16px;background:#f6f7f9}
.card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:16px;margin:10px 0}
pre{white-space:pre-wrap;overflow:auto}
</style>
</head>
<body>
<h1>সুমন ভাই Panel</h1>
<p>SMM provider service-list connection test</p>
<div class="card"><pre><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></pre></div>
</body>
</html>
