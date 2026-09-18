<?php

/**
 * End-to-end smoke test for the payment + escrow + payout leg
 * (MVP_STATUS.md: "M-Pesa payment + rider payout via escrow"). Runs against a
 * live app and a Daraja stand-in — nothing is mocked inside the app itself:
 *
 *   php -S 127.0.0.1:8091 tests/support/mock_daraja.php     # fake Daraja
 *   php -S 127.0.0.1:8000 -t public public/index.php        # the app, with
 *       MPESA_BASE_URL=http://127.0.0.1:8091 and MPESA_CALLBACK_SECRET=tok123
 *       in .env, pointed at a throwaway database loaded from database/schema.sql
 *   php tests/payments_smoke.php
 *
 * Env: APP=http://127.0.0.1:8000  DARAJA=http://127.0.0.1:8091  CALLBACK_TOKEN=tok123
 *      DB_DSN / DB_USER / DB_PASSWORD (defaults match the throwaway MariaDB used in MVP_STATUS.md passes)
 * Exits non-zero if any assertion fails.
 */

$app = getenv('APP') ?: 'http://127.0.0.1:8000';
$daraja = getenv('DARAJA') ?: 'http://127.0.0.1:8091';
$token = getenv('CALLBACK_TOKEN') ?: 'tok123';
$db = new PDO(
    getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;port=3308;dbname=rider_smoke;charset=utf8mb4',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASSWORD') ?: 'smoke',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$failures = 0;
function check(string $label, bool $ok, mixed $detail = null): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $detail === null ? '' : '  => ' . json_encode($detail)) . "\n";
    if (!$ok) {
        $failures++;
    }
}

/** @return array{0:int,1:array<string,mixed>} */
function call(string $method, string $url, ?array $body = null, string $jar = ''): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    if ($jar !== '') {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$status, json_decode((string) $raw, true) ?? ['_raw' => $raw]];
}

function darajaState(string $daraja): array
{
    return json_decode(file_get_contents("$daraja/_control/state"), true);
}

$run = substr(md5((string) microtime(true)), 0, 6);
$jars = ['customer' => tempnam(sys_get_temp_dir(), 'cj'), 'rider' => tempnam(sys_get_temp_dir(), 'rj'), 'stranger' => tempnam(sys_get_temp_dir(), 'sj')];
$phones = ['customer' => '0711' . random_int(100000, 999999), 'rider' => '0722' . random_int(100000, 999999), 'stranger' => '0733' . random_int(100000, 999999)];

call('POST', "$daraja/_control/reset");

echo "Setup\n";
foreach (['customer', 'rider', 'stranger'] as $who) {
    $payload = ['full_name' => ucfirst($who) . " $run", 'phone_number' => $phones[$who], 'password' => 'Passw0rd!x', 'account_type' => $who === 'rider' ? 'provider' : 'customer'];
    if ($who === 'rider') {
        $payload['national_id_number'] = (string) random_int(20000000, 39999999);
    }
    [$s] = call('POST', "$app/api/v1/auth/register", $payload, $jars[$who]);
    check("register $who", $s === 201, $s);
}
$riderId = (int) $db->query('SELECT id FROM users WHERE phone_number = ' . $db->quote($phones['rider']))->fetchColumn();
// Test-only: no admin KYC console exists yet (MVP_STATUS.md known issues), so activate directly.
$db->exec("UPDATE users SET status = 'active' WHERE id = $riderId");
call('PATCH', "$app/api/v1/riders/me/availability", ['is_online' => true], $jars['rider']);
call('POST', "$app/api/v1/riders/me/location-ping", ['lat' => -1.2921, 'lng' => 36.7856], $jars['rider']);

[$s, $trip] = call('POST', "$app/api/v1/trips", [
    'trip_type' => 'passenger_motorcycle', 'pickup_lat' => -1.2921, 'pickup_lng' => 36.7856, 'pickup_address' => 'Kilimani',
    'destination_lat' => -1.2676, 'destination_lng' => 36.8108, 'destination_address' => 'Westlands',
], $jars['customer']);
check('trip created + dispatched', $s === 201 && $trip['dispatch_status'] === 'offer_sent', $trip);
$tripId = (int) $trip['id'];
$fare = (float) $trip['estimated_fare'];
$due = (float) ceil($fare);

[$s] = call('PATCH', "$app/api/v1/trips/$tripId/accept", null, $jars['rider']);
check('rider accepts', $s === 200, $s);

