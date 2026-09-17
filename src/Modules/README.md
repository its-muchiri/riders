# Shared Modules — Integration Point

Placeholder directory. Per `planning/00-portfolio/shared-architecture.md` and `build-sequencing-roadmap.md`, five backend modules are meant to be built once (on laundry.co.ke first) and reused across all five platforms — Booking/Availability Engine, Escrow & Commission Engine, KYC/Verification Pipeline, Programmatic SEO Page Generator, Review & Dispute Resolution Console.

rider.co.ke is the **second** platform in the build sequence specifically because it forces the real-time dispatch and live-geolocation extensions of the Booking Engine to be built while the shared core is still young — see `build-sequencing-roadmap.md`. Once those extensions exist as a shared module, `src/Controllers/TripController.php`'s inline `TODO` matching/dispatch logic should delegate here instead.
