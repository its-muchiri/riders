<?php

namespace Rider\Core;

use PDO;
use Rider\Config\Database;
use Throwable;

/**
 * Escrow & Commission Engine v1 (planning/00-portfolio/shared-architecture.md
 * module 2) for rider.co.ke: hold a completed trip charge, then release it to
 * the rider minus platform commission. Trust-tier retention/release defaults
 * follow shared-architecture.md's trust-tiering table; rider trips are Tier 1
 * (under KES 5,000) in practice, with Tier 2/3 handled for completeness.
 *
 * Payment timing (open-questions.md #1) is assumed to be post-trip charge:
 * the customer pays via STK Push once the rider marks the trip completed, so
 * that payment *is* the customer's confirmation and Tier 1/2 funds are
 * released as soon as they're held ("near-instantly", per the roadmap). Tier 3
 * (>= KES 100,000, not reachable by a boda/taxi fare but kept for parity with
 * the shared engine) holds a 10% retention until an admin releases it.
 *
 * Disbursement to the rider's M-Pesa number (B2C) is attempted immediately
 * when B2C credentials are configured; otherwise the payout stays a
 * `pending` ledger row that the next disburse attempt picks up (payout
 * frequency is open-questions.md #3 — near-instant is assumed).
 */
final class Escrow
{
    /** Default commission rate when no commission_rules row matches — prd.md proposes 15–25%, midpoint-ish. */
    private const DEFAULT_COMMISSION_PERCENTAGE = 18.0;

    /** commission_rules.category used for rider trips. */
    private const COMMISSION_CATEGORY = 'rider_trip';

    /** Marker in payments.external_reference while a B2C request is in flight / of unknown outcome. */
    private const DISBURSE_IN_FLIGHT = 'b2c-initiating';

