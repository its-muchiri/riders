# Tests

No test runner is wired up yet. Recommended: PHPUnit for `src/`, a small assertion runner (or Vitest) for `public/assets/js/`.

Priority areas, mirroring artcollect.co.ke's own verification checklist:

- Dispatch matching/cascade logic once implemented (offer timeout, next-nearest fallback)
- M-Pesa callback idempotency (`payment_callbacks_log` uniqueness on `checkout_request_id`)
- `trip_pings` retention/archival job (see open-questions.md #8) — verify old pings are actually purged/archived on schedule
- SOS escalation path — verify it always creates a `safety_incident` dispute and never silently fails

## What exists

- `payments_smoke.php` — end-to-end payment/escrow/payout leg (STK Push, callbacks, STK-query reconcile, commission ledger, B2C payouts and their failure paths) against a live app. Needs a throwaway MySQL/MariaDB loaded from `database/schema.sql`, the app running with `MPESA_BASE_URL` pointed at `support/mock_daraja.php`, and `MPESA_CALLBACK_SECRET=tok123` — see the header comment in the file for the exact commands.
- `support/mock_daraja.php` — local Daraja stand-in (OAuth, STK Push/Query, B2C) with test controls for answering prompts and forcing B2C failures.
