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
        body{font-family:Arial,sans-serif;max-width:900px;margin:40px auto;padding:0 16px}
        pre{white-space:pre-wrap;word-break:break-word;background:#f5f5f5;padding:16px;border-radius:10px}
    </style>
</head>
<body>
    <h1>সুমন ভাই Panel</h1>
    <p>SMMSUN service connection test</p>
    <pre><?= htmlspecialchars(json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></pre>
</body>
</html>
