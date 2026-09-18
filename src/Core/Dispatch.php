<?php

namespace Rider\Core;

use PDO;
use Rider\Config\Database;

/**
 * Real-time dispatch matching shared by TripController (customer-facing
 * trip lifecycle) and RiderController (rider-facing offers view) — see
 * shared-architecture.md's Booking & Availability Engine (real-time
 * dispatch mode) and planning/02-rider-co-ke/open-questions.md #2 for the
 * 30s offer window / cascade-on-decline-or-timeout design this implements.
 *
 * There is no background job runner in this stack, so the 30s-timeout
 * sweep is lazy: sweepAndCascade() is called at the top of any endpoint
 * that touches a trip's or rider's dispatch state, expiring stale 'offered'
 * rows and re-dispatching to the next-nearest rider. Self-healing as long
 * as something is polling (customer's trip status page, or a rider's
 * offers view) — acceptable for MVP without a queue/cron.
 */
final class Dispatch
{
    private const OFFER_WINDOW_SECONDS = 30;

    public static function sweepAndCascade(PDO $db, int $tripId): void
    {
        $expireStmt = $db->prepare(
            "UPDATE trip_dispatch_offers SET status = 'expired', responded_at = NOW()
             WHERE trip_id = :trip_id AND status = 'offered' AND expires_at < NOW()"
        );
        $expireStmt->execute(['trip_id' => $tripId]);

        $tripStmt = $db->prepare('SELECT pickup_lat, pickup_lng, status FROM rider_trips WHERE id = :id');
        $tripStmt->execute(['id' => $tripId]);
        $trip = $tripStmt->fetch();

        if (!$trip || $trip['status'] !== 'requested') {
            return;
        }

        // Retry dispatch whenever the trip is still 'requested' with no
        // live offer outstanding — not only right after an offer just
        // expired. This also covers the case where the trip was created
        // with zero riders online (dispatch_status: no_riders_available)
        // and a rider has since come online; dispatchToNextRider() already
        // excludes riders previously offered this trip, so this is safe to
        // retry on every call.
        $hasLiveOffer = $db->prepare("SELECT 1 FROM trip_dispatch_offers WHERE trip_id = :trip_id AND status = 'offered' LIMIT 1");
        $hasLiveOffer->execute(['trip_id' => $tripId]);
        if ($hasLiveOffer->fetch()) {
            return;
        }

        self::dispatchToNextRider($db, $tripId, (float) $trip['pickup_lat'], (float) $trip['pickup_lng']);
    }

    /**
     * Finds the nearest available rider who hasn't already been offered
     * this trip and creates a new trip_dispatch_offers row with a 30s
     * response window. Returns the offer array, or null if no rider is
     * available.
     */
    public static function dispatchToNextRider(PDO $db, int $tripId, float $lat, float $lng): ?array
    {
        $excludeStmt = $db->prepare('SELECT DISTINCT rider_id FROM trip_dispatch_offers WHERE trip_id = :trip_id');
        $excludeStmt->execute(['trip_id' => $tripId]);
        $excludeIds = array_map('intval', array_column($excludeStmt->fetchAll(), 'rider_id'));

        $candidates = self::findNearestAvailableRiders($db, $lat, $lng, $excludeIds, 1);
        if (!$candidates) {
            return null;
        }

        $nearest = $candidates[0];

        $expiresAtSql = Database::driver() === 'pgsql'
            ? 'NOW() + INTERVAL \'' . self::OFFER_WINDOW_SECONDS . ' seconds\''
            : 'DATE_ADD(NOW(), INTERVAL ' . self::OFFER_WINDOW_SECONDS . ' SECOND)';

        $insertStmt = $db->prepare(
            "INSERT INTO trip_dispatch_offers (trip_id, rider_id, status, distance_km, offered_at, expires_at)
             VALUES (:trip_id, :rider_id, 'offered', :distance_km, NOW(), {$expiresAtSql})"
        );
        $insertStmt->execute([
            'trip_id' => $tripId,
            'rider_id' => $nearest['rider_id'],
            'distance_km' => $nearest['distance_km'],
        ]);

        return [
            'offer_id' => (int) $db->lastInsertId(),
            'rider_id' => (int) $nearest['rider_id'],
            'distance_km' => $nearest['distance_km'],
        ];
    }

    /**
     * Haversine proximity query against rider_availability, restricted to
     * online riders on active (KYC-approved) accounts. The trig functions
     * used (RADIANS/SIN/COS/ASIN/SQRT/POWER) are standard SQL supported
     * identically by both MySQL and Postgres, so this one query string
     * works unmodified against either driver — see Database::driver().
     *
     * @param int[] $excludeRiderIds
     * @return array<int, array{rider_id:int, full_name:string, distance_km:float}>
     */
    public static function findNearestAvailableRiders(PDO $db, float $lat, float $lng, array $excludeRiderIds, int $limit): array
    {
        // :lat is needed twice in the distance expression below — real
        // (non-emulated) prepared statements reject reusing one named
        // placeholder twice in a query (see Database::connection() /
        // src/Core/Auth.php's same note), so bind it under two names.
        $params = ['lat' => $lat, 'lat2' => $lat, 'lng' => $lng, 'limit' => $limit];

        $excludeSql = '';
        if ($excludeRiderIds) {
            $placeholders = [];
            foreach (array_values($excludeRiderIds) as $index => $riderId) {
                $key = "exclude_{$index}";
                $placeholders[] = ":{$key}";
                $params[$key] = $riderId;
            }
            $excludeSql = ' AND ra.rider_id NOT IN (' . implode(',', $placeholders) . ')';
        }

        $sql = "SELECT ra.rider_id, u.full_name,
                    (6371 * 2 * ASIN(SQRT(
                        POWER(SIN(RADIANS(ra.current_lat - :lat) / 2), 2) +
                        COS(RADIANS(:lat2)) * COS(RADIANS(ra.current_lat)) *
                        POWER(SIN(RADIANS(ra.current_lng - :lng) / 2), 2)
                    ))) AS distance_km
                FROM rider_availability ra
                JOIN users u ON u.id = ra.rider_id
                WHERE ra.is_online = TRUE
                    AND u.status = 'active'
                    AND u.account_type = 'provider'
                    AND ra.current_lat IS NOT NULL
                    AND ra.current_lng IS NOT NULL
                    {$excludeSql}
                ORDER BY distance_km ASC
                LIMIT :limit";

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $type = $key === 'limit' || str_starts_with($key, 'exclude_') ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $type);
        }
        $stmt->execute();

        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['rider_id'] = (int) $row['rider_id'];
            $row['distance_km'] = round((float) $row['distance_km'], 2);
        }

        return $rows;
    }

    public static function currentDispatchStatus(PDO $db, int $tripId): ?string
    {
        $stmt = $db->prepare("SELECT status FROM trip_dispatch_offers WHERE trip_id = :trip_id AND status = 'offered' LIMIT 1");
        $stmt->execute(['trip_id' => $tripId]);

        return $stmt->fetch() ? 'offer_sent' : null;
    }
}
