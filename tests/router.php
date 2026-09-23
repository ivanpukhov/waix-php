<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
header('Content-Type: application/json');
if (str_starts_with($path,'/api/v1/transport/')) {
    header('X-Request-Id: req-transport');header('Retry-After: 7');
    $kind=basename($path);
    if($kind==='html'){http_response_code(429);echo '<html>wait</html>';return;}
    if($kind==='oversize'){echo str_repeat('x',2048);return;}
    if($kind==='timeout'){usleep(250000);echo '{"data":{}}';return;}
    if($kind==='partial'){header('Content-Length: 500');echo '{"data":{}}';return;}
}
if ($path==='/health') { echo '{}'; return; }
if ($path==='/api/v1/connections') { header('Location: /stolen',true,302); echo '{}'; return; }
if ($path==='/api/v1/messages' && ($_SERVER['HTTP_AUTHORIZATION']??'')==='Bearer fixture' && ($_SERVER['HTTP_IDEMPOTENCY_KEY']??'')==='f5bf0474-d4b6-4ca5-bd1b-92e44f3ad0fb') {
    $data=json_decode(file_get_contents('php://input'),true);
    if (($data['type']??'')==='text') { http_response_code(202); echo '{"data":{"id":"http-fixture"}}'; return; }
}
http_response_code(400);echo '{"error":"Unexpected fixture request"}';
