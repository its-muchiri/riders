<?php

namespace Rider\Config;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;
    private static ?string $driver = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = getenv('PGHOST') ? self::connectPostgres() : self::connectMysql();
        }

        return self::$connection;
    }

    /**
     * Which PDO driver is active: 'mysql' (local dev, per
     * shared-architecture.md's documented stack) or 'pgsql' (Vercel/Neon —
     * the only realistic option on Vercel's Marketplace, which has no
     * MySQL-compatible database; see
     * planning/00-portfolio/ui-implementation-plan.md). A handful of
     * queries use MySQL-only syntax (ON DUPLICATE KEY UPDATE) and branch
     * on this.
     */
    public static function driver(): string
    {
        self::connection();

        return self::$driver;
    }

    private static function connectMysql(): PDO
    {
        self::$driver = 'mysql';
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'rider_co_ke';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASSWORD') ?: '';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new PDOException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }
    }

    /**
     * Live Vercel deployment path — see database/schema.postgres.sql. Uses
     * the PG_ and POSTGRES_ prefixed env vars Vercel's Neon Marketplace
     * integration injects automatically (`vercel integration add neon`).
     */
    private static function connectPostgres(): PDO
    {
        self::$driver = 'pgsql';
        $host = getenv('PGHOST');
        $name = getenv('PGDATABASE');
        $user = getenv('PGUSER');
        $pass = getenv('PGPASSWORD');

        $dsn = "pgsql:host={$host};port=5432;dbname={$name};sslmode=require";

        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new PDOException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }
    }
}
