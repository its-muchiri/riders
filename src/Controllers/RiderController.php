<?php

namespace Rider\Controllers;

use Rider\Config\Database;
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
        $db = Database::connection();
        $riderId = $request->user['id'] ?? null;
        $isOnline = (bool) $request->input('is_online');

        $stmt = $db->prepare(
            'INSERT INTO rider_availability (rider_id, is_online, updated_at) VALUES (:rider_id, :is_online, NOW())
             ON DUPLICATE KEY UPDATE is_online = VALUES(is_online), updated_at = NOW()'
        );
        $stmt->execute(['rider_id' => $riderId, 'is_online' => $isOnline]);

        Response::json(['rider_id' => $riderId, 'is_online' => $isOnline]);
    }

    public function locationPing(Request $request): void
    {
        $db = Database::connection();
        $riderId = $request->user['id'] ?? null;

        $stmt = $db->prepare(
            'INSERT INTO rider_availability (rider_id, is_online, current_lat, current_lng, last_ping_at, updated_at)
             VALUES (:rider_id, true, :lat, :lng, NOW(), NOW())
             ON DUPLICATE KEY UPDATE current_lat = VALUES(current_lat), current_lng = VALUES(current_lng), last_ping_at = NOW(), updated_at = NOW()'
        );
        $stmt->execute([
            'rider_id' => $riderId,
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
        ]);

        // If this ping is for an active trip, also record it in trip_pings
        // for live tracking / post-incident investigation — see
        // database/schema.sql's retention-policy note on that table.
        $tripId = $request->input('trip_id');
        if ($tripId) {
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

        Response::json(['status' => 'recorded']);
    }

    public function profile(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT id, full_name, status FROM users WHERE id = :id AND account_type = "provider"');
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
