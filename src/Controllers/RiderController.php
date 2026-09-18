<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Auth;
use Rider\Core\Dispatch;
use Rider\Core\Request;
use Rider\Core\Response;

/**
 * Rider availability toggle, location pings, and public profile — maps to
 * the remaining "Availability" and "Platform-Specific Resources" entries
 * in planning/02-rider-co-ke/api-endpoints.md that were missed in the
 * initial scaffold pass. Without this, riders have no way to go online or
 * report location, so TripController's matching/tracking has nothing to
 * read from — this closes that gap.
 */
final class RiderController
{
    public function setAvailability(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $riderId = (int) $user['id'];
        // Bind as 0/1 rather than a PHP bool: PDO::execute(array) sends a
        // bool through as the string '' (false) or '1' (true), and MySQL's
        // boolean (tinyint) column rejects '' with "Incorrect integer value".
        $isOnline = $request->input('is_online') ? 1 : 0;

        // Vercel's live deployment runs on Postgres (Neon) since Vercel's
        // Marketplace has no MySQL-compatible option — see
        // planning/00-portfolio/ui-implementation-plan.md — so this upsert
        // branches on the active driver; local dev keeps using MySQL's
        // ON DUPLICATE KEY UPDATE per shared-architecture.md's documented stack.
        $sql = Database::driver() === 'pgsql'
            ? 'INSERT INTO rider_availability (rider_id, is_online, updated_at) VALUES (:rider_id, :is_online, NOW())
               ON CONFLICT (rider_id) DO UPDATE SET is_online = EXCLUDED.is_online, updated_at = NOW()'
            : 'INSERT INTO rider_availability (rider_id, is_online, updated_at) VALUES (:rider_id, :is_online, NOW())
               ON DUPLICATE KEY UPDATE is_online = VALUES(is_online), updated_at = NOW()';
        $stmt = $db->prepare($sql);
        $stmt->execute(['rider_id' => $riderId, 'is_online' => $isOnline]);

        Response::json(['rider_id' => $riderId, 'is_online' => (bool) $isOnline]);
    }

    public function locationPing(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $riderId = (int) $user['id'];

        $sql = Database::driver() === 'pgsql'
            ? 'INSERT INTO rider_availability (rider_id, is_online, current_lat, current_lng, last_ping_at, updated_at)
               VALUES (:rider_id, true, :lat, :lng, NOW(), NOW())
               ON CONFLICT (rider_id) DO UPDATE SET current_lat = EXCLUDED.current_lat, current_lng = EXCLUDED.current_lng, last_ping_at = NOW(), updated_at = NOW()'
            : 'INSERT INTO rider_availability (rider_id, is_online, current_lat, current_lng, last_ping_at, updated_at)
               VALUES (:rider_id, true, :lat, :lng, NOW(), NOW())
               ON DUPLICATE KEY UPDATE current_lat = VALUES(current_lat), current_lng = VALUES(current_lng), last_ping_at = NOW(), updated_at = NOW()';
        $stmt = $db->prepare($sql);
        $stmt->execute([
            'rider_id' => $riderId,
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
        ]);

        // If this ping is for an active trip, also record it in trip_pings
        // for live tracking / post-incident investigation — see
        // database/schema.sql's retention-policy note on that table. Only
        // trusted once verified as this rider's own active trip, so a
        // rider can't write pings into a trip they're not assigned to.
        $tripId = $request->input('trip_id');
        if ($tripId) {
            $tripStmt = $db->prepare(
                "SELECT 1 FROM rider_trips WHERE id = :trip_id AND rider_id = :rider_id
                 AND status IN ('matched', 'rider_en_route', 'in_progress')"
            );
            $tripStmt->execute(['trip_id' => $tripId, 'rider_id' => $riderId]);

            if ($tripStmt->fetch()) {
                $pingStmt = $db->prepare(
                    'INSERT INTO trip_pings (trip_id, rider_id, lat, lng, speed_kmh, heading_degrees, recorded_at)
                     VALUES (:trip_id, :rider_id, :lat, :lng, :speed, :heading, NOW())'
                );
                $pingStmt->execute([
                    'trip_id' => $tripId,
                    'rider_id' => $riderId,
                    'lat' => $request->input('lat'),
                    'lng' => $request->input('lng'),
                    'speed' => $request->input('speed_kmh'),
                    'heading' => $request->input('heading_degrees'),
                ]);
            }
        }

        Response::json(['status' => 'recorded']);
    }

    /**
     * Current pending dispatch offer(s) for the logged-in rider — the
     * rider-side counterpart of TripController's dispatch/accept/decline
     * flow. Polled by src/Views/rider-dashboard.php (no SSE here per
     * shared-architecture.md's "polling as fallback" allowance; sweeping on
     * every poll is also what keeps the lazy 30s-timeout cascade moving).
     */
    public function myOffers(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $riderId = (int) $user['id'];

        $tripIdsStmt = $db->prepare(
            "SELECT DISTINCT trip_id FROM trip_dispatch_offers WHERE rider_id = :rider_id AND status = 'offered'"
        );
        $tripIdsStmt->execute(['rider_id' => $riderId]);
        foreach ($tripIdsStmt->fetchAll() as $row) {
            Dispatch::sweepAndCascade($db, (int) $row['trip_id']);
        }

        $stmt = $db->prepare(
            "SELECT o.id AS offer_id, o.trip_id, o.distance_km, o.expires_at,
                    t.trip_type, t.pickup_address, t.destination_address, t.estimated_fare, t.estimated_distance_km
             FROM trip_dispatch_offers o
             JOIN rider_trips t ON t.id = o.trip_id
             WHERE o.rider_id = :rider_id AND o.status = 'offered'
             ORDER BY o.offered_at ASC"
        );
        $stmt->execute(['rider_id' => $riderId]);

        Response::json(['offers' => $stmt->fetchAll()]);
    }

    public function profile(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, full_name, status FROM users WHERE id = :id AND account_type = \'provider\'');
        $stmt->execute(['id' => $request->params['id']]);
        $rider = $stmt->fetch();

        if (!$rider) {
            Response::notFound('Rider not found');
            return;
        }

        $performanceStmt = $db->prepare('SELECT * FROM rider_performance_tiers WHERE rider_id = :id');
        $performanceStmt->execute(['id' => $request->params['id']]);
        $rider['performance'] = $performanceStmt->fetch() ?: null;

        $vehicleStmt = $db->prepare('SELECT vehicle_type, plate_number, verification_status FROM rider_vehicle_documents WHERE rider_id = :id');
        $vehicleStmt->execute(['id' => $request->params['id']]);
        $rider['vehicle'] = $vehicleStmt->fetch() ?: null;

        Response::json($rider);
    }
}
