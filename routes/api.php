<?php

/**
 * Route table for rider.co.ke. Mirrors planning/02-rider-co-ke/api-endpoints.md.
 * Only the core resource groups are wired here.
 *
 * @var \Rider\Core\Router $router
 */

use Rider\Controllers\AuthController;
use Rider\Controllers\DisputeController;
use Rider\Controllers\GeocodeController;
use Rider\Controllers\LoyaltyController;
use Rider\Controllers\OnboardingController;
use Rider\Controllers\PaymentController;
use Rider\Controllers\ReviewController;
use Rider\Controllers\RiderController;
use Rider\Controllers\SavedPlacesController;
use Rider\Controllers\StoreController;
use Rider\Controllers\SubscriptionController;
use Rider\Controllers\TripController;

$trip = new TripController();
$payment = new PaymentController();
$review = new ReviewController();
$dispute = new DisputeController();
$store = new StoreController();
$onboarding = new OnboardingController();
$rider = new RiderController();
$subscription = new SubscriptionController();
$loyalty = new LoyaltyController();
$savedPlaces = new SavedPlacesController();
$auth = new AuthController();
$geocode = new GeocodeController();

// Auth (shared identity/auth module — see MVP_STATUS.md checklist item 1)
$router->post('/api/v1/auth/register', [$auth, 'register']);
$router->post('/api/v1/auth/login', [$auth, 'login']);
$router->post('/api/v1/auth/logout', [$auth, 'logout']);
$router->get('/api/v1/auth/me', [$auth, 'me']);

// Trips / Deliveries
$router->post('/api/v1/trips', [$trip, 'create']);
// Must be registered before the /trips/{id} wildcard below, same pattern
// as /riders/me/offers vs /riders/{id} (Router matches in registration order).
$router->get('/api/v1/trips/estimate-fare', [$trip, 'estimateFare']);
$router->get('/api/v1/trips/{id}', [$trip, 'show']);
$router->get('/api/v1/trips/{id}/location', [$trip, 'location']);
$router->get('/api/v1/trips/{id}/ping-stream', [$trip, 'pingStream']);
$router->get('/api/v1/geocode', [$geocode, 'search']);
$router->patch('/api/v1/trips/{id}/accept', [$trip, 'accept']);
$router->patch('/api/v1/trips/{id}/decline', [$trip, 'decline']);
$router->patch('/api/v1/trips/{id}/status', [$trip, 'updateStatus']);
$router->post('/api/v1/trips/{id}/cancel', [$trip, 'cancel']);
$router->post('/api/v1/trips/{id}/sos', [$trip, 'sos']);
$router->post('/api/v1/trips/{id}/proof-of-delivery', [$trip, 'proofOfDelivery']);

// Availability
$router->get('/api/v1/riders/nearby', [$trip, 'nearbyRiders']);
$router->patch('/api/v1/riders/me/availability', [$rider, 'setAvailability']);
$router->post('/api/v1/riders/me/location-ping', [$rider, 'locationPing']);
// /riders/me/offers must be registered before the /riders/{id} wildcard
// below, for the same route-ordering reason documented in routes/web.php.
$router->get('/api/v1/riders/me/offers', [$rider, 'myOffers']);
$router->get('/api/v1/riders/{id}', [$rider, 'profile']);

// Payments
$router->post('/api/v1/payments/mpesa/stk-push', [$payment, 'stkPush']);
$router->post('/api/v1/payments/mpesa/callback', [$payment, 'mpesaCallback']);
$router->post('/api/v1/payments/card', [$payment, 'card']);
$router->get('/api/v1/riders/me/earnings', [$payment, 'myEarnings']);

// Reviews
$router->post('/api/v1/trips/{id}/review', [$review, 'store']);
$router->get('/api/v1/riders/{id}/reviews', [$review, 'forRider']);

// Disputes / Safety Incidents
$router->post('/api/v1/trips/{id}/disputes', [$dispute, 'store']);
$router->get('/api/v1/disputes', [$dispute, 'index']);
$router->patch('/api/v1/disputes/{id}/resolve', [$dispute, 'resolve']);

// Rider onboarding (KYC + vehicle documents)
$router->post('/api/v1/riders/onboard', [$onboarding, 'submit']);

// Store
$router->get('/api/v1/store/products', [$store, 'listProducts']);
$router->post('/api/v1/store/orders', [$store, 'createOrder']);

// Rider subscriptions (V2 retention — see build-sequencing-roadmap.md)
$router->post('/api/v1/riders/subscriptions', [$subscription, 'subscribe']);
$router->get('/api/v1/riders/me/subscription', [$subscription, 'myStatus']);

// Loyalty (V2 retention)
$router->get('/api/v1/loyalty/me', [$loyalty, 'balance']);
$router->post('/api/v1/loyalty/redeem', [$loyalty, 'redeem']);

// Saved places / preferred rider (V2 retention)
$router->get('/api/v1/saved-places', [$savedPlaces, 'index']);
$router->post('/api/v1/saved-places', [$savedPlaces, 'create']);
$router->post('/api/v1/riders/{id}/prefer', [$savedPlaces, 'preferRider']);