echo "Payment gating\n";
[$s] = call('POST', "$app/api/v1/payments/mpesa/stk-push", ['trip_id' => $tripId], $jars['customer']);
check('cannot pay before the trip is completed (409)', $s === 409, $s);

foreach (['rider_en_route', 'in_progress', 'completed'] as $next) {
    [$s] = call('PATCH', "$app/api/v1/trips/$tripId/status", ['status' => $next], $jars['rider']);
    check("rider -> $next", $s === 200, $s);
}
$row = $db->query("SELECT final_fare FROM rider_trips WHERE id = $tripId")->fetch();
check('final_fare set to the quoted fare on completion', (float) $row['final_fare'] === $fare, $row);

[$s] = call('POST', "$app/api/v1/payments/mpesa/stk-push", ['trip_id' => $tripId], $jars['stranger']);
check('another customer cannot pay for it (403)', $s === 403, $s);
[$s] = call('POST', "$app/api/v1/payments/mpesa/stk-push", ['trip_id' => $tripId, 'phone' => '12345'], $jars['customer']);
check('invalid phone rejected (422)', $s === 422, $s);
[$s] = call('POST', "$app/api/v1/payments/mpesa/stk-push", ['trip_id' => $tripId], $jars['rider']);
check('rider cannot pay for their own trip (403)', $s === 403, $s);
[$s] = call('POST', "$app/api/v1/payments/mpesa/stk-push", ['trip_id' => $tripId]);
check('unauthenticated push rejected (401)', $s === 401, $s);

echo "Payment attempt 1: customer cancels the prompt\n";
[$s, $push] = call('POST', "$app/api/v1/payments/mpesa/stk-push", ['trip_id' => $tripId], $jars['customer']);
check('STK push initiated (202)', $s === 202 && !empty($push['checkout_request_id']), $push);
check('charge recorded at the whole-shilling fare', (float) $push['amount'] === $due, $push);
$sent = darajaState($daraja)['stk'][$push['checkout_request_id']] ?? [];
check('Daraja got the right amount + phone', (int) ($sent['Amount'] ?? 0) === (int) $due && ($sent['PhoneNumber'] ?? '') === '254' . substr($phones['customer'], 1), $sent);
[$s] = call('POST', "$app/api/v1/payments/mpesa/stk-push", ['trip_id' => $tripId], $jars['customer']);
check('a second prompt while one is in flight is refused (409)', $s === 409, $s);
[$s, $st] = call('GET', "$app/api/v1/trips/$tripId/payment", null, $jars['customer']);
check('state is pending', $s === 200 && $st['state'] === 'pending', $st);

$cb = fn (string $id, int $code, ?string $receipt = null) => [
    'Body' => ['stkCallback' => array_filter([
        'MerchantRequestID' => 'mr', 'CheckoutRequestID' => $id, 'ResultCode' => $code,
        'ResultDesc' => $code === 0 ? 'The service request is processed successfully.' : 'Request cancelled by user',
        'CallbackMetadata' => $code === 0 ? ['Item' => [
            ['Name' => 'Amount', 'Value' => $due], ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
        ]] : null,
    ], fn ($v) => $v !== null)],
];
[$s] = call('POST', "$app/api/v1/payments/mpesa/callback", $cb($push['checkout_request_id'], 1032));
check('callback without token rejected (403)', $s === 403, $s);
[$s] = call('POST', "$app/api/v1/payments/mpesa/callback?token=wrong", $cb($push['checkout_request_id'], 1032));
check('callback with wrong token rejected (403)', $s === 403, $s);
[$s] = call('POST', "$app/api/v1/payments/mpesa/callback?token=$token", $cb($push['checkout_request_id'], 1032));
check('cancel callback acked', $s === 200, $s);
[$s, $st] = call('GET', "$app/api/v1/trips/$tripId/payment", null, $jars['customer']);
check('state is failed and payable again', $st['state'] === 'failed' && $st['payable'] === true, $st);
check('no escrow created for a failed charge', (int) $db->query("SELECT COUNT(*) FROM escrow_transactions WHERE booking_id = $tripId")->fetchColumn() === 0);

