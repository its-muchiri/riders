/**
 * Status Timeline — shared component. This platform's lifecycle is defined
 * in planning/02-rider-co-ke/database-schema.md's rider_trips.status enum.
 * @param {{ steps: string[], currentIndex: number }} props
 * @returns {HTMLElement}
 */
export function createStatusTimeline({ steps, currentIndex }) {
  const list = document.createElement("ol");
  list.className = "status-timeline";

  steps.forEach((label, index) => {
    const item = document.createElement("li");
    item.className = "status-timeline__step" + (index <= currentIndex ? " status-timeline__step--done" : "");
    item.textContent = label;
    list.appendChild(item);
  });

  return list;
}

export const RIDER_TRIP_STEPS = ["Requested", "Matched", "Rider en route", "In progress", "Completed"];
