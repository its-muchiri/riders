<?php

/**
 * Local stand-in for Safaricom's Daraja API, for tests only — run with:
 *   php -S 127.0.0.1:8091 tests/support/mock_daraja.php
 * and point the app at it with MPESA_BASE_URL=http://127.0.0.1:8091.
 *
 * Implements just the calls Rider\Core\Mpesa makes (OAuth, STK Push, STK
 * Push Query, B2C) and records every request in a JSON state file so a test
 * can assert on what the app sent. Test control:
 *   POST /_control/answer   {"checkout_request_id": "...", "result_code": 0}
 *       sets the outcome STK Push Query reports for a prompt (default: unanswered)
 *   POST /_control/b2c-mode {"mode": "accept"|"reject"|"drop"}
 *       accept (default) / reject with a Daraja error / drop = hang up mid-request
 *   GET  /_control/state    returns everything recorded so far
 *   POST /_control/reset
 */

$stateFile = sys_get_temp_dir() . '/rider_mock_daraja_state.json';
$state = is_file($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];
$state += ['stk' => [], 'answers' => [], 'b2c' => [], 'b2c_mode' => 'accept'];

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode(file_get_contents('php://input') ?: '', true) ?: [];

$save = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state));
};
$json = static function (array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
};

switch ($path) {
    case '/oauth/v1/generate':
        $json(['access_token' => 'mock-token', 'expires_in' => '3599']);
        break;

    case '/mpesa/stkpush/v1/processrequest':
        $id = 'ws_CO_' . bin2hex(random_bytes(6));
        $state['stk'][$id] = $body;
        $save();
        $json([
            'MerchantRequestID' => 'mr-' . $id,
            'CheckoutRequestID' => $id,
            'ResponseCode' => '0',
            'ResponseDescription' => 'Success. Request accepted for processing',
            'CustomerMessage' => 'Success. Request accepted for processing',
        ]);
        break;

    case '/mpesa/stkpushquery/v1/query':
        $id = $body['CheckoutRequestID'] ?? '';
        if (!isset($state['answers'][$id])) {
            $json(['requestId' => 'x', 'errorCode' => '500.001.1001', 'errorMessage' => 'The transaction is being processed'], 500);
            break;
        }
        $code = $state['answers'][$id];
        $json([
            'ResponseCode' => '0',
            'ResponseDescription' => 'The service request has been accepted successfully',
            'CheckoutRequestID' => $id,
            'ResultCode' => (string) $code,
            'ResultDesc' => $code === 0 ? 'The service request is processed successfully.' : 'Request cancelled by user',
        ]);
        break;

    case '/mpesa/b2c/v1/paymentrequest':
        $state['b2c'][] = $body;
        $save();
        if ($state['b2c_mode'] === 'drop') {
            // Hang up without answering — the client sees a transport-level failure.
            exit;
        }
        if ($state['b2c_mode'] === 'reject') {
            $json(['requestId' => 'x', 'errorCode' => '500.002.1001', 'errorMessage' => 'The initiator information is invalid.'], 400);
            break;
        }
        $json([
            'ConversationID' => 'AG_' . bin2hex(random_bytes(6)),
            'OriginatorConversationID' => 'orig-' . bin2hex(random_bytes(4)),
            'ResponseCode' => '0',
            'ResponseDescription' => 'Accept the service request successfully.',
        ]);
        break;

    case '/_control/answer':
        $state['answers'][$body['checkout_request_id']] = (int) $body['result_code'];
        $save();
        $json(['ok' => true]);
        break;

    case '/_control/b2c-mode':
        $state['b2c_mode'] = $body['mode'] ?? 'accept';
        $save();
        $json(['ok' => true]);
        break;

    case '/_control/state':
        $json($state);
        break;

    case '/_control/reset':
        @unlink($stateFile);
        $json(['ok' => true]);
        break;

    default:
        $json(['error' => 'not found: ' . $path], 404);
}
