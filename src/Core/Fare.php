<?php

namespace Rider\Core;

/**
 * Upfront transparent pricing (MVP_STATUS.md checklist item 4 /
 * prd.md "Upfront Transparent Pricing"). No stakeholder-approved rate
 * card exists yet (open-questions.md doesn't cover base rates at all,
 * only the commission/surge-cap questions below) — the rates here are
 * an assumption modeled on informal Nairobi boda-boda/taxi pricing,
 * logged in MVP_STATUS.md for a follow-up pass once real rates are
 * provided.
 */
final class Fare
{
    // Same flat-speed assumption TripController::location uses for live
    // ETA — kept as one constant so the pre-trip estimate and the
    // in-trip ETA never silently disagree. No directions/traffic API is
    // wired in (MAPS_PROVIDER_API_KEY unconfigured).
    public const ASSUMED_SPEED_KMH = 25.0;

    // KES. [base, per_km, per_min, minimum]
    private const RATE_CARD = [
        'passenger_motorcycle' => ['base' => 50.0, 'per_km' => 15.0, 'per_min' => 3.0, 'minimum' => 100.0],
        'passenger_car' => ['base' => 100.0, 'per_km' => 40.0, 'per_min' => 5.0, 'minimum' => 250.0],
        'parcel_delivery' => ['base' => 60.0, 'per_km' => 20.0, 'per_min' => 2.0, 'minimum' => 100.0],
    ];

    // open-questions.md #7 asks for the surge cap and how it's
    // communicated — no stakeholder answer exists yet. Assumption taken:
    // a simple weekday peak-hours multiplier (7-9am, 5-8pm EAT), capped
    // at 1.5x, always disclosed as a line item in the estimate breakdown
    // returned below (never silently folded into the total).
    private const SURGE_CAP = 1.5;
    private const PEAK_MULTIPLIER = 1.3;
    private const PEAK_HOURS = [[7, 9], [17, 20]];

    public static function isValidTripType(string $tripType): bool
    {
        return isset(self::RATE_CARD[$tripType]);
    }

    public static function currentSurgeMultiplier(?\DateTimeImmutable $now = null): float
    {
        $now ??= new \DateTimeImmutable('now');
        $hour = (int) $now->format('G');
        $isWeekday = (int) $now->format('N') <= 5;

        if (!$isWeekday) {
            return 1.0;
        }

        foreach (self::PEAK_HOURS as [$start, $end]) {
            if ($hour >= $start && $hour < $end) {
                return min(self::PEAK_MULTIPLIER, self::SURGE_CAP);
            }
        }

        return 1.0;
    }

    /**
     * @return array{distance_km: float, duration_min: float, surge_multiplier: float,
     *     base_fare: float, distance_fare: float, time_fare: float, subtotal: float,
     *     estimated_fare: float, currency: string}
     */
    public static function estimate(
        string $tripType,
        float $pickupLat,
        float $pickupLng,
        float $destinationLat,
        float $destinationLng,
        ?float $surgeMultiplier = null
    ): array {
        $rates = self::RATE_CARD[$tripType] ?? self::RATE_CARD['passenger_motorcycle'];

        $distanceKm = Dispatch::haversineKm($pickupLat, $pickupLng, $destinationLat, $destinationLng);
        $durationMin = ($distanceKm / self::ASSUMED_SPEED_KMH) * 60;

        $surge = $surgeMultiplier ?? self::currentSurgeMultiplier();
        $surge = min(max($surge, 1.0), self::SURGE_CAP);

        $baseFare = $rates['base'];
        $distanceFare = $distanceKm * $rates['per_km'];
        $timeFare = $durationMin * $rates['per_min'];
        $subtotal = $baseFare + $distanceFare + $timeFare;
        $estimatedFare = max($subtotal * $surge, $rates['minimum']);

        return [
            'distance_km' => round($distanceKm, 2),
            'duration_min' => round($durationMin, 1),
            'surge_multiplier' => $surge,
            'base_fare' => round($baseFare, 2),
            'distance_fare' => round($distanceFare, 2),
            'time_fare' => round($timeFare, 2),
            'subtotal' => round($subtotal, 2),
            'estimated_fare' => round($estimatedFare, 2),
            'currency' => 'KES',
        ];
    }
}
