<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Request;
use Rider\Core\Response;

/**
 * Loyalty points — V2 retention mechanic (see planning/02-rider-co-ke/prd.md).
 * This platform doesn't have its own loyalty_points_ledger table in the
 * original scaffold (only laundry.co.ke's database-schema.md specified
 * one) — adding it here as a platform-specific extension, following the
 * same shape, since rider.co.ke's PRD also calls for loyalty points.
 */
final class LoyaltyController
{
    public function balance(Request $request): void
    {
        $db = Database::connection();
        $customerId = $request->user['id'] ?? null;

        $stmt = $db->prepare('SELECT COALESCE(SUM(points_change), 0) AS balance FROM loyalty_points_ledger WHERE customer_id = :customer_id');
        $stmt->execute(['customer_id' => $customerId]);
        $balance = (int) $stmt->fetchColumn();

        $historyStmt = $db->prepare('SELECT * FROM loyalty_points_ledger WHERE customer_id = :customer_id ORDER BY created_at DESC LIMIT 50');
        $historyStmt->execute(['customer_id' => $customerId]);

        Response::json(['balance' => $balance, 'history' => $historyStmt->fetchAll()]);
    }

    public function redeem(Request $request): void
    {
        $db = Database::connection();
        $customerId = $request->user['id'] ?? null;
        $pointsToRedeem = (int) $request->input('points');

        $stmt = $db->prepare('SELECT COALESCE(SUM(points_change), 0) FROM loyalty_points_ledger WHERE customer_id = :customer_id');
        $stmt->execute(['customer_id' => $customerId]);
        $balance = (int) $stmt->fetchColumn();

        if ($pointsToRedeem <= 0 || $pointsToRedeem > $balance) {
            Response::error('Invalid redemption amount', 422, ['current_balance' => $balance]);
            return;
        }

        $stmt = $db->prepare(
            'INSERT INTO loyalty_points_ledger (customer_id, trip_id, points_change, reason, created_at)
             VALUES (:customer_id, :trip_id, :points, "redeemed_for_discount", NOW())'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'trip_id' => $request->input('trip_id'),
            'points' => -$pointsToRedeem,
        ]);

        Response::json(['redeemed' => $pointsToRedeem, 'new_balance' => $balance - $pointsToRedeem]);
    }
}