echo "Payment attempt 2: missed callback, reconciled via STK query\n";
$db->exec("UPDATE payments SET created_at = created_at - INTERVAL 5 MINUTE WHERE booking_id = $tripId"); // age the failed one past the in-flight window
[$s, $push2] = call('POST', "$app/api/v1/payments/mpesa/stk-push", ['trip_id' => $tripId], $jars['customer']);
check('retry after failure allowed', $s === 202, $push2);
[$s, $st] = call('GET', "$app/api/v1/trips/$tripId/payment", null, $jars['customer']);
check('young pending payment is not queried yet', $st['state'] === 'pending', $st);
$db->exec('UPDATE payments SET created_at = created_at - INTERVAL 1 MINUTE WHERE external_reference = ' . $db->quote($push2['checkout_request_id']));
[$s, $st] = call('GET', "$app/api/v1/trips/$tripId/payment", null, $jars['customer']);
check('unanswered prompt stays pending after a Daraja query', $st['state'] === 'pending', $st);
call('POST', "$daraja/_control/answer", ['checkout_request_id' => $push2['checkout_request_id'], 'result_code' => 0]);
[$s, $st] = call('GET', "$app/api/v1/trips/$tripId/payment", null, $jars['customer']);
check('reconcile picks up the paid outcome', $st['state'] === 'paid', $st);

echo "Escrow + commission + payout\n";
$escrow = $db->query("SELECT * FROM escrow_transactions WHERE booking_id = $tripId")->fetchAll();
check('exactly one escrow row, released', count($escrow) === 1 && $escrow[0]['status'] === 'released' && (float) $escrow[0]['held_amount'] === $due, $escrow);
$commission = (float) $db->query("SELECT amount FROM payments WHERE booking_id = $tripId AND type = 'commission'")->fetchColumn();
$payout = $db->query("SELECT * FROM payments WHERE booking_id = $tripId AND type = 'payout'")->fetch();
$expectedPayout = floor($due - round($due * 0.18, 2));
check('payout = fare - 18% commission, whole shillings', $payout && (float) $payout['amount'] === $expectedPayout, [$payout, $expectedPayout]);
check('commission + payout = fare (ledger balances)', abs($commission + (float) $payout['amount'] - $due) < 0.005, [$commission, $payout['amount'], $due]);
check('payout belongs to the rider', (int) $payout['user_id'] === $riderId);
$b2c = darajaState($daraja)['b2c'];
check('B2C sent to Daraja once, to the rider, for the payout amount', count($b2c) === 1
    && (int) $b2c[0]['Amount'] === (int) $expectedPayout && $b2c[0]['PartyB'] === '254' . substr($phones['rider'], 1), $b2c);
check('payout awaiting Daraja result (pending, conversation id recorded)', $payout['status'] === 'pending' && str_starts_with((string) $payout['external_reference'], 'AG_'), $payout);
$conversationId = $payout['external_reference'];

[$s] = call('POST', "$app/api/v1/payments/mpesa/callback?token=$token", $cb($push2['checkout_request_id'], 0, 'RCPT' . $run));
check('late STK success callback for the already-reconciled payment acked', $s === 200, $s);
call('POST', "$app/api/v1/payments/mpesa/callback?token=$token", $cb($push2['checkout_request_id'], 0, 'RCPT' . $run));
check('duplicate deliveries do not double-pay (still 1 payout, 1 escrow)',
    (int) $db->query("SELECT COUNT(*) FROM payments WHERE booking_id = $tripId AND type = 'payout'")->fetchColumn() === 1
    && (int) $db->query("SELECT COUNT(*) FROM escrow_transactions WHERE booking_id = $tripId")->fetchColumn() === 1);
[$s, $st] = call('POST', "$app/api/v1/payments/mpesa/stk-push", ['trip_id' => $tripId], $jars['customer']);
check('paid trip cannot be paid again (409)', $s === 409, $st);

$b2cResult = ['Result' => ['ResultType' => 0, 'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.', 'ConversationID' => $conversationId, 'TransactionID' => 'B2C' . $run]];
[$s] = call('POST', "$app/api/v1/payments/mpesa/b2c-result", $b2cResult);
check('B2C result without token rejected (403)', $s === 403, $s);
[$s] = call('POST', "$app/api/v1/payments/mpesa/b2c-result?token=$token", $b2cResult);
check('B2C success result acked', $s === 200, $s);
call('POST', "$app/api/v1/payments/mpesa/b2c-result?token=$token", $b2cResult);
$payout = $db->query("SELECT * FROM payments WHERE booking_id = $tripId AND type = 'payout'")->fetch();
check('payout marked completed with the M-Pesa transaction id', $payout['status'] === 'completed' && $payout['external_reference'] === 'B2C' . $run, $payout);

