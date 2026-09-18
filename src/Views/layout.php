<?php
/**
 * Global layout — header/nav/footer per planning/00-portfolio/design-system.md
 * §Global layout and artcollect-design-system.md §6. `$content` and
 * optional `$title` are provided by View::render(). `$critical` (bool),
 * when set true by a page, suppresses the FAB and marks <main> with the
 * `.critical-flow` class — see artcollect-design-system.md §7 and this
 * platform's README: the SOS/safety surface gets the same zero-decoration
 * treatment as payment.
 */
$pageTitle = isset($title) ? $title . ' — rider.co.ke' : 'rider.co.ke';
$isCritical = $critical ?? false;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="stylesheet" href="/assets/css/main.css">
</head>
<body>
  <header class="site-header container">
    <a href="/" style="text-decoration:none;color:inherit;"><strong>rider.co.ke</strong></a>
    <nav aria-label="Primary" style="display:flex; align-items:center; gap: var(--ac-space-3);">
      <a href="/request" class="btn btn--secondary">Request a ride</a>
      <?php if (!empty($currentUser)): ?>
        <?php if (($currentUser['account_type'] ?? null) === 'provider'): ?>
          <a href="/riders/dashboard" class="btn btn--secondary">Rider dashboard</a>
        <?php endif; ?>
        <span class="card__meta">Hi, <?= \Rider\Core\View::e($currentUser['full_name']) ?></span>
        <form method="post" action="/logout" style="display:inline;">
          <button type="submit" class="btn btn--secondary">Log out</button>
        </form>
      <?php else: ?>
        <a href="/login" class="btn btn--secondary">Log in</a>
        <a href="/signup" class="btn btn--primary">Sign up</a>
      <?php endif; ?>
    </nav>
  </header>

  <main class="container<?= $isCritical ? ' critical-flow' : '' ?>" style="padding-block: var(--ac-space-8);">
    <?= $content ?>
  </main>

  <footer class="site-footer container">
    <p class="card__meta">&copy; <?= date('Y') ?> rider.co.ke — part of the artcollect.co.ke network. <a href="/showcase.html">Component showcase</a></p>
  </footer>

  <script type="module" src="/assets/js/main.js"></script>
  <?php if (!$isCritical): ?>
  <script type="module">
    import { createFab } from "/assets/js/components/fab.js";
    document.body.appendChild(createFab({ label: "Request a Ride", onClick: () => { window.location.href = "/request"; } }));
  </script>
  <?php endif; ?>
</body>
</html>
