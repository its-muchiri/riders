<?php
/** @var array|null $rider */
/** @var int $riderId */
/** @var string|null $dbError */
use Rider\Core\View;
?>
<?php if ($dbError): ?>
  <p class="card__meta"><?= View::e($dbError) ?></p>
<?php elseif (!$rider): ?>
  <h1>Rider #<?= $riderId ?></h1>
  <p class="card__meta">No rider found with this ID.</p>
<?php else: ?>
  <h1><?= View::e($rider['full_name']) ?></h1>
  <div id="verification-badge" style="margin: var(--ac-space-2) 0 var(--ac-space-4);"></div>

  <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-6);">
    <div>
      <h3>Performance</h3>
      <?php if ($rider['performance']): ?>
        <p class="card__meta">
          <?= number_format((float) $rider['performance']['average_rating'], 1) ?> ★
          · <?= number_format((float) $rider['performance']['completion_rate'], 1) ?>% completion
          · <?= View::e(ucfirst($rider['performance']['tier'])) ?> tier
        </p>
      <?php else: ?>
        <p class="card__meta">No completed trips yet.</p>
      <?php endif; ?>
    </div>

    <div>
      <h3>Vehicle</h3>
      <?php if ($rider['vehicle']): ?>
        <p class="card__meta">
          <?= View::e(ucfirst($rider['vehicle']['vehicle_type'])) ?> · <?= View::e($rider['vehicle']['plate_number']) ?>
        </p>
      <?php else: ?>
        <p class="card__meta">No vehicle document on file.</p>
      <?php endif; ?>
    </div>
  </div>

  <script type="module">
    import { createStatusBadge } from "/assets/js/components/status-badge.js";

    const vehicleStatus = <?= json_encode($rider['vehicle']['verification_status'] ?? null) ?>;
    const tone = { verified: "success", pending: "warning", rejected: "danger" }[vehicleStatus] ?? "neutral";
    const label = vehicleStatus ? "KYC " + vehicleStatus : "No KYC on file";

    document.getElementById("verification-badge").appendChild(createStatusBadge({ label, tone }));
  </script>
<?php endif; ?>
