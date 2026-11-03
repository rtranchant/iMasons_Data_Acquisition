<?php
declare(strict_types=1);

require __DIR__ . '/../src/model.php';
require __DIR__ . '/../src/layout.php';

start_session();

if (isset($_GET['out'])) {
    $_SESSION = [];
    session_destroy();
    redirect('login.php');
}

$next = (string) ($_GET['next'] ?? $_POST['next'] ?? 'admin.php');
if (!preg_match('#^/[^/\\\\]|^[a-z]+\.php#i', $next)) {
    $next = 'admin.php'; // only local redirects
}

$configured = isset(app_config()['admin_password']) && app_config()['admin_password'] !== '';
$error = null;

if ($configured && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Slow down guessing a little.
    usleep(400000);
    if (hash_equals((string) app_config()['admin_password'], (string) ($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        migrate();
        redirect($next);
    }
    $error = 'Wrong password.';
}

page_start('Log in – iMasons Data Acquisition', '', 'Data Acquisition');
?>
<div style="max-width:420px">
  <h1>Log in</h1>
  <?php if (!$configured): ?>
    <div class="error">No admin password is set yet.</div>
    <p>Open <b>imasons_config.php</b> on the server and add this line inside the outer <code>[ ]</code>, next to <code>'db'</code>:</p>
    <p class="codebox">'admin_password' => 'choose-a-long-password',</p>
    <p class="muted small">Then reload this page.</p>
  <?php else: ?>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <label for="pw">Admin password</label>
      <input type="password" id="pw" name="password" autocomplete="current-password" autofocus required>
      <p><button class="btn block" type="submit">Log in</button></p>
    </form>
  <?php endif; ?>
</div>
<?php
page_end();
