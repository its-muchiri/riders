<?php

namespace Rider\Controllers;

use PDO;
use PDOException;
use Rider\Config\Database;
use Rider\Core\Auth;
use Rider\Core\Escrow;
use Rider\Core\Mpesa;
use Rider\Core\Request;
use Rider\Core\Response;
use Throwable;

/**
 * M-Pesa payment endpoints + rider earnings. Payment timing follows the
 * assumption logged in MVP_STATUS.md for open-questions.md #1: post-trip
 * charge (M-Pesa STK Push has no native hold/capture), so a trip can only be
 * paid once the rider has marked it completed. Once the charge is confirmed,
 * Rider\Core\Escrow holds it, releases it to the rider minus commission, and
 * kicks off the B2C payout (see Escrow's docblock).
 *
 * Callbacks are idempotent per shared-architecture.md's "M-Pesa Callback
 * Idempotency" section: each is logged to payment_callbacks_log keyed by
 * CheckoutRequestID / ConversationID (UNIQUE) before processing, and a
 * duplicate delivery is acked as a no-op. A missed STK callback is caught by
 * tripPayment(), which queries Daraja for the outcome of a stale pending
 * payment (the "reconcile pending payments" requirement, done lazily since
 * this stack has no cron — same trade-off as Dispatch::sweepAndCascade).
 */
final class PaymentController
{
    /** An STK prompt stays answerable for roughly this long; a newer request within it would double-prompt the customer. */
    private const STK_PROMPT_WINDOW_SECONDS = 120;

    /** Don't hit Daraja's query API for a payment younger than this — the callback normally lands within seconds. */
    private const RECONCILE_AFTER_SECONDS = 15;

