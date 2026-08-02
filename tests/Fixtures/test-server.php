<?php

/**
 * Router for the throwaway `php -S` server used by HttpClientCurlTest.
 *
 * It exists so the SDK's REAL cURL transport can be exercised end to end —
 * curl_exec, curl_getinfo, the header/body split and header parsing — instead
 * of being stubbed out by an injected transport. Everything in HttpClient
 * below the transport seam is untestable any other way.
 *
 * Routes (all under the SDK's /verify/v1 prefix):
 *   GET    /verify/v1/ok        200 + a success envelope + assorted headers
 *   POST   /verify/v1/echo      200 + an envelope echoing method and raw body
 *   GET    /verify/v1/limited   429 + Retry-After: 42
 *   GET    /verify/v1/garbage   200 + a body that is not JSON
 *
 * Not part of the shipped SDK — tests/ is excluded from the Composer package.
 */

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Content-Type: application/json');

switch ($path) {
    case '/verify/v1/ok':
        // A header whose VALUE contains colons, so the parser is forced to
        // split on the first colon only rather than on every one.
        header('X-Echo-Url: https://example.com:8443/a:b');
        echo json_encode([
            'success' => true,
            'data'    => ['token' => 'xtk_from_wire'],
            'meta'    => ['request_id' => 'req_wire_1'],
        ], JSON_THROW_ON_ERROR);
        return;

    case '/verify/v1/echo':
        echo json_encode([
            'success' => true,
            'data'    => [
                'method' => $method,
                'query'  => $_SERVER['QUERY_STRING'] ?? '',
                'body'   => file_get_contents('php://input'),
            ],
        ], JSON_THROW_ON_ERROR);
        return;

    case '/verify/v1/limited':
        http_response_code(429);
        header('Retry-After: 42');
        echo json_encode([
            'success' => false,
            'error'   => ['code' => 'RATE_LIMITED', 'message' => 'Slow down'],
        ], JSON_THROW_ON_ERROR);
        return;

    case '/verify/v1/garbage':
        echo 'this is not json';
        return;

    default:
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error'   => ['code' => 'NOT_FOUND', 'message' => 'No such fixture route'],
        ], JSON_THROW_ON_ERROR);
}
