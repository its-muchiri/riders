<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Request;
use Rider\Core\Response;

/**
 * Category taxonomy (planning/02-rider-co-ke/database-schema.md):
 * safety_incident, fare_dispute, route_deviation, lost_item, other.
 * safety_incident is also raised directly via TripController::sos() for
 * in-trip escalation — this endpoint additionally supports post-trip
 * safety reports and the other, lower-urgency categories.
 */
final class DisputeController
{
    public function store(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO disputes (booking_id, raised_by, category, description, evidence_urls, status, created_at)
             VALUES (:booking_id, :raised_by, :category, :description, :evidence_urls, \'open\', NOW())'
        );
        $stmt->execute([
            'booking_id' => $request->params['id'],
            'raised_by' => $request->user['id'] ?? null,
            'category' => $request->input('category'),
            'description' => $request->input('description'),
            'evidence_urls' => json_encode($request->input('evidence_urls', [])),
        ]);

        Response::json(['id' => (int) $db->lastInsertId(), 'status' => 'open'], 201);
    }

    public function index(Request $request): void
    {
        // TODO: prioritize safety_incident category first in this queue —
        // see user-flows.md's Safety Incident admin journey.
        $db = Database::connection();
        $stmt = $db->query(
            'SELECT * FROM disputes WHERE status IN (\'open\', \'under_review\')
             ORDER BY (category = \'safety_incident\') DESC, created_at ASC'
        );

        Response::json($stmt->fetchAll());
    }

    public function resolve(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'UPDATE disputes SET status = :status, resolved_by = :resolved_by, resolution_notes = :notes, resolved_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $request->input('status'),
            'resolved_by' => $request->user['id'] ?? null,
            'notes' => $request->input('resolution_notes'),
            'id' => $request->params['id'],
        ]);

        Response::json(['id' => (int) $request->params['id'], 'status' => $request->input('status')]);
    }
}
