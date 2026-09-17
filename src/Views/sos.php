<?php
/** @var array|null $trip */
/** @var int $tripId */
use Rider\Core\View;
?>
<h1>Safety — Trip #<?= $tripId ?></h1>

<?php if ($trip): ?>
  <p><?= View::e($trip['pickup_address'] ?? 'Pickup address not set') ?> → <?= View::e($trip['destination_address'] ?? 'Destination not set') ?></p>
<?php endif; ?>

<p>Press the button below only if you need immediate help. This alerts the Safety Admin team with this trip's location history.</p>

<button type="button" id="sos-button" class="btn btn--primary" style="background: var(--ac-danger); font-size: 1.25rem; padding: var(--ac-space-4) var(--ac-space-8);">
  Send SOS
</button>

<p id="sos-result" style="margin-top: var(--ac-space-4);"></p>

<script type="module">
  import { triggerSos } from "/assets/js/pages/sos.js";

  document.getElementById("sos-button").addEventListener("click", async () => {
    const button = document.getElementById("sos-button");
    const resultEl = document.getElementById("sos-result");
    button.disabled = true;
    button.textContent = "Sending…";

    try {
      const data = await triggerSos(<?= $tripId ?>);
      resultEl.textContent = data.status === "sos_escalated"
        ? "SOS sent. The Safety Admin team has been alerted."
        : "SOS request failed: " + (data.error || "unknown error") + " — expected until a live database is connected.";
    } catch (e) {
      resultEl.textContent = "Network error: " + e.message;
    } finally {
      button.disabled = false;
      button.textContent = "Send SOS";
    }
  });
</script>
