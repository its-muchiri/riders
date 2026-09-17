/**
 * SOS / safety-incident page logic.
 *
 * ENFORCED RULE: this file must NEVER import components/scrap.js or any
 * other decorative module. A safety-critical surface gets the same
 * zero-decoration treatment as payment — see artcollect-design-system.md §7
 * and this platform's README.
 */
export function triggerSos(tripId) {
  return fetch(`/api/v1/trips/${tripId}/sos`, { method: "POST" }).then((res) => res.json());
}
