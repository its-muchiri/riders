# MVP Status — Riders (rider.co.ke)

STATE: IN_PROGRESS

## Planning references
- Product spec: ../planning/02-rider-co-ke/prd.md
- Data model: ../planning/02-rider-co-ke/database-schema.md
- API contract: ../planning/02-rider-co-ke/api-endpoints.md
- User flows: ../planning/02-rider-co-ke/user-flows.md
- Open questions (stakeholder-pending decisions): ../planning/02-rider-co-ke/open-questions.md
- Shared modules (auth, payments/escrow, booking engine, KYC, reviews): ../planning/00-portfolio/shared-architecture.md
- Authoritative MVP feature cut & build order: ../planning/00-portfolio/build-sequencing-roadmap.md

## What "MVP complete" means for this project
Per build-sequencing-roadmap.md, rider.co.ke is second in the build order — it reuses the booking engine from laundry.co.ke but forces real-time dispatch and live-geolocation extensions to be built early. Its MVP is: real-time ride/delivery request, live GPS matching and tracking, M-Pesa payment, and rider onboarding with KYC Tier 1/2 — built on shared modules 1–5 (identity/auth, payments core, booking engine v1, escrow & commission engine v1, KYC pipeline v1). Rider subscription, in-app chat, scheduled bookings, and loyalty points are V2/V3 — don't block DONE on them even though controllers for some already exist.

- Customer can sign up / log in
- Customer can request a trip or delivery by setting pickup and destination points
- System dispatches the request to nearby available riders in proximity order, who can accept within a short window
- Customer sees the rider's live GPS location and ETA once a trip starts (feeds a `trip_pings`-style record per database-schema.md)
- System shows an upfront transparent price estimate before confirmation (distance/time, plus surge if configured)
- Customer pays via M-Pesa STK Push; rider is paid out via escrow, per shared-architecture.md's Escrow & Commission Engine
- An SOS/safety button is visible to both customer and rider and alerts the Dispatcher/Safety Admin with live trip location
- Rider completes onboarding with at least ID + phone verification (KYC Tier 1/2, per shared-architecture.md's trust-tiering table)
- Customer can buy rider safety gear/consumables from the store
- Every image slot (homepage hero, rider profile photos, store product photos for gear/consumables) shows a real, topically relevant photo sourced from Unsplash — not a placeholder box or broken image
- App builds and runs with zero errors, works on mobile width
- Core flow (request → match → live track → pay → review) covered by a smoke test

## Checklist
Controllers already exist for most of this (src/Controllers/*) — verify against the spec above and the planning docs rather than assuming they're complete, and rather than rebuilding from scratch.

- [x] Identity/auth + rider onboarding with KYC Tier 1/2 (verify OnboardingController.php against shared-architecture.md's trust tiers)
- [ ] Real-time trip/delivery request + proximity-based rider matching (verify TripController.php, RiderController.php, Models/RiderTrip.php against database-schema.md)
- [ ] Live GPS trip tracking with ETA updates (verify trip-status.php view and the ping/update mechanism against shared-architecture.md's SSE-based real-time strategy)
- [ ] Upfront transparent price estimate (distance/time, optional surge)
- [ ] M-Pesa payment + rider payout via escrow (verify PaymentController.php)
- [ ] SOS/safety incident reporting to Dispatcher/Safety Admin (verify sos.php view is wired to a real alert path, and DisputeController.php has a safety-incident category) — this is prd.md's most severe stated risk for this platform
- [ ] Store: rider safety gear & consumables (verify StoreController.php)
- [ ] Real Unsplash photography (verified resolving URLs) for hero imagery on home.php, rider-profile.php avatars/vehicle photos, and store product photos — motorcycle/delivery subject matter, no placeholders or broken images
- [ ] Error handling (no riders available, GPS/location failure, payment failure)
- [ ] Smoke test / manual run-through of the full core flow passes
- [ ] Remove stubs, TODOs, placeholder data
- [ ] Cross-check against open-questions.md — where it conflicts with an assumption made here, note the assumption taken and continue (don't stop to ask)

Not MVP per the roadmap — don't block DONE on these even if partially built (SubscriptionController.php, LoyaltyController.php, SavedPlacesController.php already exist): rider subscription/lower-commission tier, in-app chat, scheduled (non-immediate) rides, loyalty points, parcel proof-of-delivery photo capture.

## Known issues / open questions
- Physical safety and real-time GPS fraud (fake location, route manipulation) are prd.md's stated core risks — the SOS button and the trip location record are the primary mitigations; treat any gap in either as high priority, not a nice-to-have.
- Check open-questions.md for unresolved items (e.g., pre-authorization vs. pay-on-completion) — pick a reasonable default, log it here, and continue.
- Rider onboarding's KYC document fields (src/Views/rider-onboard.php) collect a document reference/URL per document rather than a real file upload — there is no file-storage backend (e.g. Vercel Blob) wired into this project yet. Assumption taken: acceptable for MVP since kyc_documents.file_reference is just a string column and the admin-review step (not yet built) would need a real document viewer regardless; flag for a follow-up pass to wire actual file upload + storage before this goes to real users.
- No admin console exists yet to approve/reject KYC submissions (OnboardingController.submit only creates pending_verification records) — a rider can complete onboarding but has no path to reach status=active. This blocks the full supply-side journey (user-flows.md §2 step 3) and should be the next KYC-adjacent task.
- TripController's dispatch/matching, SSE trip_pings streaming, and nearbyRiders proximity query are still stubbed (see inline TODOs) — tracked under checklist item 2, not item 1.

## Changelog
- 2026-09-17: Initial checklist created (assumed generic scope, not sourced from planning/)
- 2026-09-18: Rewritten against planning/02-rider-co-ke and shared-architecture.md; checklist now reflects the roadmap's authoritative MVP cut and points at existing controllers to verify rather than assuming a blank slate
- 2026-09-17: Implemented Identity/auth (checklist item 1): added src/Core/Auth.php (session-cookie auth, ported from laundry.co.ke's reference implementation) and src/Controllers/AuthController.php (signup/login/logout, both server-rendered forms and /api/v1/auth/* JSON); wired Auth::start()/currentUser() into public/index.php so `$request->user` is finally populated (every controller already assumed this and had no way to get it — the app was fully unauthenticated end-to-end before this pass); added form-urlencoded body parsing to Request::fromGlobals (previously JSON-only, which silently broke native HTML form posts); added signup.php/login.php views and a rider-onboard.php page (KYC document + vehicle submission form posting to the existing OnboardingController API) with a /riders/onboard route; added Auth::requireUser guards to OnboardingController::submit and TripController::create/accept so customer_id/rider_id can no longer resolve to null; updated layout.php nav to show login state. Fixed a route-ordering bug discovered during smoke testing: /riders/{id} was registered before /riders/onboard and silently swallowed it (Router matches in registration order) — reordered. Smoke-tested end-to-end against a throwaway local MariaDB (Docker) instance: customer signup → session → authenticated trip creation (201) → unauthenticated trip creation (401) → logout → rider signup (national_id required) → /riders/onboard renders → KYC submission (201, status=pending_verification) → rider profile page renders. Restored .env to its committed defaults and tore down the Docker container afterward.
