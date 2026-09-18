<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Auth;
use Rider\Core\Dispatch;
use Rider\Core\Request;
use Rider\Core\Response;

/**
 * Maps to the "Bookings (Trips/Deliveries)" and "Availability" groups in
 * planning/02-rider-co-ke/api-endpoints.md. Real-time dispatch matching
 * (nearest-rider lookup, offer cascade on decline/timeout) lives in
 * Rider\Core\Dispatch — see that class for the matching/cascade design.
 */
final class TripController
{
    // Assumed average moving speed for a boda-boda/car in Nairobi-style
    // urban traffic, used only for the ETA estimate below. No
    // directions/traffic API is wired in (MAPS_PROVIDER_API_KEY is
    // unconfigured — see .env.example) so this is a flat assumption rather
    // than a routed ETA; flagged in MVP_STATUS.md's known issues.
    private const ASSUMED_SPEED_KMH = 25.0;

    public function create(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();

        $pickupLat = (float) $request->input('pickup_lat');
        $pickupLng = (float) $request->input('pickup_lng');

        $stmt = $db->prepare(
            'INSERT INTO rider_trips
                (customer_id, trip_type, status, pickup_lat, pickup_lng, pickup_address,
                 destination_lat, destination_lng, destination_address, recipient_phone_number,
                 estimated_distance_km, estimated_duration_min, surge_multiplier, estimated_fare,
                 requested_at, created_at, updated_at)
             VALUES (:customer_id, :trip_type, \'requested\', :pickup_lat, :pickup_lng, :pickup_address,
                 :destination_lat, :destination_lng, :destination_address, :recipient_phone_number,
                 :distance_km, :duration_min, :surge, :estimated_fare, NOW(), NOW(), NOW())'
        );

        $stmt->execute([
            'customer_id' => $user['id'],
            'trip_type' => $request->input('trip_type'),
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'pickup_address' => $request->input('pickup_address'),
            'destination_lat' => $request->input('destination_lat'),
            'destination_lng' => $request->input('destination_lng'),
            'destination_address' => $request->input('destination_address'),
            'recipient_phone_number' => $request->input('recipient_phone_number'),
            'distance_km' => $request->input('estimated_distance_km', 0),
            'duration_min' => $request->input('estimated_duration_min', 0),
            'surge' => $request->input('surge_multiplier', 1.0),
            'estimated_fare' => $request->input('estimated_fare', 0),
        ]);

        $tripId = (int) $db->lastInsertId();

        $offer = Dispatch::dispatchToNextRider($db, $tripId, $pickupLat, $pickupLng);

        Response::json([
            'id' => $tripId,
            'status' => 'requested',
            'dispatch_status' => $offer ? 'offer_sent' : 'no_riders_available',
        ], 201);
    }

    public function show(Request $request): void
    {
        $db = Database::connection();
        $tripId = (int) $request->params['id'];

        Dispatch::sweepAndCascade($db, $tripId);

        $stmt = $db->prepare('SELECT * FROM rider_trips WHERE id = :id');
        $stmt->execute(['id' => $tripId]);
        $trip = $stmt->fetch();

        if (!$trip) {
            Response::notFound('Trip not found');
            return;
        }

        $trip['dispatch_status'] = Dispatch::currentDispatchStatus($db, $tripId);

        Response::json($trip);
    }

