<?php

// Router for `php -S` used by LocalServer. Behaviour is read from files in
// TF_TEST_DIR on every request, so a test can change it while running:
//   mode: ok (default) | 503 | 409 | 429 | jitter (ok after 0-15 ms)
// Every request appends a JSON line to requests.log when received and when
// answered, for request counts and ordering checks.

$dir = getenv('TF_TEST_DIR');
$mode = trim((string) @file_get_contents("{$dir}/mode")) ?: 'ok';
$id = bin2hex(random_bytes(6));
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$log = function (string $event) use ($dir, $id, $method, $path): void {
    file_put_contents(
        "{$dir}/requests.log",
        json_encode(['id' => $id, 'event' => $event, 'method' => $method, 'path' => $path, 'time' => microtime(true)])."\n",
        FILE_APPEND | LOCK_EX,
    );
};

$log('received');

$status = match ($mode) {
    '503' => 503,
    '409' => 409,
    '429' => 429,
    default => $method === 'POST' ? 201 : 200,
};

if ($mode === 'jitter') {
    usleep(random_int(0, 15000));
}

$log('answered');
http_response_code($status);
header('Content-Type: application/json');
echo '{}';

return true;
