<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Request;
use Rider\Core\View;
use Rider\Models\RiderTrip;
use Throwable;

/**
 * Server-rendered pages for the primary customer journey (see
 * planning/02-rider-co-ke/user-flows.md §1: search → book → pay → track →
 * complete → review) plus the safety-incident (SOS) surface, since that is
 * this platform's most distinctive critical-flow requirement. These are
 * real pages against the live database — not a mockup — but only cover the
 * customer's request/track path; dispatcher/admin consoles are not built
 * here, matching the scope discipline laundry.co.ke's page layer set (see
 * planning/00-portfolio/ui-implementation-plan.md §3).
 *
 * Every DB-backed method below fails soft: this environment (a fresh
 * Vercel deploy with no database wired yet, see
 * planning/00-portfolio/ui-implementation-plan.md §"Live database" answer)
 * has no live connection, so a page must still render a meaningful empty
 * state rather than a fatal error, per artcollect-design-system.md §8's
 * "empty states are required, not an afterthought" rule.
 */
final class PageController
{
    public function home(Request $request): void
    {
        $riders = [];
        $dbError = null;

        try {
            $stmt = Database::connection()->query(
                'SELECT u.id, u.full_name, rpt.average_rating, rpt.tier
                 FROM users u
                 JOIN rider_performance_tiers rpt ON rpt.rider_id = u.id
                 WHERE u.account_type = \'provider\' AND u.status = \'active\'
                 ORDER BY rpt.average_rating DESC
                 LIMIT 6'
            );
            $riders = $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log((string) $e);
            $dbError = 'Live rider data is unavailable in this environment — no database is connected yet.';
        }

        View::render('home', [
            'title' => 'Get a ride or send a delivery — now',
            'riders' => $riders,
            'dbError' => $dbError,
        ]);
    }

    public function requestForm(Request $request): void
    {
        View::render('request', ['title' => 'Request a ride or delivery']);
    }

    public function tripStatus(Request $request): void
    {
        $tripId = (int) $request->params['id'];
        $trip = null;
        $dbError = null;

        try {
            $trip = RiderTrip::find($tripId);
        } catch (Throwable $e) {
            error_log((string) $e);
            $dbError = 'Live trip data is unavailable in this environment — no database is connected yet.';
        }

        View::render('trip-status', [
            'title' => 'Trip #' . $tripId,
            'tripId' => $tripId,
            'trip' => $trip,
            'dbError' => $dbError,
        ]);
    }

    public function sos(Request $request): void
    {
        $tripId = (int) $request->params['id'];
        $trip = null;

        try {
            $trip = RiderTrip::find($tripId);
        } catch (Throwable $e) {
            error_log((string) $e);
        }

        // Critical-flow surface (see artcollect-design-system.md §7 and this
        // platform's public/assets/js/pages/sos.js): the view itself must
        // stay minimal regardless of whether trip data loaded, and the
        // layout must suppress the FAB and apply .critical-flow.
        View::render('sos', [
            'title' => 'Safety — Trip #' . $tripId,
            'tripId' => $tripId,
            'trip' => $trip,
            'critical' => true,
        ]);
    }

    public function riderProfile(Request $request): void
    {
        $riderId = (int) $request->params['id'];
        $rider = null;
        $dbError = null;

        try {
            $db = Database::connection();

            $stmt = $db->prepare('SELECT id, full_name, status FROM users WHERE id = :id AND account_type = \'provider\'');
            $stmt->execute(['id' => $riderId]);
            $rider = $stmt->fetch() ?: null;

            if ($rider) {
                $performanceStmt = $db->prepare('SELECT * FROM rider_performance_tiers WHERE rider_id = :id');
                $performanceStmt->execute(['id' => $riderId]);
                $rider['performance'] = $performanceStmt->fetch() ?: null;

                $vehicleStmt = $db->prepare('SELECT vehicle_type, plate_number, verification_status FROM rider_vehicle_documents WHERE rider_id = :id');
                $vehicleStmt->execute(['id' => $riderId]);
                $rider['vehicle'] = $vehicleStmt->fetch() ?: null;
            }
        } catch (Throwable $e) {
            error_log((string) $e);
            $dbError = 'Live rider data is unavailable in this environment — no database is connected yet.';
        }

        View::render('rider-profile', [
            'title' => $rider ? $rider['full_name'] : 'Rider #' . $riderId,
            'riderId' => $riderId,
            'rider' => $rider,
            'dbError' => $dbError,
        ]);
    }
}