    /**
     * Server-Sent Events stream per shared-architecture.md's "Real-Time
     * Update Strategy" decision (SSE over WebSockets). Emits a "ping" event
     * whenever the trip's status or the rider's latest location changes.
     *
     * Bounded to ~25s per connection rather than looping forever: this app
     * has no long-running process model (PHP-FPM/CLI dev server request,
     * potentially a serverless function on Vercel — see MVP_STATUS.md), so
     * an unbounded loop risks hitting a request timeout mid-stream with no
     * clean close. EventSource auto-reconnects on close, so a short bound
     * is invisible to the client beyond a reconnect. trip-status.php's
     * polling fallback (documented there) covers any environment where SSE
     * doesn't flush incrementally at all (e.g. a serverless platform that
     * buffers the full response body before sending it).
     */
    public function pingStream(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $tripId = (int) $request->params['id'];

        $tripStmt = $db->prepare('SELECT customer_id, rider_id FROM rider_trips WHERE id = :id');
        $tripStmt->execute(['id' => $tripId]);
        $trip = $tripStmt->fetch();

        if (!$trip || ((int) $trip['customer_id'] !== (int) $user['id'] && (int) ($trip['rider_id'] ?? 0) !== (int) $user['id'])) {
            Response::forbidden('Not part of this trip.');
            return;
        }

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        $lastStatus = null;
        $lastPingAt = null;
        $terminal = ['completed', 'cancelled', 'disputed'];

        for ($i = 0; $i < 25; $i++) {
            $statusStmt = $db->prepare('SELECT status FROM rider_trips WHERE id = :id');
            $statusStmt->execute(['id' => $tripId]);
            $status = $statusStmt->fetchColumn();

            $pingStmt = $db->prepare('SELECT recorded_at FROM trip_pings WHERE trip_id = :trip_id ORDER BY recorded_at DESC LIMIT 1');
            $pingStmt->execute(['trip_id' => $tripId]);
            $pingAt = $pingStmt->fetchColumn() ?: null;

            if ($status !== $lastStatus || $pingAt !== $lastPingAt) {
                $lastStatus = $status;
                $lastPingAt = $pingAt;
                $dispatchStatus = Dispatch::currentDispatchStatus($db, $tripId);
                echo 'event: ping' . "\n";
                echo 'data: ' . json_encode(['status' => $status, 'dispatch_status' => $dispatchStatus]) . "\n\n";
                @flush();
            }

            if (in_array($status, $terminal, true)) {
                return;
            }

            usleep(1_000_000);
        }
    }

    /**
     * Latest known rider location for this trip plus a distance/ETA to
     * whatever the rider is currently heading toward (pickup while en
     * route to collect the customer, destination once the trip is
     * in_progress). Backs trip-status.php's live map (checklist item 3).
     */
    public function location(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $tripId = (int) $request->params['id'];

        $tripStmt = $db->prepare('SELECT * FROM rider_trips WHERE id = :id');
        $tripStmt->execute(['id' => $tripId]);
        $trip = $tripStmt->fetch();

        if (!$trip) {
            Response::notFound('Trip not found');
            return;
        }

        $isCustomer = (int) $trip['customer_id'] === (int) $user['id'];
        $isRider = $trip['rider_id'] !== null && (int) $trip['rider_id'] === (int) $user['id'];
        if (!$isCustomer && !$isRider) {
            Response::forbidden('Not part of this trip.');
            return;
        }

        if (!$trip['rider_id'] || !in_array($trip['status'], ['matched', 'rider_en_route', 'in_progress'], true)) {
            Response::json(['tracking' => false]);
            return;
        }

        $pingStmt = $db->prepare(
            'SELECT lat, lng, speed_kmh, heading_degrees, recorded_at FROM trip_pings
             WHERE trip_id = :trip_id ORDER BY recorded_at DESC LIMIT 1'
        );
        $pingStmt->execute(['trip_id' => $tripId]);
        $ping = $pingStmt->fetch();

        if (!$ping) {
            // The rider may not have sent a trip-scoped ping yet (e.g. right
            // after accepting) — fall back to their last known position from
            // rider_availability so the customer isn't staring at a blank
            // map for no reason.
            $availStmt = $db->prepare(
                'SELECT current_lat AS lat, current_lng AS lng, last_ping_at AS recorded_at
                 FROM rider_availability WHERE rider_id = :rider_id'
            );
            $availStmt->execute(['rider_id' => $trip['rider_id']]);
            $ping = $availStmt->fetch() ?: null;
        }

        if (!$ping || $ping['lat'] === null || $ping['lng'] === null) {
            Response::json(['tracking' => true, 'has_location' => false]);
            return;
        }

        $target = $trip['status'] === 'in_progress' ? 'destination' : 'pickup';
        $targetLat = (float) $trip[$target . '_lat'];
        $targetLng = (float) $trip[$target . '_lng'];

        $payload = [
            'tracking' => true,
            'has_location' => true,
            'lat' => (float) $ping['lat'],
            'lng' => (float) $ping['lng'],
            // The rider_availability fallback row (no trip-scoped ping yet)
            // has no speed_kmh/heading_degrees columns at all, unlike a real
            // trip_pings row — isset() covers both "column present but NULL"
            // and "column absent from this fallback SELECT".
            'speed_kmh' => isset($ping['speed_kmh']) ? (float) $ping['speed_kmh'] : null,
            'heading_degrees' => isset($ping['heading_degrees']) ? (float) $ping['heading_degrees'] : null,
            'recorded_at' => $ping['recorded_at'],
            'target' => $target,
            'distance_km' => null,
            'eta_minutes' => null,
        ];

        // A target of exactly (0,0) means the address was never geocoded
        // (see GeocodeController) — showing a "distance"/ETA against
        // null-island would be actively misleading, so omit it instead.
        if (abs($targetLat) > 0.0001 || abs($targetLng) > 0.0001) {
            $distanceKm = Dispatch::haversineKm((float) $ping['lat'], (float) $ping['lng'], $targetLat, $targetLng);
            $payload['distance_km'] = round($distanceKm, 2);
            $payload['eta_minutes'] = max(1, (int) round(($distanceKm / self::ASSUMED_SPEED_KMH) * 60));
        }

        Response::json($payload);
    }

