<?php

namespace Rider\Controllers;

use PDOException;
use Rider\Config\Database;
use Rider\Core\Auth;
use Rider\Core\Request;
use Rider\Core\Response;
use Rider\Core\View;
use Throwable;

/**
 * Signup/login/logout — server-rendered forms for the web routes (see
 * routes/web.php) plus the same actions exposed as JSON under
 * /api/v1/auth/* (routes/api.php) for the request/track pages' fetch()
 * calls. This is this platform's implementation of the shared
 * identity/auth module (see MVP_STATUS.md checklist item 1).
 */
final class AuthController
{
    public function showSignup(Request $request): void
    {
        if (Auth::id() !== null) {
            self::redirect('/');
            return;
        }
        View::render('signup', ['title' => 'Sign up', 'error' => null, 'old' => []]);
    }

    public function showLogin(Request $request): void
    {
        if (Auth::id() !== null) {
            self::redirect('/');
            return;
        }
        View::render('login', ['title' => 'Log in', 'error' => null]);
    }

    public function register(Request $request): void
    {
        $fullName = trim((string) $request->input('full_name', ''));
        $phone = trim((string) $request->input('phone_number', ''));
        $email = trim((string) $request->input('email', '')) ?: null;
        $password = (string) $request->input('password', '');
        $nationalId = trim((string) $request->input('national_id_number', '')) ?: null;
        $accountType = $request->input('account_type', 'customer');
        $accountType = in_array($accountType, ['customer', 'provider'], true) ? $accountType : 'customer';
        $old = [
            'full_name' => $fullName,
            'phone_number' => $phone,
            'email' => $email,
            'national_id_number' => $nationalId,
            'account_type' => $accountType,
        ];

        if ($fullName === '') {
            $this->fail($request, 'signup', 'Full name is required.', $old);
            return;
        }
        if (!preg_match('/^\+?[0-9]{9,15}$/', $phone)) {
            $this->fail($request, 'signup', 'Enter a valid phone number, e.g. 0712345678.', $old);
            return;
        }
        if (strlen($password) < 8) {
            $this->fail($request, 'signup', 'Password must be at least 8 characters.', $old);
            return;
        }
        // Riders need at least a national ID number at signup — Tier 1 KYC
        // per shared-architecture.md's trust-tiering table ("phone number +
        // national ID number, unverified against registry"); the driving
        // license/insurance proof (this platform's raised bar — see
        // OnboardingController) is collected as a follow-up onboarding step
        // before the rider can go online, not at signup time.
        if ($accountType === 'provider' && $nationalId === null) {
            $this->fail($request, 'signup', 'Riders must provide a national ID number.', $old);
            return;
        }

        try {
            $db = Database::connection();

            // Customers are Tier 1 trust and can request a trip immediately.
            // Riders stay pending_verification until KYC + vehicle documents
            // are approved (see OnboardingController), per this platform's
            // Supply-Side Journey step 3 in user-flows.md.
            $status = $accountType === 'customer' ? 'active' : 'pending_verification';

            $stmt = $db->prepare(
                'INSERT INTO users (phone_number, email, password_hash, full_name, national_id_number, account_type, status, created_at, updated_at)
                 VALUES (:phone, :email, :hash, :name, :national_id, :type, :status, NOW(), NOW())'
            );
            $stmt->execute([
                'phone' => $phone,
                'email' => $email,
                'hash' => password_hash($password, PASSWORD_DEFAULT),
                'name' => $fullName,
                'national_id' => $nationalId,
                'type' => $accountType,
                'status' => $status,
            ]);
            $userId = (int) $db->lastInsertId();
            Auth::login($userId);
        } catch (PDOException $e) {
            if ((int) $e->getCode() === 23000 || str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'duplicate')) {
                $this->fail($request, 'signup', 'An account with that phone number already exists.', $old);
                return;
            }
            error_log((string) $e);
            $this->fail($request, 'signup', 'Signup is unavailable right now — no database is connected in this environment.', $old);
            return;
        }

        if ($this->wantsJson($request)) {
            Response::json(['id' => $userId, 'account_type' => $accountType, 'status' => $status], 201);
            return;
        }

        self::redirect($accountType === 'provider' ? '/riders/onboard' : '/request');
    }

    public function login(Request $request): void
    {
        $identifier = trim((string) $request->input('phone_number', ''));
        $password = (string) $request->input('password', '');

        try {
            $user = Auth::attempt($identifier, $password);
        } catch (Throwable $e) {
            error_log((string) $e);
            $this->fail($request, 'login', 'Login is unavailable right now — no database is connected in this environment.');
            return;
        }

        if (!$user) {
            $this->fail($request, 'login', 'Incorrect phone number or password.');
            return;
        }

        if ($this->wantsJson($request)) {
            Response::json(['id' => (int) $user['id'], 'account_type' => $user['account_type']]);
            return;
        }

        self::redirect('/');
    }

    public function logout(Request $request): void
    {
        Auth::logout();
        if ($this->wantsJson($request)) {
            Response::json(['status' => 'logged_out']);
            return;
        }
        self::redirect('/');
    }

    public function me(Request $request): void
    {
        $user = Auth::requireUser($request);
        if (!$user) {
            return;
        }
        unset($user['password_hash']);
        Response::json($user);
    }

    private function fail(Request $request, string $view, string $error, array $old = []): void
    {
        if ($this->wantsJson($request)) {
            Response::error($error, 422);
            return;
        }
        View::render($view, ['title' => $view === 'signup' ? 'Sign up' : 'Log in', 'error' => $error, 'old' => $old]);
    }

    private function wantsJson(Request $request): bool
    {
        return str_starts_with($request->path, '/api/');
    }

    private static function redirect(string $to): void
    {
        header('Location: ' . $to, true, 303);
    }
}
