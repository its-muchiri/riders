<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Request;
use Rider\Core\Response;

/**
 * Saved addresses and preferred riders — V2 retention mechanics (see
 * planning/02-rider-co-ke/prd.md).
 */
final class SavedPlacesController
{
    public function index(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare('SELECT * FROM saved_places WHERE customer_id = :customer_id');
        $stmt->execute(['customer_id' => $request->user['id'] ?? null]);

        Response::json($stmt->fetchAll());
    }

    public function create(Request $request): void
    {
        $db = Database::connection();
        $stmt = $db->prepare(
            'INSERT INTO saved_places (customer_id, label, lat, lng) VALUES (:customer_id, :label, :lat, :lng)'
        );
        $stmt->execute([
            'customer_id' => $request->user['id'] ?? null,
            'label' => $request->input('label'),
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
        ]);

        Response::json(['id' => (int) $db->lastInsertId()], 201);
    }

    public function preferRider(Request $request): void
    {
        $db = Database::connection();
        // See Database::driver() and planning/00-portfolio/ui-implementation-plan.md
        // for why this branches (Vercel's Marketplace has no MySQL-compatible database).
        $sql = Database::driver() === 'pgsql'
            ? 'INSERT INTO preferred_riders (customer_id, rider_id, created_at) VALUES (:customer_id, :rider_id, NOW())
               ON CONFLICT (customer_id, rider_id) DO NOTHING'
            : 'INSERT INTO preferred_riders (customer_id, rider_id, created_at) VALUES (:customer_id, :rider_id, NOW())
               ON DUPLICATE KEY UPDATE created_at = created_at';
        $stmt = $db->prepare($sql);
        $stmt->execute(['customer_id' => $request->user['id'] ?? null, 'rider_id' => $request->params['id']]);

        Response::json(['status' => 'preferred']);
    }
}
