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

    public function pingStream(Request $request): void
    {
        // TODO: implement as an SSE stream — see shared-architecture.md's
        // "Real-Time Update Strategy" (SSE, not WebSockets, per the portfolio
        // decision). Polls trip_pings for this trip and flushes new rows.
        // Tracked under checklist item 3 (live GPS tracking), not this
        // dispatch-matching item.
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo "event: ping\ndata: {}\n\n";
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
        $allowed = ['rider_en_route', 'in_progress', 'completed'];
        $status = $request->input('status');

        if (!in_array($status, $allowed, true)) {
            Response::error('Invalid status transition', 422, ['allowed' => $allowed]);
            return;
        }

        $timestampColumn = match ($status) {
            'in_progress' => 'started_at',
            'completed' => 'completed_at',
            default => null,
        };

        $db = Database::connection();
        $sql = 'UPDATE rider_trips SET status = :status, updated_at = NOW()';
        if ($timestampColumn) {
            $sql .= ", {$timestampColumn} = NOW()";
        }
        $sql .= ' WHERE id = :id';

        $stmt = $db->prepare($sql);
        $stmt->execute(['status' => $status, 'id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'status' => $status]);
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
