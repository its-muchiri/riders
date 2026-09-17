<?php

namespace Rider\Core;

use Rider\Config\Database;
use Throwable;

/**
 * Session-cookie auth — this platform's implementation of the shared
 * identity/auth module referenced by MVP_STATUS.md (shared module 1).
 * No JWT/API-token layer: per shared-architecture.md's frontend stack
 * decision (server-rendered PHP, no SPA framework), a PHP session cookie
 * is sufficient and is sent automatically by same-origin fetch() calls.
 * Ported from laundry.co.ke's reference implementation.
 */
final class Auth
{
    private const SESSION_KEY = 'user_id';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    /**
     * Verifies credentials and, on success, starts the session. Returns the
     * user row (including password_hash — callers must strip it before
     * sending to a client) or null on bad credentials/suspended account.
     */
    public static function attempt(string $identifier, string $password): ?array
    {
        $db = Database::connection();
        // Real (non-emulated) prepared statements reject reusing one named
        // placeholder twice in a query — see Database::connection()'s
        // PDO::ATTR_EMULATE_PREPARES => false — so bind :phone and :email
        // separately even though both carry the same value.
        $stmt = $db->prepare('SELECT * FROM users WHERE phone_number = :phone OR email = :email LIMIT 1');
        $stmt->execute(['phone' => $identifier, 'email' => $identifier]);
        $user = $stmt->fetch();

        if (!$user || !is_string($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            return null;
        }

        if (in_array($user['status'], ['banned', 'suspended'], true)) {
            return null;
        }

        self::login((int) $user['id']);
        return $user;
    }

    public static function login(int $userId): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = $userId;
    }

    public static function logout(): void
    {
        self::start();
        unset($_SESSION[self::SESSION_KEY]);
        session_regenerate_id(true);
    }

    public static function id(): ?int
    {
        self::start();
        return isset($_SESSION[self::SESSION_KEY]) ? (int) $_SESSION[self::SESSION_KEY] : null;
    }

    /**
     * Loads the current session's user row, or null if unauthenticated or
     * the database is unavailable. Fails soft (logs + returns null) rather
     * than throwing, consistent with PageController's DB-unavailable
     * handling, so an unconfigured environment degrades to "logged out"
     * instead of a hard 500 on every request.
     */
    public static function currentUser(): ?array
    {
        $id = self::id();
        if ($id === null) {
            return null;
        }

        try {
            $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $user = $stmt->fetch();
            return $user ?: null;
        } catch (Throwable $e) {
            error_log((string) $e);
            return null;
        }
    }

    /**
     * Guard for controller actions that require a logged-in user. Writes a
     * 401 response and returns null if unauthenticated, so callers can
     * `$user = Auth::requireUser($request); if (!$user) return;`.
     */
    public static function requireUser(Request $request): ?array
    {
        if (!$request->user) {
            Response::unauthorized('Log in to continue.');
            return null;
        }
        return $request->user;
    }
}
