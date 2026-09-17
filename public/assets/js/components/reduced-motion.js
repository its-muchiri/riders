/**
 * Shared reduced-motion check — ported from artcollect.co.ke's
 * usePrefersReducedMotion hook (artcollect-design-system.md §4/§7) as a
 * plain function, since this stack has no framework/hook system.
 */
export function prefersReducedMotion() {
  return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
}

/** @param {(reduced: boolean) => void} callback */
export function onReducedMotionChange(callback) {
  const mql = window.matchMedia("(prefers-reduced-motion: reduce)");
  callback(mql.matches);
  mql.addEventListener("change", (event) => callback(event.matches));
}