    public function accept(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $tripId = (int) $request->params['id'];
        $riderId = (int) $user['id'];

        Dispatch::sweepAndCascade($db, $tripId);

        $offerStmt = $db->prepare(
            "SELECT * FROM trip_dispatch_offers WHERE trip_id = :trip_id AND rider_id = :rider_id AND status = 'offered'"
        );
        $offerStmt->execute(['trip_id' => $tripId, 'rider_id' => $riderId]);
        $offer = $offerStmt->fetch();

        if (!$offer) {
            Response::error('No active offer for this trip — it may have expired or already been taken.', 409);
            return;
        }

        // Guard against a double-accept race (two riders responding to
        // overlapping offers): only succeeds while the trip is still
        // 'requested', and only the first UPDATE to land wins.
        $updateStmt = $db->prepare(
            "UPDATE rider_trips SET rider_id = :rider_id, status = 'matched', matched_at = NOW(), updated_at = NOW()
             WHERE id = :id AND status = 'requested'"
        );
        $updateStmt->execute(['rider_id' => $riderId, 'id' => $tripId]);

        if ($updateStmt->rowCount() === 0) {
            Response::error('This trip has already been matched to another rider.', 409);
            return;
        }

        $acceptStmt = $db->prepare(
            "UPDATE trip_dispatch_offers SET status = 'accepted', responded_at = NOW() WHERE id = :id"
        );
        $acceptStmt->execute(['id' => $offer['id']]);

        $supersedeStmt = $db->prepare(
            "UPDATE trip_dispatch_offers SET status = 'superseded', responded_at = NOW()
             WHERE trip_id = :trip_id AND status = 'offered' AND id != :offer_id"
        );
        $supersedeStmt->execute(['trip_id' => $tripId, 'offer_id' => $offer['id']]);

        Response::json(['id' => $tripId, 'status' => 'matched']);
    }

    public function decline(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $db = Database::connection();
        $tripId = (int) $request->params['id'];
        $riderId = (int) $user['id'];

        $offerStmt = $db->prepare(
            "SELECT * FROM trip_dispatch_offers WHERE trip_id = :trip_id AND rider_id = :rider_id AND status = 'offered'"
        );
        $offerStmt->execute(['trip_id' => $tripId, 'rider_id' => $riderId]);
        $offer = $offerStmt->fetch();

        if (!$offer) {
            Response::error('No active offer for this trip.', 409);
            return;
        }

        $declineStmt = $db->prepare(
            "UPDATE trip_dispatch_offers SET status = 'declined', responded_at = NOW() WHERE id = :id"
        );
        $declineStmt->execute(['id' => $offer['id']]);

        $tripStmt = $db->prepare('SELECT pickup_lat, pickup_lng, status FROM rider_trips WHERE id = :id');
        $tripStmt->execute(['id' => $tripId]);
        $trip = $tripStmt->fetch();

        $nextOffer = null;
        if ($trip && $trip['status'] === 'requested') {
            $nextOffer = Dispatch::dispatchToNextRider($db, $tripId, (float) $trip['pickup_lat'], (float) $trip['pickup_lng']);
        }

        Response::json([
            'id' => $tripId,
            'status' => 'offer_declined',
            'dispatch_status' => $nextOffer ? 'offer_sent' : 'no_riders_available',
        ]);
    }

