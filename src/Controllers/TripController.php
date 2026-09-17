<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Request;
use Rider\Core\Response;

/**
 * Maps to the "Bookings (Trips/Deliveries)" and "Availability" groups in
 * planning/02-rider-co-ke/api-endpoints.md. Real-time dispatch matching
 * (nearest-rider lookup, offer cascade on decline/timeout) is stubbed —
 * see planning/00-portfolio/shared-architecture.md's Booking & Availability
 * Engine (real-time dispatch mode) for what this should eventually call into.
 */
final class TripController
{
    public function create(Request $request): void
    {
        $db = Database::connection();

        // TODO: dispatch to nearest available rider via rider_availability
        // (see database/schema.sql), per planning/02-rider-co-ke/user-flows.md
        // step 3, with the offer-cascade timing from open-questions.md #2.
        $stmt = $db->prepare(
            'INSERT INTO rider_trips
                (customer_id, trip_type, status, pickup_lat, pickup_lng, pickup_address,
                 destination_lat, destination_lng, destination_address, recipient_phone_number,
                 estimated_distance_km, estimated_duration_min, surge_multiplier, estimated_fare,
                 requested_at, created_at, updated_at)
             VALUES (:customer_id, :trip_type, "requested", :pickup_lat, :pickup_lng, :pickup_address,
                 :destination_lat, :destination_lng, :destination_address, :recipient_phone_number,
                 :distance_km, :duration_min, :surge, :estimated_fare, NOW(), NOW(), NOW())'
        );

        $stmt->execute([
            'customer_id' => $request->user['id'] ?? null,
            'trip_type' => $request->input('trip_type'),
            'pickup_lat' => $request->input('pickup_lat'),
            'pickup_lng' => $request->input('pickup_lng'),
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

        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'requested'], 201);
    }

    public function show(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM rider_trips WHERE id = :id');
        $stmt->execute(['id' => $request->params['id']]);
        $trip = $stmt->fetch();

        if (!$trip) {
            Response::notFound('Trip not found');
            return;
        }

        Response::json($trip);
    }

    public function pingStream(Request $request): void
    {
        // TODO: implement as an SSE stream — see shared-architecture.md's
        // "Real-Time Update Strategy" (SSE, not WebSockets, per the portfolio
        // decision). Polls trip_pings for this trip and flushes new rows.
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        echo "event: ping\ndata: {}\n\n";
    }

    public function accept(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE rider_trips SET rider_id = :rider_id, status = "matched", matched_at = NOW(), updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['rider_id' => $request->user['id'] ?? null, 'id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'status' => 'matched']);
    }

    public function decline(Request $request): void
    {
        // TODO: cascade the offer to the next-nearest rider — see
        // open-questions.md #2 for the (unresolved) cascade timing.
        Response::json(['id' => (int) $request->params['id'], 'status' => 'offer_declined']);
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
        $stmt = $db->prepare('UPDATE rider_trips SET status = "cancelled", updated_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $request->params['id']]);

        Response::json(['id' => (int) $request->params['id'], 'status' => 'cancelled']);
    }

    public function sos(Request $request): void
    {
        // TODO: high-priority escalation — see user-flows.md's Safety
        // Incident admin journey. Must alert the Safety Admin queue
        // immediately, not just create a standard-priority dispute.
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO disputes (booking_id, raised_by, category, description, status, created_at)
             VALUES (:booking_id, :raised_by, "safety_incident", "SOS triggered", "open", NOW())'
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
        // TODO: proximity query against rider_availability using the
        // shared geolocation service — see shared-architecture.md.
        Response::json(['riders' => []]);
    }
}
