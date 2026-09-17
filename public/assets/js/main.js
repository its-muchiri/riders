/**
 * Entry point — wires up whichever shared components a given page needs.
 */
import { onReducedMotionChange } from "./components/reduced-motion.js";

onReducedMotionChange((reduced) => {
  document.documentElement.dataset.reducedMotion = String(reduced);
});
