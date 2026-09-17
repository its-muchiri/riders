/**
 * Decorative "scrap" primitives — ported from artcollect.co.ke's collage-lane
 * component library (artcollect-design-system.md §4/§5) as plain SVG-DOM
 * constructors. Optional decoration for empty states/marketing content only.
 *
 * NEVER import this module from a trip-booking, payment, SOS/safety, or
 * dispute page — see pages/checkout.js for the enforced example. Per
 * artcollect-design-system.md §7 and this platform's README, rider.co.ke
 * extends the "no decoration" rule to the SOS/safety flow specifically.
 */

const SVG_NS = "http://www.w3.org/2000/svg";

/**
 * @param {{ seed?: number, intensity?: number }} [options]
 * @returns {SVGSVGElement}
 */
export function createTornEdge({ seed = 7, intensity = 12 } = {}) {
  const filterId = `torn-${Math.random().toString(36).slice(2)}`;

  const svg = document.createElementNS(SVG_NS, "svg");
  svg.setAttribute("aria-hidden", "true");
  svg.setAttribute("preserveAspectRatio", "none");
  svg.setAttribute("viewBox", "0 0 100 20");
  svg.classList.add("scrap-torn-edge");

  const defs = document.createElementNS(SVG_NS, "defs");
  const filter = document.createElementNS(SVG_NS, "filter");
  filter.setAttribute("id", filterId);
  filter.setAttribute("x", "-10%");
  filter.setAttribute("y", "-60%");
  filter.setAttribute("width", "120%");
  filter.setAttribute("height", "220%");

  const turbulence = document.createElementNS(SVG_NS, "feTurbulence");
  turbulence.setAttribute("type", "fractalNoise");
  turbulence.setAttribute("baseFrequency", "0.015 0.12");
  turbulence.setAttribute("numOctaves", "4");
  turbulence.setAttribute("seed", String(seed));
  turbulence.setAttribute("result", "tear");

  const displace = document.createElementNS(SVG_NS, "feDisplacementMap");
  displace.setAttribute("in", "SourceGraphic");
  displace.setAttribute("in2", "tear");
  displace.setAttribute("scale", String(intensity));
  displace.setAttribute("xChannelSelector", "R");
  displace.setAttribute("yChannelSelector", "G");

  filter.append(turbulence, displace);
  defs.appendChild(filter);

  const rect = document.createElementNS(SVG_NS, "rect");
  rect.setAttribute("x", "2%");
  rect.setAttribute("y", "28%");
  rect.setAttribute("width", "96%");
  rect.setAttribute("height", "44%");
  rect.setAttribute("fill", "currentColor");
  rect.setAttribute("filter", `url(#${filterId})`);

  svg.append(defs, rect);
  return svg;
}

export const ANNOTATION_TONES = {
  ink: { text: "var(--ac-ink)", backing: "var(--ac-paper)", rotate: 1.5 },
  accentOnPaper: { text: "var(--ac-accent)", backing: "var(--ac-paper)", rotate: -1 },
  highlight: { text: "var(--ac-ink)", backing: "var(--ac-highlighter)", rotate: -1.5 },
  pink: { text: "var(--ac-ink)", backing: "var(--ac-sticker-pink)", rotate: -2.5 },
};

/**
 * @param {{ text: string, tone?: keyof typeof ANNOTATION_TONES }} props
 * @returns {HTMLElement}
 */
export function createAnnotation({ text, tone = "ink" }) {
  const { text: textColor, backing, rotate } = ANNOTATION_TONES[tone];

  const note = document.createElement("span");
  note.className = "annotation";
  note.textContent = text;
  note.style.fontFamily = "var(--font-hand)";
  note.style.color = textColor;
  note.style.background = backing;
  note.style.display = "inline-block";
  note.style.padding = "var(--ac-space-1) var(--ac-space-3)";
  note.style.borderRadius = "var(--ac-radius-sticker)";
  note.style.transform = `rotate(${rotate}deg)`;
  return note;
}
