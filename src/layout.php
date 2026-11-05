<?php
// Page chrome for the admin, remote and attendee pages (iMasons house style).

declare(strict_types=1);

function page_start(string $title, string $bodyClass = '', string $heading = ''): void
{
    ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex">
  <title><?= h($title) ?></title>
  <link rel="preload" href="assets/fonts/lexend-400.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="assets/fonts/lexend-600.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="assets/fonts/lexend.css?v=1">
  <link rel="stylesheet" href="assets/app.css?v=9">
</head>
<body class="<?= h($bodyClass) ?>">
<div class="stripe"></div>
<header class="band">
  <div class="wrap band-inner">
    <span class="brand">iMasons</span>
    <?php if ($heading !== ''): ?><span class="band-title"><?= h($heading) ?></span><?php endif; ?>
    <?php if (is_admin()): ?><nav class="band-nav"><a href="admin.php">Events</a><a href="login.php?out=1">Log out</a></nav><?php endif; ?>
  </div>
</header>
<main class="wrap">
<?php
}

function page_end(string $scripts = ''): void
{
    ?>
</main>
<?= $scripts ?>
</body>
</html>
<?php
}

function flash(?string $msg = null): ?string
{
    start_session();
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}
