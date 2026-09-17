<?php
/** @var string|null $error */
use Rider\Core\View;
?>
<h1>Log in</h1>

<?php if ($error): ?>
  <p class="card__meta" role="alert" style="color: #b3261e;"><?= View::e($error) ?></p>
<?php endif; ?>

<form method="post" action="/login" style="max-width: 28rem; display:flex; flex-direction:column; gap: var(--ac-space-4);">
  <label>
    Phone number or email
    <input type="text" name="phone_number" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <label>
    Password
    <input type="password" name="password" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <button type="submit" class="btn btn--primary">Log in</button>
</form>

<p class="card__meta" style="margin-top: var(--ac-space-4);">Don't have an account? <a href="/signup">Sign up</a></p>
