# rider.co.ke

Scaffold for the rider.co.ke marketplace platform. See `/planning/02-rider-co-ke/` for the full PRD, user flows, database schema, API spec, and open questions this scaffold implements a starting skeleton of.

## Stack
PHP (no framework, PSR-4 autoloaded) + vanilla JS/CSS + relational SQL (MySQL/MariaDB), per `/planning/00-portfolio/shared-architecture.md`.

## Design system
Tokens in `public/assets/css/tokens.css` are ported from the real artcollect.co.ke system documented in `/planning/00-portfolio/artcollect-design-system.md`, with the **platform accent set to coral** (`--ac-coral` in artcollect's system) — this platform's assigned lane, chosen for its energy/motion association. Only the token architecture, the collage/pixel decorative primitives, and the motion/accessibility governance rules are adopted; the heavier graffiti and 3D/diorama treatments are intentionally **not** ported here.

This platform is **real-time dispatch, not scheduled booking** — it does not use the Booking Calendar/Slot Picker component (see `planning/00-portfolio/design-system.md`'s note that rider.co.ke is on-demand). Its distinctive UI need is a live dispatch/tracking status surface instead — see `public/assets/js/components/dispatch-status.js`.

**Critical-flow rule (enforced, not just documented):** `public/assets/js/pages/checkout.js` (fare payment) must never import `components/scrap.js` or any decorative module. This platform additionally locks decoration out of the **SOS/safety-incident flow** — see `artcollect-design-system.md` §7 — a safety-critical surface deserves the same "zero decoration" treatment as payment.

## Structure

```
public/                 Web root — front controller, static assets
  index.php             Front controller: bootstraps Router, dispatches request
  assets/css/           tokens.css, reset.css, main.css
  assets/js/            main.js, components/, pages/
src/
  Config/               Database connection (PDO)
  Core/                 Router, Request, Response
  Controllers/          TripController, PaymentController, ReviewController, DisputeController
  Models/               Data-access classes
  Modules/              Placeholder for shared-module integration points (Escrow, KYC, Booking Engine)
database/
  schema.sql            Shared core tables + this platform's extension tables (rider_trips, trip_pings, ...)
routes/
  api.php               Route table — mirrors planning/02-rider-co-ke/api-endpoints.md
```

## Getting started

1. Copy `.env.example` to `.env` and fill in database + M-Pesa Daraja + geolocation-provider credentials.
2. Create the database and run `database/schema.sql` against it.
3. Point your web server's document root at `public/`, with all requests rewritten to `public/index.php`.
4. `composer install` if/when shared-module packages are added as dependencies.

## What this scaffold is (and isn't)

This is a **starting skeleton**: the controllers wire up the request/response shape of each endpoint, not the real-time dispatch matching algorithm, SSE ping stream, or M-Pesa signature verification. Every stub references the planning doc section it should eventually implement.

**Implemented in this scaffold:** trips, payments (stub), reviews, disputes/SOS, rider onboarding (KYC + vehicle documents — note this platform extends the shared `kyc_documents.document_type` enum with `driving_license`/`vehicle_logbook`, since the generic taxonomy didn't cover vehicle compliance), and the e-commerce store. **Not yet wired:** rider subscriptions, loyalty points, saved-places, and preferred-rider endpoints from `/planning/02-rider-co-ke/api-endpoints.md`.
