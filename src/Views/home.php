<?php
/** @var array $riders */
/** @var string|null $dbError */
use Rider\Core\View;
?>
<section style="padding-block: var(--ac-space-8) var(--ac-space-12);">
  <h1 style="font-size: 2.5rem; max-width: 32rem;">Get a ride or send a delivery — now.</h1>
  <p style="max-width: var(--ac-measure); margin-block: var(--ac-space-4);">
    Verified riders near you, upfront fixed-price fares, and live GPS tracking from pickup to drop-off.
  </p>
  <a href="/request" class="btn btn--primary">Request a ride or delivery</a>
</section>

<section style="padding-block: var(--ac-space-8); border-block: 1px solid var(--ac-paper-deep);">
  <h2>How it works</h2>
  <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-6); margin-top: var(--ac-space-4);">
    <div style="flex: 1 1 12rem;">
      <strong>1. Request</strong>
      <p class="card__meta">Set your pickup and destination, pick motorcycle, car, or parcel delivery.</p>
    </div>
    <div style="flex: 1 1 12rem;">
      <strong>2. Matched</strong>
      <p class="card__meta">A nearby verified rider accepts and heads to your pickup point.</p>
    </div>
    <div style="flex: 1 1 12rem;">
      <strong>3. Track</strong>
      <p class="card__meta">Live GPS tracking and an SOS button are available for the whole trip.</p>
    </div>
  </div>
</section>

<section style="padding-block: var(--ac-space-8);">
  <h2>Top-rated riders on the network</h2>
  <?php if ($dbError): ?>
    <p class="card__meta"><?= View::e($dbError) ?></p>
  <?php elseif (empty($riders)): ?>
    <p class="card__meta">No riders are onboarded yet in this environment — see src/Controllers/OnboardingController.php to add one, or seed the <code>users</code>/<code>rider_performance_tiers</code> tables directly for a demo.</p>
  <?php else: ?>
    <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-4); margin-top: var(--ac-space-4);">
      <?php foreach ($riders as $rider): ?>
        <div class="card" style="width: 16rem;">
          <h3><?= View::e($rider['full_name']) ?></h3>
          <div class="card__meta">
            <?= $rider['average_rating'] !== null ? number_format((float) $rider['average_rating'], 1) . ' ★' : 'No ratings yet' ?>
            · <?= View::e(ucfirst($rider['tier'] ?? 'standard')) ?> tier
          </div>
          <a href="/riders/<?= (int) $rider['id'] ?>" class="btn btn--secondary" style="margin-top: var(--ac-space-3);">View profile</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
