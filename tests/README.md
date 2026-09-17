# Tests

No test runner is wired up yet. Recommended: PHPUnit for `src/`, a small assertion runner (or Vitest) for `public/assets/js/`.

Priority areas, mirroring artcollect.co.ke's own verification checklist:

- Dispatch matching/cascade logic once implemented (offer timeout, next-nearest fallback)
- M-Pesa callback idempotency (`payment_callbacks_log` uniqueness on `checkout_request_id`)
- `trip_pings` retention/archival job (see open-questions.md #8) — verify old pings are actually purged/archived on schedule
- SOS escalation path — verify it always creates a `safety_incident` dispute and never silently fails