    public function updateStatus(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $allowed = ['rider_en_route', 'in_progress', 'completed'];
        $status = $request->input('status');

        if (!in_array($status, $allowed, true)) {
            Response::error('Invalid status transition', 422, ['allowed' => $allowed]);
            return;
        }

        $db = Database::connection();
        $tripId = (int) $request->params['id'];

        $tripStmt = $db->prepare('SELECT rider_id, status FROM rider_trips WHERE id = :id');
        $tripStmt->execute(['id' => $tripId]);
        $trip = $tripStmt->fetch();

        if (!$trip) {
            Response::notFound('Trip not found');
            return;
        }

        if ((int) ($trip['rider_id'] ?? 0) !== (int) $user['id']) {
            Response::forbidden('Only the assigned rider can update trip status.');
            return;
        }

        // Only the trip's own next step is a valid transition — a rider
        // can't skip from 'matched' straight to 'completed', for instance.
        $nextStatus = ['matched' => 'rider_en_route', 'rider_en_route' => 'in_progress', 'in_progress' => 'completed'];
        if (($nextStatus[$trip['status']] ?? null) !== $status) {
            Response::error("Trip is currently '{$trip['status']}' — cannot move to '{$status}'.", 422);
            return;
        }

        $timestampColumn = match ($status) {
            'in_progress' => 'started_at',
            'completed' => 'completed_at',
            default => null,
        };

        $sql = 'UPDATE rider_trips SET status = :status, updated_at = NOW()';
        if ($timestampColumn) {
            $sql .= ", {$timestampColumn} = NOW()";
        }
        $sql .= ' WHERE id = :id AND status = :current_status';

        $stmt = $db->prepare($sql);
        $stmt->execute(['status' => $status, 'id' => $tripId, 'current_status' => $trip['status']]);

        if ($stmt->rowCount() === 0) {
            Response::error('Trip status changed elsewhere — refresh and try again.', 409);
            return;
        }

        Response::json(['id' => $tripId, 'status' => $status]);
    }

    public function cancel(Request $request): void
    {
        $db = Database::connection();
        $tripId = (int) $request->params['id'];

        $stmt = $db->prepare('UPDATE rider_trips SET status = \'cancelled\', updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $tripId]);

        $cancelOffersStmt = $db->prepare(
            "UPDATE trip_dispatch_offers SET status = 'superseded', responded_at = NOW() WHERE trip_id = :trip_id AND status = 'offered'"
        );
        $cancelOffersStmt->execute(['trip_id' => $tripId]);

        Response::json(['id' => $tripId, 'status' => 'cancelled']);
    }

    public function sos(Request $request): void
    {
        // TODO: high-priority escalation — see user-flows.md's Safety
        // Incident admin journey. Must alert the Safety Admin queue
        // immediately, not just create a standard-priority dispute.
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO disputes (booking_id, raised_by, category, description, status, created_at)
             VALUES (:booking_id, :raised_by, \'safety_incident\', \'SOS triggered\', \'open\', NOW())'
        );
        $stmt->execute([
            'booking_id' => $request->params['id'],
            'raised_by' => $request->user['id'] ?? null,
        ]);

        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'sos_escalated'], 201);
    }

    public function proofOfDelivery(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('UPDATE rider_trips SET proof_of_delivery_url = :url, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['url' => $request->input('proof_of_delivery_url'), 'id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id']]);
    }

    public function nearbyRiders(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }

        $lat = (float) $request->input('lat');
        $lng = (float) $request->input('lng');
        $limit = (int) $request->input('limit', 10);

        $db = Database::connection();
        $riders = Dispatch::findNearestAvailableRiders($db, $lat, $lng, [], $limit);

        Response::json(['riders' => $riders]);
    }
}
