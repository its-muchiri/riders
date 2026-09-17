<?php
/** @var string|null $error */
/** @var array $old */
use Rider\Core\View;
?>
<h1>Sign up</h1>
<p style="max-width: var(--ac-measure); margin-block: var(--ac-space-2) var(--ac-space-6);">
  Create an account to request a ride or delivery, or sign up as a rider to start receiving trip offers.
</p>

<?php if ($error): ?>
  <p class="card__meta" role="alert" style="color: #b3261e;"><?= View::e($error) ?></p>
<?php endif; ?>

<form method="post" action="/signup" style="max-width: 28rem; display:flex; flex-direction:column; gap: var(--ac-space-4);">
  <label>
    Full name
    <input type="text" name="full_name" required value="<?= View::e($old['full_name'] ?? '') ?>" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <label>
    Phone number
    <input type="tel" name="phone_number" placeholder="0712345678" required value="<?= View::e($old['phone_number'] ?? '') ?>" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <label>
    Email (optional)
    <input type="email" name="email" value="<?= View::e($old['email'] ?? '') ?>" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <label>
    Password
    <input type="password" name="password" minlength="8" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <fieldset style="border: 1px solid #ddd; padding: var(--ac-space-3);">
    <legend>I am a&hellip;</legend>
    <label style="display:block;">
      <input type="radio" name="account_type" value="customer" id="account-type-customer" <?= ($old['account_type'] ?? 'customer') === 'customer' ? 'checked' : '' ?>>
      Customer requesting a ride or delivery
    </label>
    <label style="display:block;">
      <input type="radio" name="account_type" value="provider" id="account-type-provider" <?= ($old['account_type'] ?? '') === 'provider' ? 'checked' : '' ?>>
      Rider wanting to receive trip offers
    </label>
  </fieldset>
  <label id="national-id-field" hidden>
    National ID number
    <input type="text" name="national_id_number" value="<?= View::e($old['national_id_number'] ?? '') ?>" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
    <span class="card__meta">Required for riders — this is the minimum ID check before you can start onboarding (see the driving license/insurance step next).</span>
  </label>
  <button type="submit" class="btn btn--primary">Create account</button>
</form>

<p class="card__meta" style="margin-top: var(--ac-space-4);">Already have an account? <a href="/login">Log in</a></p>

<script type="module">
  const nationalIdField = document.getElementById('national-id-field');
  const nationalIdInput = nationalIdField.querySelector('input');
  function syncNationalIdField() {
    const isProvider = document.getElementById('account-type-provider').checked;
    nationalIdField.hidden = !isProvider;
    nationalIdInput.required = isProvider;
  }
  document.getElementById('account-type-customer').addEventListener('change', syncNationalIdField);
  document.getElementById('account-type-provider').addEventListener('change', syncNationalIdField);
  syncNationalIdField();
</script>