echo "Visibility\n";
[$s, $st] = call('GET', "$app/api/v1/trips/$tripId/payment", null, $jars['rider']);
check('rider sees paid state + own payout', $st['state'] === 'paid' && (float) $st['payout']['amount'] === $expectedPayout && $st['payout']['status'] === 'completed', $st);
[$s] = call('GET', "$app/api/v1/trips/$tripId/payment", null, $jars['stranger']);
check('stranger cannot read payment state (403)', $s === 403, $s);
[$s, $earn] = call('GET', "$app/api/v1/riders/me/earnings", null, $jars['rider']);
check('rider earnings: totals + entries', $s === 200 && (float) $earn['totals']['paid_out'] === $expectedPayout && count($earn['entries']) === 2, $earn);
[$s] = call('GET', "$app/api/v1/riders/me/earnings");
check('earnings require login (401)', $s === 401, $s);
[$s, $cust] = call('GET', "$app/api/v1/riders/me/earnings", null, $jars['customer']);
check('a customer sees no rider earnings', $s === 200 && $cust['entries'] === [], $cust);

echo "Payout failure paths\n";
$freshCompletedTrip = function () use ($app, $jars): int {
    [, $t] = call('POST', "$app/api/v1/trips", ['trip_type' => 'parcel_delivery', 'pickup_lat' => -1.2921, 'pickup_lng' => 36.7856, 'destination_lat' => -1.3000, 'destination_lng' => 36.8000, 'pickup_address' => 'A', 'destination_address' => 'B'], $jars['customer']);
    call('PATCH', "$app/api/v1/trips/{$t['id']}/accept", null, $jars['rider']);
    foreach (['rider_en_route', 'in_progress', 'completed'] as $next) {
        call('PATCH', "$app/api/v1/trips/{$t['id']}/status", ['status' => $next], $jars['rider']);
    }

    return (int) $t['id'];
};
$payTrip = function (int $tid) use ($app, $jars, $token, $cb): void {
    [, $p] = call('POST', "$app/api/v1/payments/mpesa/stk-push", ['trip_id' => $tid], $jars['customer']);
    call('POST', "$app/api/v1/payments/mpesa/callback?token=$token", $cb($p['checkout_request_id'], 0, 'R' . random_int(1000, 9999)));
};

call('POST', "$daraja/_control/b2c-mode", ['mode' => 'reject']);
$t2 = $freshCompletedTrip();
$payTrip($t2);
$p2 = $db->query("SELECT * FROM payments WHERE booking_id = $t2 AND type = 'payout'")->fetch();
check('Daraja-rejected B2C leaves the payout pending and retryable', $p2 && $p2['status'] === 'pending' && $p2['external_reference'] === null, $p2);
call('POST', "$daraja/_control/b2c-mode", ['mode' => 'accept']);
call('GET', "$app/api/v1/riders/me/earnings", null, $jars['rider']);
$p2 = $db->query("SELECT * FROM payments WHERE booking_id = $t2 AND type = 'payout'")->fetch();
check('viewing earnings retries the payout, which now goes out', str_starts_with((string) $p2['external_reference'], 'AG_'), $p2);

$before = count(darajaState($daraja)['b2c']);
call('POST', "$daraja/_control/b2c-mode", ['mode' => 'drop']);
$t3 = $freshCompletedTrip();
$payTrip($t3);
call('POST', "$daraja/_control/b2c-mode", ['mode' => 'accept']);
call('GET', "$app/api/v1/riders/me/earnings", null, $jars['rider']);
$p3 = $db->query("SELECT * FROM payments WHERE booking_id = $t3 AND type = 'payout'")->fetch();
$after = count(darajaState($daraja)['b2c']);
check('a B2C call with unknown outcome is NOT auto-retried (no double-pay risk)', $p3['status'] === 'pending' && $p3['external_reference'] === 'b2c-initiating' && $after === $before + 1, [$p3, $before, $after]);

$b2cFail = ['Result' => ['ResultType' => 0, 'ResultCode' => 2001, 'ResultDesc' => 'The initiator information is invalid.', 'ConversationID' => $p2['external_reference']]];
call('POST', "$app/api/v1/payments/mpesa/b2c-result?token=$token", $b2cFail);
$p2 = $db->query("SELECT status FROM payments WHERE booking_id = $t2 AND type = 'payout'")->fetch();
check('failed B2C result marks the payout failed', $p2['status'] === 'failed', $p2);

foreach ($jars as $jar) {
    @unlink($jar);
}
echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
