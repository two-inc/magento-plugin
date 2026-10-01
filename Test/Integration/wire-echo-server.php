<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Router for `php -S`, used by api-wire-probe.php (TWO-26150). Records each
 * request it receives as one JSON line in the file named by WIRE_ECHO_LOG and
 * answers the way the API's documented contract does: the order edit and the
 * self-invoice upload request are PUT-only, and any other method is refused
 * with a 405 and an HTML body. An order id starting `refused-` is refused the
 * same way whatever the method, standing in for an edit the API turns down.
 * A GET of an order answers its state and status: FULFILLED for an id
 * starting `fulfilled-`, status PARTIAL for one starting `partial-`, and
 * CONFIRMED and APPROVED otherwise.
 */
declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'];
$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = (string)file_get_contents('php://input');

file_put_contents(
    (string)getenv('WIRE_ECHO_LOG'),
    json_encode([
        'method' => $method,
        'path' => $path,
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
        'body' => $body,
    ]) . PHP_EOL,
    FILE_APPEND | LOCK_EX
);

$routes = [
    '#^/v1/order/[^/]+$#' => [200, ['id' => 'probe-order-id']],
    '#^/uploads/v1/invoice/[^/]+/external_invoice/\d+$#' => [202, [
        'url' => 'https://storage.example.test/probe',
        'headers' => ['Content-Type' => 'application/pdf'],
        'reference' => 'probe-reference',
    ]],
];
if ($method === 'GET' && preg_match('#^/v1/order/([^/]+)$#', $path, $m) && !str_starts_with($m[1], 'refused-')) {
    header('Content-Type: application/json');
    echo json_encode([
        'id' => $m[1],
        'state' => str_starts_with($m[1], 'fulfilled-') ? 'FULFILLED' : 'CONFIRMED',
        'status' => str_starts_with($m[1], 'partial-') ? 'PARTIAL' : 'APPROVED',
    ]);
    return true;
}
foreach ($routes as $pattern => [$status, $response]) {
    if (preg_match($pattern, $path) && $method === 'PUT' && !str_starts_with($path, '/v1/order/refused-')) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($response);
        return true;
    }
}

http_response_code(405);
header('Content-Type: text/html');
echo '<!doctype html><title>405 Method Not Allowed</title><h1>Method Not Allowed</h1>';
return true;
