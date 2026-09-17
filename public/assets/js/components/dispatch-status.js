/**
 * Dispatch Status — this platform's distinctive UI need in place of the
 * Booking Calendar/Slot Picker used by scheduled-booking platforms (see
 * planning/00-portfolio/design-system.md's note that rider.co.ke is
 * on-demand). Shows live dispatch state, sourced from the trip's SSE
 * ping-stream endpoint (see routes/api.php's /trips/{id}/ping-stream) once
 * implemented — this component only renders whatever state it's given.
 *
 * @param {{ state: "searching" | "matched" | "en_route" | "in_progress", label: string }} props
 * @returns {HTMLElement}
 */
export function createDispatchStatus({ state, label }) {
  const wrapper = document.createElement("div");
  wrapper.className = "dispatch-status";
  wrapper.dataset.state = state;
  wrapper.setAttribute("role", "status");
  wrapper.setAttribute("aria-live", "polite");

  const pulse = document.createElement("span");
  pulse.className = "dispatch-status__pulse";
  pulse.setAttribute("aria-hidden", "true");

  const text = document.createElement("span");
  text.textContent = label;

  wrapper.append(pulse, text);
  return wrapper;
}

/**
 * Opens an SSE connection to a trip's ping stream and calls `onUpdate` for
 * each event. Caller is responsible for closing the returned EventSource.
 * @param {number} tripId
 * @param {(data: unknown) => void} onUpdate
 * @returns {EventSource}
 */
export function subscribeToTripPings(tripId, onUpdate) {
  const source = new EventSource(`/api/v1/trips/${tripId}/ping-stream`);
  source.addEventListener("ping", (event) => onUpdate(JSON.parse(event.data)));
  return source;
}
