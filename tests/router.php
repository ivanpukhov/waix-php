<?php
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
header('Content-Type: application/json');
if ($path==='/health') { echo '{}'; return; }
if ($path==='/api/v1/connections') { header('Location: /stolen',true,302); echo '{}'; return; }
if ($path==='/api/v1/messages' && ($_SERVER['HTTP_AUTHORIZATION']??'')==='Bearer fixture' && ($_SERVER['HTTP_IDEMPOTENCY_KEY']??'')==='f5bf0474-d4b6-4ca5-bd1b-92e44f3ad0fb') {
    $data=json_decode(file_get_contents('php://input'),true);
    if (($data['type']??'')==='text') { http_response_code(202); echo '{"data":{"id":"http-fixture"}}'; return; }
}
http_response_code(400);echo '{"error":"Unexpected fixture request"}';
