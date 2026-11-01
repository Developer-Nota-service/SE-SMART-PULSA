<?php

// Compose healthcheck: verify that Apache and Laravel's /up endpoint respond.
$port = (int) (getenv('APP_HEALTH_PORT') ?: 80);
$context = stream_context_create([
    'http' => [
        'timeout' => 3,
        'ignore_errors' => true,
    ],
]);

$headers = @get_headers('http://127.0.0.1:'.$port.'/up', false, $context);
$status = is_array($headers) ? ($headers[0] ?? '') : '';

exit(preg_match('/\s200\s/', $status) === 1 ? 0 : 1);
