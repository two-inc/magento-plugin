<?php
/**
 * Copyright © Two.inc All rights reserved.
 * See COPYING.txt for license details.
 *
 * Router for `php -S`, used by order-term-wire-probe.php. It is
 * wire-echo-server.php with one difference: the merchant record offers 30, 60
 * and 90 days with 30 as its default, so a buyer can choose a term other than
 * the default. Everything else, including the request log, is the shared
 * server's.
 */
declare(strict_types=1);

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $path === '/v1/merchant/probe-merchant') {
    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'probe-merchant',
        'short_name' => 'probe',
        'available_terms' => [30, 60, 90],
        'due_in_days' => 30,
    ]);
    return true;
}

return require __DIR__ . '/wire-echo-server.php';
