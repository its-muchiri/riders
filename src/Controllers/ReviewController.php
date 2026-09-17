<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Request;
use Rider\Core\Response;

final class ReviewController
{
    public function store(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO reviews (booking_id, reviewer_id, reviewee_id, rating, comment, created_at)
             VALUES (:booking_id, :reviewer_id, :reviewee_id, :rating, :comment, NOW())'
        );
        $stmt->execute([
            'booking_id' => $request->params['id'],
            'reviewer_id' => $request->user['id'] ?? null,
            'reviewee_id' => $request->input('reviewee_id'),
            'rating' => $request->input('rating'),
            'comment' => $request->input('comment'),
        ]);

        // TODO: recompute rider_performance_tiers.average_rating.
        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    public function forRider(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'SELECT r.* FROM reviews r
             JOIN rider_trips t ON t.id = r.booking_id
             WHERE t.rider_id = :rider_id
             ORDER BY r.created_at DESC'
        );
        $stmt->execute(['rider_id' => $request->params['id']]);

        Response::json($stmt->fetchAll());
    }
}
