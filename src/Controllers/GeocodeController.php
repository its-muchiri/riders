<?php

namespace Rider\Controllers;

use Rider\Core\Request;
use Rider\Core\Response;

/**
 * Address -> lat/lng lookup, used by request.php to fill in pickup/
 * destination coordinates the customer only typed as free text (no map
 * picker exists in this stack — see shared-architecture.md's Google Maps
 * Platform assumption, which is unconfigured: .env's MAPS_PROVIDER_API_KEY
 * is blank). Proxied server-side through OpenStreetMap's free Nominatim
 * search API rather than called directly from the browser, both to attach
 * Nominatim's required identifying User-Agent (its usage policy rejects
 * anonymous/default User-Agents) and because it does not send CORS headers
 * a page-origin fetch() could rely on.
 *
 * Without real coordinates, TripController::location's distance/ETA math
 * and the eventual fare-estimate work (checklist item 4) would be computed
 * against (0,0) — this closes that gap rather than leaving pickup/
 * destination silently wrong.
 */
final class GeocodeController
{
    public function search(Request $request): void
    {
        $query = trim((string) $request->input('q', ''));
        if ($query === '') {
            Response::error('Missing query parameter "q".', 422);
            return;
        }

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $query,
            'format' => 'jsonv2',
            'limit' => 1,
            'countrycodes' => 'ke',
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            // Nominatim's usage policy requires a real identifying
            // User-Agent; requests without one are dropped.
            CURLOPT_HTTPHEADER => ['User-Agent: rider.co.ke MVP (contact: ndikimuchiri@gmail.com)'],
        ]);
        $raw = curl_exec($ch);
        $ok = $raw !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        if (!$ok) {
            // Fails soft: the caller (request.php) falls back to (0,0) and
            // the trip is still created, just without precise coordinates —
            // matches PageController's "never a hard error on an
            // unavailable external dependency" pattern.
            Response::json(['found' => false]);
            return;
        }

        $results = json_decode($raw, true);
        if (!is_array($results) || empty($results[0]['lat']) || empty($results[0]['lon'])) {
            Response::json(['found' => false]);
            return;
        }

        Response::json([
            'found' => true,
            'lat' => (float) $results[0]['lat'],
            'lng' => (float) $results[0]['lon'],
            'display_name' => $results[0]['display_name'] ?? $query,
        ]);
    }
}
