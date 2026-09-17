/**
 * Fare payment / trip-completion page logic.
 *
 * ENFORCED RULE (per artcollect-design-system.md §7 and this platform's
 * README): this file, and the SOS/safety-incident surface, must NEVER
 * import components/scrap.js or any other decorative module. Only plain,
 * calm UI components are permitted here.
 */
import { createStatusTimeline, RIDER_TRIP_STEPS } from "../components/status-timeline.js";

// import { createTornEdge } from "../components/scrap.js"; // <- NEVER do this here.

export function renderTripStatus(container, currentIndex) {
  container.classList.add("critical-flow");
  container.appendChild(createStatusTimeline({ steps: RIDER_TRIP_STEPS, currentIndex }));
}