    /**
     * Initiates an M-Pesa STK Push for a completed trip's fare. Records a
     * pending `payments` row keyed by Daraja's CheckoutRequestID; the
     * callback (or tripPayment()'s reconcile) settles it.
     */
    public function stkPush(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $tripId = (int) $request->input('trip_id', 0);
        if ($tripId <= 0) {
            Response::error('trip_id is required', 422);
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM rider_trips WHERE id = :id');
        $stmt->execute(['id' => $tripId]);
        $trip = $stmt->fetch();

        if (!$trip) {
            Response::notFound('Trip not found');
            return;
        }
        if ((int) $trip['customer_id'] !== (int) $user['id']) {
            Response::forbidden('This trip does not belong to you');
            return;
        }
        if ($trip['status'] !== 'completed') {
            Response::error('You can pay once the rider has completed the trip (currently: ' . $trip['status'] . ').', 409);
            return;
        }
        if (self::tripIsPaid($db, $tripId)) {
            Response::error('This trip has already been paid', 409);
            return;
        }

        $inFlight = $db->prepare(
            'SELECT COUNT(*) FROM payments
             WHERE booking_id = :id AND type = \'charge\' AND status = \'pending\' AND created_at > ' . self::agoSql(self::STK_PROMPT_WINDOW_SECONDS)
        );
        $inFlight->execute(['id' => $tripId]);
        if ((int) $inFlight->fetchColumn() > 0) {
            Response::error('An M-Pesa prompt was already sent for this trip — check your phone, or wait a minute and try again.', 409);
            return;
        }

        // The fare is fixed upfront (prd.md "Upfront Transparent Pricing");
        // final_fare is set when the trip completes. M-Pesa only moves whole
        // shillings, so charge (and record) the fare rounded up.
        $amount = (float) ceil((float) ($trip['final_fare'] ?? $trip['estimated_fare']));
        if ($amount <= 0) {
            Response::error('There is no payable amount for this trip', 422);
            return;
        }

        $phone = Mpesa::normalizePhone(trim((string) $request->input('phone', $user['phone_number'] ?? '')));
        if (!preg_match('/^254(7|1)\d{8}$/', $phone)) {
            Response::error('Enter a valid Kenyan M-Pesa number (e.g. 07XXXXXXXX)', 422);
            return;
        }

        if (!Mpesa::isConfigured()) {
            Response::error(
                'M-Pesa is not configured in this environment (MPESA_* env vars are empty) — '
                    . 'STK Push cannot be sent. See .env.example.',
                503
            );
            return;
        }

        try {
            $daraja = Mpesa::stkPush($phone, $amount, 'TRIP' . $tripId, 'rider.co.ke trip #' . $tripId);
        } catch (Throwable $e) {
            error_log((string) $e);
            Response::error('Could not reach M-Pesa — please try again in a moment.', 502);
            return;
        }

        $checkoutRequestId = $daraja['CheckoutRequestID'] ?? null;
        if (!$checkoutRequestId) {
            Response::error('M-Pesa did not return a CheckoutRequestID', 502);
            return;
        }

        $db->beginTransaction();
        try {
            $insert = $db->prepare(
                'INSERT INTO payments (user_id, booking_id, type, method, amount, currency, external_reference, status, created_at, updated_at)
                 VALUES (:user_id, :booking_id, \'charge\', \'mpesa_stk\', :amount, \'KES\', :external_reference, \'pending\', NOW(), NOW())'
            );
            $insert->execute([
                'user_id' => $user['id'],
                'booking_id' => $tripId,
                'amount' => $amount,
                'external_reference' => $checkoutRequestId,
            ]);
            $paymentId = (int) $db->lastInsertId();

            $link = $db->prepare('UPDATE rider_trips SET payment_id = :payment_id, updated_at = NOW() WHERE id = :id');
            $link->execute(['payment_id' => $paymentId, 'id' => $tripId]);

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        Response::json([
            'status' => 'stk_push_initiated',
            'payment_id' => $paymentId,
            'amount' => $amount,
            'checkout_request_id' => $checkoutRequestId,
            'customer_message' => $daraja['CustomerMessage'] ?? 'Enter your M-Pesa PIN on your phone to complete payment.',
        ], 202);
    }

    public function card(Request $request): void
    {
        // Not part of rider.co.ke's MVP cut (roadmap: "M-Pesa payment") —
        // returning a real 501 rather than a fake "initiated" 202 so
        // callers don't believe a charge happened.
        Response::error('Card payments are not available yet — pay with M-Pesa.', 501);
    }

    /**
     * Payment state for a trip, visible to its customer and its rider:
     * unpaid / pending / paid / failed. If the latest STK Push is still
     * pending well after it was sent, asks Daraja for its outcome first, so
     * a missed callback can't leave a paid trip looking unpaid.
     */
    public function tripPayment(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $tripId = (int) $request->params['id'];

        $stmt = $db->prepare('SELECT * FROM rider_trips WHERE id = :id');
        $stmt->execute(['id' => $tripId]);
        $trip = $stmt->fetch();

        if (!$trip) {
            Response::notFound('Trip not found');
            return;
        }

        $isCustomer = (int) $trip['customer_id'] === (int) $user['id'];
        $isRider = $trip['rider_id'] !== null && (int) $trip['rider_id'] === (int) $user['id'];
        if (!$isCustomer && !$isRider) {
            Response::forbidden('This trip does not belong to you');
            return;
        }

        $latest = self::latestCharge($db, $tripId);

        if ($latest && $latest['status'] === 'pending' && Mpesa::isConfigured()
            && self::isOlderThan($db, (int) $latest['id'], self::RECONCILE_AFTER_SECONDS)) {
            try {
                $outcome = Mpesa::stkQuery((string) $latest['external_reference']);
                if ($outcome['result_code'] !== null) {
                    self::settleCharge($db, (int) $latest['id'], $outcome['result_code'] === 0, null);
                    $latest = self::latestCharge($db, $tripId);
                }
            } catch (Throwable $e) {
                // Daraja unreachable right now — report it as still pending and try again on the next poll.
                error_log('STK reconcile failed for payment ' . $latest['id'] . ': ' . $e->getMessage());
            }
        }

        $paid = self::tripIsPaid($db, $tripId);
        $state = $paid ? 'paid' : ($latest ? ($latest['status'] === 'pending' ? 'pending' : 'failed') : 'unpaid');

        $body = [
            'trip_id' => $tripId,
            'trip_status' => $trip['status'],
            'payable' => $trip['status'] === 'completed' && !$paid,
            'amount_due' => (float) ceil((float) ($trip['final_fare'] ?? $trip['estimated_fare'])),
            'state' => $state,
            'payment_id' => $latest ? (int) $latest['id'] : null,
        ];

        if ($isRider) {
            $payoutStmt = $db->prepare(
                'SELECT amount, status FROM payments WHERE booking_id = :id AND type = \'payout\' ORDER BY id DESC LIMIT 1'
            );
            $payoutStmt->execute(['id' => $tripId]);
            $payout = $payoutStmt->fetch();
            $body['payout'] = $payout ? ['amount' => (float) $payout['amount'], 'status' => $payout['status']] : null;
        }

        Response::json($body);
    }

    /**
     * M-Pesa STK Push callback receiver (idempotent — see class docblock).
     */
    public function mpesaCallback(Request $request): void
    {
        if (!self::callbackAuthorized($request)) {
            Response::forbidden('Invalid callback token');
            return;
        }

        $payload = self::callbackPayload($request);
        $callback = $payload['Body']['stkCallback'] ?? null;
        $checkoutRequestId = is_array($callback) ? ($callback['CheckoutRequestID'] ?? null) : null;

        if (!$checkoutRequestId) {
            error_log('M-Pesa callback received with no recognizable stkCallback payload: ' . json_encode($payload));
            self::ack();
            return;
        }

        $db = Database::connection();

        if (!self::logCallback($db, (string) $checkoutRequestId, $payload)) {
            self::ack(); // already processed this delivery
            return;
        }

        $paymentStmt = $db->prepare(
            'SELECT id FROM payments WHERE external_reference = :ref AND method = \'mpesa_stk\' AND type = \'charge\' LIMIT 1'
        );
        $paymentStmt->execute(['ref' => $checkoutRequestId]);
        $paymentId = $paymentStmt->fetchColumn();

        if ($paymentId === false) {
            error_log("M-Pesa callback for unknown CheckoutRequestID {$checkoutRequestId}");
            self::markCallbackProcessed($db, (string) $checkoutRequestId);
            self::ack();
            return;
        }

        try {
            $items = [];
            foreach ($callback['CallbackMetadata']['Item'] ?? [] as $item) {
                if (isset($item['Name'])) {
                    $items[$item['Name']] = $item['Value'] ?? null;
                }
            }

            self::settleCharge(
                $db,
                (int) $paymentId,
                (int) ($callback['ResultCode'] ?? 1) === 0,
                isset($items['MpesaReceiptNumber']) ? (string) $items['MpesaReceiptNumber'] : null
            );
            self::markCallbackProcessed($db, (string) $checkoutRequestId);
        } catch (Throwable $e) {
            error_log((string) $e);
            // Forget we saw this delivery so Safaricom's retry (or the
            // tripPayment reconcile) can process it — otherwise a transient
            // failure would leave a real payment permanently unrecorded.
            $db->prepare('DELETE FROM payment_callbacks_log WHERE checkout_request_id = :id')
                ->execute(['id' => $checkoutRequestId]);
            Response::error('Temporary processing failure', 500);
            return;
        }

        self::ack();
    }

    /**
     * M-Pesa B2C result callback: marks a rider payout completed or failed.
     */
    public function b2cResult(Request $request): void
    {
        if (!self::callbackAuthorized($request)) {
            Response::forbidden('Invalid callback token');
            return;
        }

        $payload = self::callbackPayload($request);
        $result = $payload['Result'] ?? null;
        $conversationId = is_array($result) ? ($result['ConversationID'] ?? null) : null;

        if (!$conversationId) {
            error_log('M-Pesa B2C result received with no recognizable Result payload: ' . json_encode($payload));
            self::ack();
            return;
        }

        $db = Database::connection();

        if (!self::logCallback($db, (string) $conversationId, $payload)) {
            self::ack();
            return;
        }

        $succeeded = (int) ($result['ResultCode'] ?? 1) === 0;
        $reference = $succeeded ? (string) ($result['TransactionID'] ?? $conversationId) : (string) $conversationId;

        $update = $db->prepare(
            'UPDATE payments SET status = :status, external_reference = :new_ref, updated_at = NOW()
             WHERE external_reference = :conversation_id AND type = \'payout\' AND status = \'pending\''
        );
        $update->execute([
            'status' => $succeeded ? 'completed' : 'failed',
            'new_ref' => $reference,
            'conversation_id' => $conversationId,
        ]);

        if ($update->rowCount() === 0) {
            error_log("M-Pesa B2C result for unknown/settled ConversationID {$conversationId}");
        } elseif (!$succeeded) {
            error_log("B2C payout {$conversationId} failed: " . ($result['ResultDesc'] ?? 'no description'));
        }

        self::markCallbackProcessed($db, (string) $conversationId);
        self::ack();
    }

    /**
     * M-Pesa B2C queue-timeout callback: Daraja gave up waiting to process
     * the request. The payout outcome is unknown, so it's deliberately left
     * pending/in-flight (never auto-retried — see Escrow::disburse) and just
     * logged for manual reconciliation.
     */
    public function b2cTimeout(Request $request): void
    {
        if (!self::callbackAuthorized($request)) {
            Response::forbidden('Invalid callback token');
            return;
        }

        error_log('M-Pesa B2C queue timeout: ' . json_encode(self::callbackPayload($request)));
        self::ack();
    }

    /**
     * The rider's payout/commission ledger plus totals. Also gives any
     * never-sent payout another disbursement attempt.
     */
    public function myEarnings(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        Escrow::disbursePendingFor((int) $user['id']);

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id, booking_id AS trip_id, type, method, amount, status, created_at
             FROM payments
             WHERE user_id = :user_id AND type IN (\'payout\', \'commission\')
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['user_id' => $user['id']]);
        $rows = $stmt->fetchAll();

        $paidOut = 0.0;
        $pending = 0.0;
        $commission = 0.0;
        foreach ($rows as $row) {
            $amount = (float) $row['amount'];
            if ($row['type'] === 'commission') {
                $commission += $amount;
            } elseif ($row['status'] === 'completed') {
                $paidOut += $amount;
            } elseif ($row['status'] === 'pending') {
                $pending += $amount;
            }
        }

        Response::json([
            'totals' => [
                'paid_out' => round($paidOut, 2),
                'pending_payout' => round($pending, 2),
                'commission_deducted' => round($commission, 2),
            ],
            'entries' => $rows,
        ]);
    }

    /**
     * Applies a charge's outcome exactly once: on success marks the payment
     * completed and (if the trip isn't already paid by another charge) holds
     * and releases escrow to the rider, then kicks off the payout; on
     * failure marks it failed. Shared by the STK callback and the
     * tripPayment() reconcile so both take the same path. Safe to call twice
     * for the same payment — only a pending (or, for a late success, failed)
     * payment changes state.
     */
    private static function settleCharge(PDO $db, int $paymentId, bool $succeeded, ?string $receipt): void
    {
        $payoutPaymentId = null;

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('SELECT * FROM payments WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $paymentId]);
            $payment = $stmt->fetch();

            if (!$payment || $payment['status'] === 'completed') {
                $db->commit();
                return;
            }

            if (!$succeeded) {
                if ($payment['status'] === 'pending') {
                    $db->prepare('UPDATE payments SET status = \'failed\', updated_at = NOW() WHERE id = :id')
                        ->execute(['id' => $paymentId]);
                }
                $db->commit();
                return;
            }

            $tripId = (int) $payment['booking_id'];

            $alreadyPaid = $db->prepare(
                'SELECT COUNT(*) FROM payments WHERE booking_id = :trip AND type = \'charge\' AND status = \'completed\' AND id <> :id'
            );
            $alreadyPaid->execute(['trip' => $tripId, 'id' => $paymentId]);
            $duplicate = (int) $alreadyPaid->fetchColumn() > 0;

            $db->prepare(
                'UPDATE payments SET status = \'completed\', external_reference = :ref, updated_at = NOW() WHERE id = :id'
            )->execute(['ref' => $receipt ?? $payment['external_reference'], 'id' => $paymentId]);

            if ($duplicate) {
                // The customer paid twice (answered two STK prompts). The second charge is real
                // money with no escrow to back it — flag it for a manual refund rather than pay the rider twice.
                error_log("M-Pesa payment {$paymentId} duplicates an already-paid trip {$tripId} — manual refund required");
            } else {
                $riderStmt = $db->prepare('SELECT rider_id FROM rider_trips WHERE id = :id');
                $riderStmt->execute(['id' => $tripId]);
                $riderId = (int) $riderStmt->fetchColumn();

                $db->prepare('UPDATE rider_trips SET payment_id = :payment_id, updated_at = NOW() WHERE id = :id')
                    ->execute(['payment_id' => $paymentId, 'id' => $tripId]);

                $releaseCondition = Escrow::hold($db, $paymentId, $tripId, (float) $payment['amount']);
                if ($releaseCondition !== 'admin_release' && $riderId > 0) {
                    $released = Escrow::release($db, $tripId, $riderId);
                    $payoutPaymentId = $released['payout_payment_id'] ?? null;
                }
            }

            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // Outbound HTTP to Daraja happens after the ledger is committed, never inside the transaction.
        if ($payoutPaymentId !== null) {
            Escrow::disburse($db, $payoutPaymentId);
        }
    }

    private static function tripIsPaid(PDO $db, int $tripId): bool
    {
        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM payments WHERE booking_id = :id AND type = \'charge\' AND status = \'completed\''
        );
        $stmt->execute(['id' => $tripId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return array<string,mixed>|null */
    private static function latestCharge(PDO $db, int $tripId): ?array
    {
        $stmt = $db->prepare(
            'SELECT * FROM payments WHERE booking_id = :id AND type = \'charge\' ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['id' => $tripId]);

        return $stmt->fetch() ?: null;
    }

    private static function isOlderThan(PDO $db, int $paymentId, int $seconds): bool
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM payments WHERE id = :id AND created_at < ' . self::agoSql($seconds));
        $stmt->execute(['id' => $paymentId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** SQL expression for "now minus N seconds" on whichever driver is active. */
    private static function agoSql(int $seconds): string
    {
        return Database::driver() === 'pgsql'
            ? "(NOW() - INTERVAL '{$seconds} seconds')"
            : "DATE_SUB(NOW(), INTERVAL {$seconds} SECOND)";
    }

    /**
     * Records an inbound callback in payment_callbacks_log. Returns false if
     * this exact CheckoutRequestID/ConversationID was already logged (a
     * duplicate delivery), which the caller acks as a no-op success.
     *
     * @param array<string,mixed> $payload
     */
    private static function logCallback(PDO $db, string $key, array $payload): bool
    {
        try {
            $db->prepare(
                'INSERT INTO payment_callbacks_log (checkout_request_id, raw_payload, created_at) VALUES (:id, :payload, NOW())'
            )->execute(['id' => $key, 'payload' => json_encode($payload)]);
        } catch (PDOException $e) {
            // SQLSTATE 23000 (MySQL duplicate entry) / 23505 (Postgres unique violation).
            if ($e->getCode() === '23000' || $e->getCode() === '23505') {
                return false;
            }
            throw $e;
        }

        return true;
    }

    private static function markCallbackProcessed(PDO $db, string $key): void
    {
        $db->prepare('UPDATE payment_callbacks_log SET processed_at = NOW() WHERE checkout_request_id = :id')
            ->execute(['id' => $key]);
    }

    /**
     * Daraja callbacks aren't signed. If MPESA_CALLBACK_SECRET is set, every
     * callback URL registered with Safaricom must carry it as `?token=` —
     * otherwise anyone who learns a CheckoutRequestID could POST a forged
     * "success" and get a trip marked paid. Unset (local dev/sandbox) means
     * no check.
     */
    private static function callbackAuthorized(Request $request): bool
    {
        $secret = getenv('MPESA_CALLBACK_SECRET');
        if (!$secret) {
            return true;
        }

        return hash_equals($secret, (string) ($request->query['token'] ?? ''));
    }

    /** @return array<string,mixed> */
    private static function callbackPayload(Request $request): array
    {
        if (!empty($request->body)) {
            return $request->body;
        }

        $decoded = json_decode(file_get_contents('php://input') ?: '', true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Daraja expects this exact envelope; a non-success ack just makes Safaricom retry delivery. */
    private static function ack(): void
    {
        Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
