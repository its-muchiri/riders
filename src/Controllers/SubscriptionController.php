<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Request;
use Rider\Core\Response;

/**
 * Reduced-commission rider subscription — V2 retention mechanic (see
 * planning/02-rider-co-ke/prd.md and build-sequencing-roadmap.md's V2
 * milestone for this platform). Plan catalog hardcoded as a starting
 * point; move to a `rider_subscription_plans` table if plans need to be
 * admin-configurable.
 */
final class SubscriptionController
{
    private const PLANS = [
        ['id' => 'weekly_reduced', 'plan_type' => 'weekly', 'discounted_commission_rate' => 12.0, 'subscription_fee' => 300],
        ['id' => 'monthly_reduced', 'plan_type' => 'monthly', 'discounted_commission_rate' => 10.0, 'subscription_fee' => 1000],
    ];

    public function subscribe(Request $request): void
    {
        $planId = $request->input('plan_id');
        $plan = null;
        foreach (self::PLANS as $candidate) {
            if ($candidate['id'] === $planId) {
                $plan = $candidate;
                break;
            }
        }

        if (!$plan) {
            Response::error('Unknown plan_id', 422, ['known_plans' => array_column(self::PLANS, 'id')]);
            return;
        }

        $periodEnd = $plan['plan_type'] === 'weekly' ? '+7 days' : '+1 month';

        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO rider_subscriptions
                (rider_id, plan_type, discounted_commission_rate, subscription_fee, status,
                 current_period_start, current_period_end, created_at)
             VALUES (:rider_id, :plan_type, :rate, :fee, "active", CURDATE(), :period_end, NOW())'
        );
        $stmt->execute([
            'rider_id' => $request->user['id'] ?? null,
            'plan_type' => $plan['plan_type'],
            'rate' => $plan['discounted_commission_rate'],
            'fee' => $plan['subscription_fee'],
            'period_end' => date('Y-m-d', strtotime($periodEnd)),
        ]);

        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'active'], 201);
    }

    public function myStatus(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT * FROM rider_subscriptions WHERE rider_id = :rider_id AND status = "active" ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute(['rider_id' => $request->user['id'] ?? null]);

        Response::json($stmt->fetch() ?: null);
    }
}
