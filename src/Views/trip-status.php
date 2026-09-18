<?php
/** @var array|null $trip */
/** @var int $tripId */
/** @var string|null $dbError */
use Rider\Core\Auth;
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

  <div id="live-tracking-container" style="margin-top: var(--ac-space-6); max-width: 40rem;" hidden>
    <h2>Live tracking</h2>
    <div id="live-map" style="width:100%; height:280px; border-radius: 8px; overflow:hidden; border:1px solid #ddd;"></div>
    <p id="live-tracking-note" class="card__meta" style="margin-top: var(--ac-space-2);"></p>
  </div>

  <div id="rider-controls" style="margin-top: var(--ac-space-6);" hidden>
    <button type="button" id="advance-status-btn" class="btn btn--primary"></button>
    <p id="rider-location-note" class="card__meta" style="margin-top: var(--ac-space-2);"></p>
  </div>

  <a href="/trips/<?= $tripId ?>/sos" class="btn btn--secondary" style="margin-top: var(--ac-space-6); border-color: var(--ac-danger); color: var(--ac-danger);">Safety / SOS</a>

  <script type="module">
    import { createStatusTimeline, RIDER_TRIP_STEPS } from "/assets/js/components/status-timeline.js";
    import { createDispatchStatus, subscribeToTripPings } from "/assets/js/components/dispatch-status.js";
    import { createStatusBadge } from "/assets/js/components/status-badge.js";

    const tripId = <?= $tripId ?>;
    const initialStatus = <?= json_encode($trip['status'] ?? 'requested') ?>;
    const currentUserId = <?= json_encode(Auth::id()) ?>;
    const tripRiderId = <?= json_encode($trip['rider_id'] !== null ? (int) $trip['rider_id'] : null) ?>;
    const tripCustomerId = <?= json_encode((int) $trip['customer_id']) ?>;
    const isRider = currentUserId !== null && currentUserId === tripRiderId;

    const STEP_INDEX = { requested: 0, matched: 1, rider_en_route: 2, in_progress: 3, completed: 4 };
    const BADGE_TONE = {
      requested: "neutral", matched: "accent", rider_en_route: "accent",
      in_progress: "warning", completed: "success", cancelled: "danger", disputed: "danger",
    };
    const DISPATCH_STATE = { requested: "searching", matched: "matched", rider_en_route: "en_route", in_progress: "in_progress" };
    const TRACKED_STATUSES = new Set(["matched", "rider_en_route", "in_progress"]);
    const NEXT_STATUS = { matched: "rider_en_route", rider_en_route: "in_progress", in_progress: "completed" };
    const NEXT_LABEL = { rider_en_route: "Start heading to pickup", in_progress: "Start trip (picked up)", completed: "Complete trip" };

    const badgeContainer = document.getElementById("status-badge-container");
    const dispatchContainer = document.getElementById("dispatch-status-container");
    const dispatchNote = document.getElementById("dispatch-note");
    const timelineContainer = document.getElementById("status-timeline-container");
    const liveTrackingContainer = document.getElementById("live-tracking-container");
    const liveMap = document.getElementById("live-map");
    const liveTrackingNote = document.getElementById("live-tracking-note");
    const riderControls = document.getElementById("rider-controls");
    const advanceBtn = document.getElementById("advance-status-btn");
    const riderLocationNote = document.getElementById("rider-location-note");

    // --- Rider side: share live location + advance trip status ---

    let riderWatchId = null;
    let riderPingTimer = null;
    let riderLastCoords = null;

    function sendRiderPing() {
      if (!riderLastCoords) return;
      fetch("/api/v1/riders/me/location-ping", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ trip_id: tripId, lat: riderLastCoords.lat, lng: riderLastCoords.lng }),
      }).catch(() => {});
    }

    function startRiderLocationSharing() {
      if (riderWatchId !== null) return;
      if (!navigator.geolocation) {
        riderLocationNote.textContent = "Geolocation is not available in this browser — the customer won't see live tracking.";
        return;
      }
      riderWatchId = navigator.geolocation.watchPosition(
        (position) => {
          riderLastCoords = { lat: position.coords.latitude, lng: position.coords.longitude };
          riderLocationNote.textContent = `Sharing your live location: ${riderLastCoords.lat.toFixed(5)}, ${riderLastCoords.lng.toFixed(5)}`;
          sendRiderPing();
        },
        () => { riderLocationNote.textContent = "Could not read your location — grant location access so the customer can track you."; },
        { enableHighAccuracy: true }
      );
      riderPingTimer = setInterval(sendRiderPing, 6000);
    }

    function stopRiderLocationSharing() {
      if (riderWatchId !== null) navigator.geolocation.clearWatch(riderWatchId);
      if (riderPingTimer) clearInterval(riderPingTimer);
      riderWatchId = null;
      riderPingTimer = null;
    }

    function updateRiderControls(status) {
      if (!isRider) {
        riderControls.hidden = true;
        return;
      }
      const next = NEXT_STATUS[status];
      if (!next) {
        riderControls.hidden = true;
        stopRiderLocationSharing();
        return;
      }
      riderControls.hidden = false;
      advanceBtn.textContent = NEXT_LABEL[next];
      advanceBtn.dataset.next = next;

      if (TRACKED_STATUSES.has(status)) {
        startRiderLocationSharing();
      } else {
        stopRiderLocationSharing();
      }
    }

    advanceBtn.addEventListener("click", async () => {
      const next = advanceBtn.dataset.next;
      advanceBtn.disabled = true;
      try {
        const res = await fetch(`/api/v1/trips/${tripId}/status`, {
          method: "PATCH",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ status: next }),
        });
        const data = await res.json();
        if (!res.ok) {
          alert(data.error || "Could not update trip status.");
          return;
        }
        lastStatus = data.status;
        render(data.status, null);
      } catch (e) {
        alert("Network error: " + e.message);
      } finally {
        advanceBtn.disabled = false;
      }
    });

    // --- Customer side (and anyone but the assigned rider): live map ---

    let locationPollTimer = null;

    function renderMap(lat, lng) {
      const delta = 0.01;
      const bbox = [lng - delta, lat - delta, lng + delta, lat + delta].join(",");
      liveMap.innerHTML = `<iframe title="Rider location map" width="100%" height="100%" style="border:0;" src="https://www.openstreetmap.org/export/embed.html?bbox=${bbox}&layer=mapnik&marker=${lat},${lng}"></iframe>`;
    }

    async function pollLocation() {
      try {
        const res = await fetch(`/api/v1/trips/${tripId}/location`);
        if (!res.ok) return;
        const data = await res.json();

        if (!data.tracking) {
          liveTrackingContainer.hidden = true;
          return;
        }
        liveTrackingContainer.hidden = false;

        if (!data.has_location) {
          liveMap.innerHTML = "";
          liveTrackingNote.textContent = "Waiting for the rider's location…";
          return;
        }

        renderMap(data.lat, data.lng);
        const targetLabel = data.target === "destination" ? "your destination" : "pickup";
        liveTrackingNote.textContent = data.eta_minutes !== null
          ? `Rider is ~${data.distance_km} km from ${targetLabel} — ETA ~${data.eta_minutes} min.`
          : `Last updated ${new Date(data.recorded_at).toLocaleTimeString()}.`;
      } catch {
        // Network hiccup — retried on the next tick.
      }
    }

    function updateLocationPolling(status) {
      if (isRider) return; // riders share their own location above, not a map of themselves
      if (TRACKED_STATUSES.has(status)) {
        if (!locationPollTimer) {
          pollLocation();
          locationPollTimer = setInterval(pollLocation, 4000);
        }
      } else if (locationPollTimer) {
        clearInterval(locationPollTimer);
        locationPollTimer = null;
        liveTrackingContainer.hidden = true;
      }
    }

    // --- Shared status rendering ---

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

      updateRiderControls(status);
      updateLocationPolling(status);
    }

    render(initialStatus, null);

    // Live updates via SSE (see src/Controllers/TripController.php::pingStream)
    // — this degrades harmlessly if the stream isn't reachable or doesn't
    // flush incrementally in this environment; the polling fallback below
    // is the mechanism actually relied on.
    try {
      const source = subscribeToTripPings(tripId, (data) => {
        if (data && data.status) render(data.status, data.dispatch_status);
      });
      window.addEventListener("beforeunload", () => source.close());
    } catch {
      // EventSource not available or the endpoint isn't reachable in this
      // environment — the static initial render above still stands.
    }

    // Polling fallback for dispatch/match progress: covers environments
    // where SSE doesn't flush incrementally (e.g. a serverless platform
    // that buffers the full response). Stops once the trip reaches a
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

    window.addEventListener("beforeunload", () => {
      clearInterval(pollTimer);
      if (locationPollTimer) clearInterval(locationPollTimer);
      stopRiderLocationSharing();
    });
  </script>
<?php endif; ?>
