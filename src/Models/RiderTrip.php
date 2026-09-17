<?php

namespace Rider\Models;

use Rider\Config\Database;

final class RiderTrip
{
    public static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM rider_trips WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public static function forCustomer(int $customerId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM rider_trips WHERE customer_id = :customer_id ORDER BY created_at DESC'
        );
        $stmt->execute(['customer_id' => $customerId]);

        return $stmt->fetchAll();
    }
}
