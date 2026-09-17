/**
 * Status Badge — pixel lane, per artcollect-design-system.md §4/§5: "Sprites
 * defined as small 2D color-grid data ... rendered as SVG rects ... fully
 * code-generated. Governed usage: status badges, stamps ('sold out',
 * 'last few'), loading spinners." This is the first shared component to
 * implement that technique in the marketplace-platform component set (see
 * planning/00-portfolio/ui-implementation-plan.md §5) — every icon below is
 * a plain array of 1s/0s, not an image asset, so new badge icons are
 * authored as data.
 *
 * Semantic tone maps to the same status colors used across all five
 * platforms (--ac-success/--ac-warning/--ac-danger/--ac-accent), so a
 * booking/dispute/KYC status reads the same way regardless of which
 * platform or which component renders it.
 */

const SVG_NS = "http://www.w3.org/2000/svg";

const TONE_COLORS = {
  neutral: "var(--ac-ink)",
  accent: "var(--ac-accent)",
  success: "var(--ac-success)",
  warning: "var(--ac-warning)",
  danger: "var(--ac-danger)",
};

// 5x5 grids — 1 = filled pixel, 0 = empty. Purely decorative alongside the
// always-present text label, so the SVG stays aria-hidden.
const ICONS = {
  dot: [
    [0, 0, 1, 0, 0],
    [0, 1, 1, 1, 0],
    [1, 1, 1, 1, 1],
    [0, 1, 1, 1, 0],
    [0, 0, 1, 0, 0],
  ],
  check: [
    [0, 0, 0, 0, 1],
    [0, 0, 0, 1, 0],
    [1, 0, 1, 0, 0],
    [0, 1, 0, 0, 0],
    [0, 0, 0, 0, 0],
  ],
  alert: [
    [0, 0, 1, 0, 0],
    [0, 0, 1, 0, 0],
    [0, 0, 1, 0, 0],
    [0, 0, 0, 0, 0],
    [0, 0, 1, 0, 0],
  ],
};

/**
 * Renders any 2D 1/0 grid as a small aria-hidden pixel-art SVG. This is the
 * general primitive the status badge below builds on — a future pixel-lane
 * component (a stamp, a spinner) can reuse it with its own grid.
 * @param {number[][]} grid
 * @param {string} color
 * @returns {SVGSVGElement}
 */
export function renderPixelGrid(grid, color) {
  const size = grid.length;
  const svg = document.createElementNS(SVG_NS, "svg");
  svg.setAttribute("viewBox", `0 0 ${size} ${size}`);
  svg.setAttribute("width", "10");
  svg.setAttribute("height", "10");
  svg.setAttribute("aria-hidden", "true");
  svg.style.imageRendering = "pixelated";

  grid.forEach((row, y) => {
    row.forEach((on, x) => {
      if (!on) return;
      const rect = document.createElementNS(SVG_NS, "rect");
      rect.setAttribute("x", String(x));
      rect.setAttribute("y", String(y));
      rect.setAttribute("width", "1");
      rect.setAttribute("height", "1");
      rect.setAttribute("fill", color);
      svg.appendChild(rect);
    });
  });

  return svg;
}

/**
 * @param {{ label: string, tone?: keyof typeof TONE_COLORS, icon?: keyof typeof ICONS }} props
 * @returns {HTMLElement}
 */
export function createStatusBadge({ label, tone = "neutral", icon = "dot" }) {
  const color = TONE_COLORS[tone] ?? TONE_COLORS.neutral;
  const grid = ICONS[icon] ?? ICONS.dot;

  const badge = document.createElement("span");
  badge.className = "status-badge";
  badge.dataset.tone = tone;

  badge.appendChild(renderPixelGrid(grid, color));

  const text = document.createElement("span");
  text.textContent = label;
  badge.appendChild(text);

  return badge;
}
