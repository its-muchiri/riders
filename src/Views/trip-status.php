<?php
/** @var array|null $trip */
/** @var int $tripId */
/** @var string|null $dbError */
use Rider\Core\View;
?>
<h1>Trip #<?= $tripId ?></h1>

<?php if ($dbError): ?>
  <p class="card__meta"><?= View::e($dbError) ?></p>
<?php elseif (!$trip): ?>
  <p class="card__meta">No trip found with this ID.</p>
<?php else: ?>
  <p class="card__meta">
    <?= View::e($trip['pickup_address'] ?? 'Pickup address not set') ?> → <?= View::e($trip['destination_address'] ?? 'Destination not set') ?>
  </p>

  <div id="status-badge-container" style="margin-top: var(--ac-space-3);"></div>
  <div id="dispatch-status-container" style="max-width: 32rem; margin-top: var(--ac-space-4);"></div>
  <p id="dispatch-note" class="card__meta" style="margin-top: var(--ac-space-2);"></p>
  <div id="status-timeline-container" style="max-width: 40rem; margin-top: var(--ac-space-4);"></div>

  <a href="/trips/<?= $tripId ?>/sos" class="btn btn--secondary" style="margin-top: var(--ac-space-6); border-color: var(--ac-danger); color: var(--ac-danger);">Safety / SOS</a>

  <script type="module">
    import { createStatusTimeline, RIDER_TRIP_STEPS } from "/assets/js/components/status-timeline.js";
    import { createDispatchStatus, subscribeToTripPings } from "/assets/js/components/dispatch-status.js";
    import { createStatusBadge } from "/assets/js/components/status-badge.js";

    const tripId = <?= $tripId ?>;
    const initialStatus = <?= json_encode($trip['status'] ?? 'requested') ?>;

    const STEP_INDEX = { requested: 0, matched: 1, rider_en_route: 2, in_progress: 3, completed: 4 };
    const BADGE_TONE = {
      requested: "neutral", matched: "accent", rider_en_route: "accent",
      in_progress: "warning", completed: "success", cancelled: "danger", disputed: "danger",
    };
    const DISPATCH_STATE = { requested: "searching", matched: "matched", rider_en_route: "en_route", in_progress: "in_progress" };

    const badgeContainer = document.getElementById("status-badge-container");
    const dispatchContainer = document.getElementById("dispatch-status-container");
    const dispatchNote = document.getElementById("dispatch-note");
    const timelineContainer = document.getElementById("status-timeline-container");

    function render(status, dispatchStatus) {
      badgeContainer.innerHTML = "";
      badgeContainer.appendChild(createStatusBadge({ label: status.replace(/_/g, " "), tone: BADGE_TONE[status] ?? "neutral" }));

      dispatchContainer.innerHTML = "";
      if (DISPATCH_STATE[status]) {
        dispatchContainer.appendChild(createDispatchStatus({ state: DISPATCH_STATE[status], label: "Status: " + status.replace(/_/g, " ") }));
      }

      dispatchNote.textContent = status === "requested" && dispatchStatus === "no_riders_available"
        ? "No riders are available near you right now — we'll keep looking."
        : "";

      timelineContainer.innerHTML = "";
      const index = STEP_INDEX[status] ?? 0;
      timelineContainer.appendChild(createStatusTimeline({ steps: RIDER_TRIP_STEPS, currentIndex: index }));
    }

    render(initialStatus, null);

    // Live updates via SSE once routes/api.php's ping-stream endpoint is
    // implemented (see src/Controllers/TripController.php::pingStream,
    // currently a TODO stub) — this degrades harmlessly if the stream
    // never sends a real status change.
    try {
      const source = subscribeToTripPings(tripId, (data) => {
        if (data && data.status) render(data.status, data.dispatch_status);
      });
      window.addEventListener("beforeunload", () => source.close());
    } catch {
      // EventSource not available or the endpoint isn't reachable in this
      // environment — the static initial render above still stands.
    }

    // Polling fallback for dispatch/match progress: the SSE ping-stream
    // above is still a stub (tracked separately, MVP_STATUS.md checklist
    // item 3), so without this the customer would never see "requested"
    // become "matched" once a rider accepts. Stops once the trip reaches a
    // terminal state.
    const TERMINAL = new Set(["completed", "cancelled", "disputed"]);
    let lastStatus = initialStatus;
    const pollTimer = setInterval(async () => {
      if (TERMINAL.has(lastStatus)) {
        clearInterval(pollTimer);
        return;
      }
      try {
        const res = await fetch(`/api/v1/trips/${tripId}`);
        if (!res.ok) return;
        const data = await res.json();
        lastStatus = data.status;
        render(data.status, data.dispatch_status);
      } catch {
        // Network hiccup — try again on the next tick.
      }
    }, 3000);
    window.addEventListener("beforeunload", () => clearInterval(pollTimer));
  </script>
<?php endif; ?>
