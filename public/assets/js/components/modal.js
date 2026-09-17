/**
 * Modal — shared component. Traps focus, closes on Escape/backdrop click,
 * restores focus to the triggering element on close.
 * @param {{ content: HTMLElement, onClose?: () => void }} props
 * @returns {{ close: () => void }}
 */
export function openModal({ content, onClose }) {
  const previouslyFocused = document.activeElement;

  const backdrop = document.createElement("div");
  backdrop.className = "modal-backdrop";

  const modal = document.createElement("div");
  modal.className = "modal";
  modal.setAttribute("role", "dialog");
  modal.setAttribute("aria-modal", "true");
  modal.appendChild(content);

  backdrop.appendChild(modal);
  document.body.appendChild(backdrop);

  function close() {
    backdrop.remove();
    document.removeEventListener("keydown", onKeydown);
    if (previouslyFocused instanceof HTMLElement) {
      previouslyFocused.focus();
    }
    onClose?.();
  }

  function onKeydown(event) {
    if (event.key === "Escape") {
      close();
    }
  }

  backdrop.addEventListener("click", (event) => {
    if (event.target === backdrop) {
      close();
    }
  });
  document.addEventListener("keydown", onKeydown);

  const focusable = modal.querySelector(
    'a, button, input, textarea, select, [tabindex]:not([tabindex="-1"])'
  );
  (focusable ?? modal).focus?.();

  return { close };
}
