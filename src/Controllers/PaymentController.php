<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Request;
use Rider\Core\Response;

/**
 * See planning/02-rider-co-ke/open-questions.md #1 — fare pre-authorization
 * vs. post-trip charge is unresolved; this stub assumes post-trip charge
 * (the simpler of the two given M-Pesa STK Push has no native hold/capture).
 */
final class PaymentController
{
    public function stkPush(Request $request): void
    {
        Response::json(['status' => 'stk_push_initiated', 'checkout_request_id' => null], 202);
    }

    public function card(Request $request): void
    {
        Response::json(['status' => 'card_charge_initiated'], 202);
    }

    public function mpesaCallback(Request $request): void
    {
        // TODO: idempotent per checkout_request_id — see shared-architecture.md.
        Response::json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    public function myEarnings(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM payments WHERE user_id = :user_id AND type = "payout" ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $request->user['id'] ?? null]);

        Response::json($stmt->fetchAll());
    }
}