    /**
     * Opens an escrow hold for a completed trip charge.
     *
     * @return string the escrow row's release_condition, so the caller knows whether to release now
     */
    public static function hold(PDO $db, int $paymentId, int $tripId, float $amount): string
    {
        [$retentionPercentage, $releaseCondition] = self::trustTierDefaults($amount);

        $stmt = $db->prepare(
            'INSERT INTO escrow_transactions
                (payment_id, booking_id, held_amount, retention_percentage, release_condition, status)
             VALUES (:payment_id, :booking_id, :held_amount, :retention_percentage, :release_condition, \'held\')'
        );
        $stmt->execute([
            'payment_id' => $paymentId,
            'booking_id' => $tripId,
            'held_amount' => $amount,
            'retention_percentage' => $retentionPercentage,
            'release_condition' => $releaseCondition,
        ]);

        return $releaseCondition;
    }

    /**
     * Releases a trip's held escrow to its rider minus platform commission.
     * Writes the commission (settled immediately — it's a ledger entry, not
     * an outbound transfer) and payout (pending until disbursed) ledger
     * rows. Returns null if nothing is held for this trip.
     *
     * The payout is rounded down to whole shillings (M-Pesa B2C only moves
     * whole KES), with the sub-shilling remainder folded into commission so
     * that the ledger matches what actually gets disbursed.
     *
     * @return array{commission_amount:float,payout_amount:float,retained_amount:float,payout_payment_id:int}|null
     */
    public static function release(PDO $db, int $tripId, int $riderId): ?array
    {
        $stmt = $db->prepare(
            'SELECT * FROM escrow_transactions WHERE booking_id = :booking_id AND status = \'held\' FOR UPDATE'
        );
        $stmt->execute(['booking_id' => $tripId]);
        $escrow = $stmt->fetch();

        if (!$escrow) {
            return null;
        }

        $heldAmount = (float) $escrow['held_amount'];
        $retainedAmount = round($heldAmount * (float) $escrow['retention_percentage'] / 100, 2);
        $commissionAmount = round($heldAmount * self::commissionRate($db) / 100, 2);
        $payoutAmount = (float) max(0, floor($heldAmount - $commissionAmount - $retainedAmount));
        // Whatever isn't paid out or retained is the platform's — includes the sub-shilling rounding remainder.
        $commissionAmount = round($heldAmount - $retainedAmount - $payoutAmount, 2);

        $paymentStmt = $db->prepare('SELECT method FROM payments WHERE id = :id');
        $paymentStmt->execute(['id' => $escrow['payment_id']]);
        $originalMethod = $paymentStmt->fetchColumn() ?: 'mpesa_stk';

        $insertPayment = $db->prepare(
            'INSERT INTO payments (user_id, booking_id, type, method, amount, currency, status, created_at, updated_at)
             VALUES (:user_id, :booking_id, :type, :method, :amount, \'KES\', :status, NOW(), NOW())'
        );

        $insertPayment->execute([
            'user_id' => $riderId,
            'booking_id' => $tripId,
            'type' => 'commission',
            'method' => $originalMethod,
            'amount' => $commissionAmount,
            'status' => 'completed',
        ]);

        $insertPayment->execute([
            'user_id' => $riderId,
            'booking_id' => $tripId,
            'type' => 'payout',
            'method' => 'mpesa_b2c',
            'amount' => $payoutAmount,
            'status' => 'pending',
        ]);
        $payoutPaymentId = (int) $db->lastInsertId();

        $update = $db->prepare(
            'UPDATE escrow_transactions SET status = :status, released_at = NOW() WHERE id = :id'
        );
        $update->execute([
            'status' => $retainedAmount > 0 ? 'partially_released' : 'released',
            'id' => $escrow['id'],
        ]);

        return [
            'commission_amount' => $commissionAmount,
            'payout_amount' => $payoutAmount,
            'retained_amount' => $retainedAmount,
            'payout_payment_id' => $payoutPaymentId,
        ];
    }

    /**
     * Sends one pending payout to the rider's M-Pesa number via B2C. Safe to
     * call repeatedly and concurrently: the row is claimed with a conditional
     * UPDATE first, so two callers can't both send it.
     *
     * Returns 'sent' (B2C accepted, awaiting Daraja's result callback),
     * 'skipped' (B2C not configured, or already claimed by someone else — the
     * row stays pending for a later attempt), or 'failed' (Daraja rejected it
     * outright or the payout is un-sendable; row released for retry / marked
     * failed respectively).
     */
    public static function disburse(PDO $db, int $payoutPaymentId): string
    {
        if (!Mpesa::isB2cConfigured()) {
            return 'skipped';
        }

        $stmt = $db->prepare(
            'SELECT p.id, p.user_id, p.amount, u.phone_number
             FROM payments p JOIN users u ON u.id = p.user_id
             WHERE p.id = :id AND p.type = \'payout\' AND p.status = \'pending\' AND p.external_reference IS NULL'
        );
        $stmt->execute(['id' => $payoutPaymentId]);
        $payout = $stmt->fetch();

        if (!$payout) {
            return 'skipped';
        }

        $amount = (int) floor((float) $payout['amount']);
        if ($amount < 1) {
            // Nothing sendable (fare fully absorbed by commission/rounding) — close it out rather than retry forever.
            $db->prepare('UPDATE payments SET status = \'completed\', updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $payoutPaymentId]);
            return 'skipped';
        }

        $claim = $db->prepare(
            'UPDATE payments SET external_reference = :marker, updated_at = NOW()
             WHERE id = :id AND external_reference IS NULL'
        );
        $claim->execute(['marker' => self::DISBURSE_IN_FLIGHT, 'id' => $payoutPaymentId]);
        if ($claim->rowCount() === 0) {
            return 'skipped';
        }

        $phone = Mpesa::normalizePhone((string) $payout['phone_number']);

        try {
            $response = Mpesa::b2c($phone, $amount, 'rider.co.ke trip payout');
        } catch (MpesaTransportException $e) {
            // Unknown whether Daraja got the request — leave the in-flight
            // marker so this row is NOT retried automatically (a retry could
            // pay the rider twice); it needs a manual reconcile against the
            // Daraja portal.
            error_log("B2C payout {$payoutPaymentId} outcome unknown, left marked in-flight: " . $e->getMessage());
            return 'failed';
        } catch (Throwable $e) {
            // Daraja answered and rejected it — safe to release for a retry.
            error_log("B2C payout {$payoutPaymentId} rejected: " . $e->getMessage());
            $db->prepare('UPDATE payments SET external_reference = NULL, updated_at = NOW() WHERE id = :id')
                ->execute(['id' => $payoutPaymentId]);
            return 'failed';
        }

        $conversationId = (string) ($response['ConversationID'] ?? '');
        $db->prepare('UPDATE payments SET external_reference = :ref, updated_at = NOW() WHERE id = :id')
            ->execute(['ref' => $conversationId !== '' ? $conversationId : self::DISBURSE_IN_FLIGHT, 'id' => $payoutPaymentId]);

        return 'sent';
    }

    /**
     * Retries every pending, never-sent payout for a rider (e.g. B2C was
     * unconfigured or rejected at release time). Cheap no-op when there's
     * nothing to send or B2C isn't configured.
     */
    public static function disbursePendingFor(int $riderId): void
    {
        if (!Mpesa::isB2cConfigured()) {
            return;
        }

        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT id FROM payments
             WHERE user_id = :user_id AND type = \'payout\' AND status = \'pending\' AND external_reference IS NULL
             ORDER BY id LIMIT 20'
        );
        $stmt->execute(['user_id' => $riderId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            self::disburse($db, (int) $id);
        }
    }

    /**
     * @return array{0:float,1:string} [retention_percentage, release_condition]
     */
    private static function trustTierDefaults(float $amount): array
    {
        if ($amount < 100000) {
            // Tier 1/2 — the customer's post-trip payment is their completion
            // confirmation, so funds are releasable straight away.
            return [0.0, 'customer_confirmation'];
        }

        // Tier 3 — retain 10%, released only after admin review.
        return [10.0, 'admin_release'];
    }

    private static function commissionRate(PDO $db): float
    {
        $stmt = $db->prepare(
            'SELECT value, commission_type FROM commission_rules
             WHERE platform = \'rider\' AND category = :category
                AND effective_from <= NOW()
                AND (effective_to IS NULL OR effective_to > NOW())
             ORDER BY effective_from DESC LIMIT 1'
        );
        $stmt->execute(['category' => self::COMMISSION_CATEGORY]);
        $rule = $stmt->fetch();

        if ($rule && $rule['commission_type'] === 'percentage') {
            return (float) $rule['value'];
        }

        return self::DEFAULT_COMMISSION_PERCENTAGE;
    }
}
