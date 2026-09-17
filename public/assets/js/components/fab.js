/**
 * FAB — used by rider.co.ke and laundry.co.ke (high-frequency, low-friction
 * primary actions). See design-system.md's assumption that construction/
 * solar/event use a sticky in-page CTA instead.
 * @param {{ label: string, onClick: () => void }} props
 * @returns {HTMLElement}
 */
export function createFab({ label, onClick }) {
  const button = document.createElement("button");
  button.type = "button";
  button.className = "fab";
  button.textContent = label;
  button.addEventListener("click", onClick);
  return button;
}
