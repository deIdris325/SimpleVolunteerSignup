<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$scheduleFile = __DIR__ . '/schedule.json';
$signupsFile  = __DIR__ . '/signups.json';

session_start();

/* ---------- helpers ---------- */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function ensureScheduleFile(string $file): void {
  if (!file_exists($file) || filesize($file) === 0) {
    file_put_contents($file, "[]", LOCK_EX);
  }
}

function loadSchedule(string $file): array {
  ensureScheduleFile($file);
  $raw = file_get_contents($file);
  $json = json_decode($raw ?: '', true);
  if (!is_array($json)) return [];

  $rows = [];
  foreach ($json as $item) {
    if (!is_array($item)) continue;

    $date  = normalizeText((string)($item['date'] ?? ''));
    $day   = normalizeText((string)($item['day'] ?? ''));
    $task  = normalizeText((string)($item['task'] ?? ''));
    $slots = (int)($item['slots'] ?? 0);
    $hidden = !empty($item['hidden']);

    if ($date === '' || $day === '' || $task === '' || $slots <= 0) continue;

    $id = hash('sha256', $date . '|' . $day . '|' . $task);

    $rows[] = ['id'=>$id,'date'=>$date,'day'=>$day,'task'=>$task,'slots'=>$slots,'hidden'=>$hidden];
  }
  return $rows;
}

function saveSchedule(string $file, array $rows): bool {
  ensureScheduleFile($file);

  // Only persist fields we actually need
  $outRows = [];
  foreach ($rows as $r) {
    $outRows[] = [
      'date'   => (string)($r['date'] ?? ''),
      'day'    => (string)($r['day'] ?? ''),
      'task'   => (string)($r['task'] ?? ''),
      'slots'  => (int)($r['slots'] ?? 0),
      'hidden' => !empty($r['hidden']),
    ];
  }

  $fp = fopen($file, 'c+');
  if (!$fp) return false;
  if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }

  rewind($fp);
  ftruncate($fp, 0);
  $ok = fwrite($fp, json_encode($outRows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  fflush($fp);

  flock($fp, LOCK_UN);
  fclose($fp);
  return $ok !== false;
}

function loadJson(string $file): array {
  if (!file_exists($file)) return [];
  $raw = file_get_contents($file);
  $json = json_decode($raw ?: '', true);
  return is_array($json) ? $json : [];
}

function saveJson(string $file, array $data): bool {
  return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), LOCK_EX) !== false;
}

function normalizeText(string $s): string {
  $s = strip_tags($s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim((string)$s);
}

/* ---------- auth ---------- */
if (isset($_GET['logout'])) {
  $_SESSION = [];
  session_destroy();
  header('Location: admin.php');
  exit;
}

if (isset($_POST['login_password'])) {
  $pw = (string)($_POST['login_password'] ?? '');
  if (hash_equals((string)$config['admin_password'], $pw)) {
    $_SESSION['admin'] = true;
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
    header('Location: admin.php');
    exit;
  }
  header('Location: admin.php?msg=badpw');
  exit;
}

if (!isset($_SESSION['admin']) || $_SESSION['admin'] !== true) {
  $msg = $_GET['msg'] ?? '';
  ?>
  <!doctype html>
  <html lang="de">
  <head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Login</title>
    <link rel="stylesheet" href="admin.css">
    <style>
      .rowActions{display:flex;gap:8px;align-items:center}
      .miniForm{margin:0}
      .btn-secondary{background:#eee;border:1px solid #ccc;padding:6px 10px;border-radius:8px;cursor:pointer}
      .btn-secondary:hover{filter:brightness(0.97)}
    </style>

  </head>
  <body>
    <div class="wrap">
      <h1>Admin</h1>
      <?php if ($msg === 'badpw'): ?><div class="note err">❌ Falsches Passwort.</div><?php endif; ?>
      <div class="card">
        <form method="post">
          <input type="password" name="login_password" placeholder="Passwort" required>
          <button type="submit">Login</button>
        </form>
      </div>
    </div>
  </body>
  </html>
  <?php
  exit;
}

$csrf = (string)($_SESSION['csrf'] ?? '');
if ($csrf === '') { $_SESSION['csrf'] = bin2hex(random_bytes(16)); $csrf = $_SESSION['csrf']; }

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  $token = (string)($_POST['csrf'] ?? '');
  if (!hash_equals($csrf, $token)) {
    header('Location: admin.php?msg=csrf');
    exit;
  }

  $action = (string)$_POST['action'];
  $rows = loadSchedule($scheduleFile);
  $signups = loadJson($signupsFile);

  if ($action === 'update_row') {
    $oldId = (string)($_POST['old_id'] ?? '');
    $date  = normalizeText((string)($_POST['date'] ?? ''));
    $day   = normalizeText((string)($_POST['day'] ?? ''));
    $task  = normalizeText((string)($_POST['task'] ?? ''));
    $slots = (int)($_POST['slots'] ?? 0);

    if ($oldId === '' || $date === '' || $day === '' || $task === '' || $slots < 1 || $slots > 99) {
      header('Location: admin.php?msg=bad');
      exit;
    }

    $newId = hash('sha256', $date.'|'.$day.'|'.$task);

    // Update row in schedule
    $found = false;
    foreach ($rows as &$r) {
      if (($r['id'] ?? '') === $oldId) {
        $wasHidden = !empty($r['hidden']);
        $r = ['id'=>$newId,'date'=>$date,'day'=>$day,'task'=>$task,'slots'=>$slots,'hidden'=>$wasHidden];
        $found = true;
        break;
      }
    }
    unset($r);

    if (!$found) {
      header('Location: admin.php?msg=notfound');
      exit;
    }

    // Migrate signups oldId -> newId (if changed)
    if ($newId !== $oldId && isset($signups[$oldId]) && is_array($signups[$oldId])) {
      if (!isset($signups[$newId]) || !is_array($signups[$newId])) $signups[$newId] = [];
      $signups[$newId] = array_values(array_unique(array_merge($signups[$newId], $signups[$oldId])));
      unset($signups[$oldId]);
    }

    if (!saveSchedule($scheduleFile, $rows)) {
      header('Location: admin.php?msg=saveerr');
      exit;
    }
    saveJson($signupsFile, $signups);

    header('Location: admin.php?msg=saved');
    exit;
  }

  if ($action === 'delete_row') {
    $delId = (string)($_POST['id'] ?? '');
    if ($delId === '') { header('Location: admin.php?msg=delbad'); exit; }

    $rows = array_values(array_filter($rows, fn($r) => ($r['id'] ?? '') !== $delId));
    if (isset($signups[$delId])) unset($signups[$delId]);

    if (!saveSchedule($scheduleFile, $rows)) { header('Location: admin.php?msg=delerr'); exit; }
    saveJson($signupsFile, $signups);

    header('Location: admin.php?msg=delok');
    exit;
  }

  
  if ($action === 'toggle_hide') {
    $toggleId = (string)($_POST['id'] ?? '');
    if ($toggleId === '') { header('Location: admin.php?msg=hidebad'); exit; }

    $found = false;
    foreach ($rows as &$r) {
      if (($r['id'] ?? '') === $toggleId) {
        $r['hidden'] = empty($r['hidden']); // toggle
        $found = true;
        break;
      }
    }
    unset($r);

    if (!$found) { header('Location: admin.php?msg=hidenotfound'); exit; }

    if (!saveSchedule($scheduleFile, $rows)) { header('Location: admin.php?msg=hideerr'); exit; }
    header('Location: admin.php?msg=hideok');
    exit;
  }

if ($action === 'add_row') {
    $date  = normalizeText((string)($_POST['date'] ?? ''));
    $day   = normalizeText((string)($_POST['day'] ?? ''));
    $task  = normalizeText((string)($_POST['task'] ?? ''));
    $slots = (int)($_POST['slots'] ?? 0);

    if ($date === '' || $day === '' || $task === '' || $slots < 1 || $slots > 99) {
      header('Location: admin.php?msg=addbad');
      exit;
    }

    $rows[] = [
      'id'    => hash('sha256', $date.'|'.$day.'|'.$task),
      'date'  => $date,
      'day'   => $day,
      'task'  => $task,
      'slots' => $slots,
    ];

    if (!saveSchedule($scheduleFile, $rows)) { header('Location: admin.php?msg=adderror'); exit; }
    header('Location: admin.php?msg=addok'); exit;
  }
}

/* ---------- render ---------- */
$rows = loadSchedule($scheduleFile);
$signups = loadJson($signupsFile);
$msg = $_GET['msg'] ?? '';
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin</title>
  <link rel="stylesheet" href="admin.css">
    <style>
      .rowActions{display:flex;gap:8px;align-items:center}
      .miniForm{margin:0}
      .btn-secondary{background:#eee;border:1px solid #ccc;padding:6px 10px;border-radius:8px;cursor:pointer}
      .btn-secondary:hover{filter:brightness(0.97)}
    </style>

</head>
<body>
<div class="wrap">

  <div class="topbar">
    <h1 style="margin:0;">Admin</h1>
    <div class="toplinks">
      <a href="index.php">← Zur Startseite</a>
      <a href="admin.php?logout=1">Logout</a>
    </div>
  </div>

  <?php if ($msg === 'saved'): ?><div class="note ok">✅ Gespeichert.</div><?php endif; ?>
  <?php if ($msg === 'saveerr'): ?><div class="note err">❌ Konnte schedule.json nicht speichern.</div><?php endif; ?>
  <?php if ($msg === 'bad'): ?><div class="note err">❌ Bitte alle Felder korrekt ausfüllen.</div><?php endif; ?>
  <?php if ($msg === 'csrf'): ?><div class="note err">❌ Sicherheitsfehler. Seite neu laden.</div><?php endif; ?>
  <?php if ($msg === 'delok'): ?><div class="note ok">✅ Gelöscht.</div><?php endif; ?>
  <?php if ($msg === 'delerr'): ?><div class="note err">❌ Löschen fehlgeschlagen.</div><?php endif; ?>
  <?php if ($msg === 'addok'): ?><div class="note ok">✅ Hinzugefügt.</div><?php endif; ?>
  <?php if ($msg === 'adderror'): ?><div class="note err">❌ Hinzufügen fehlgeschlagen.</div><?php endif; ?>
  <?php if ($msg === 'addbad'): ?><div class="note err">❌ Felder für neuen Eintrag prüfen.</div><?php endif; ?>
  <?php if ($msg === 'notfound'): ?><div class="note err">❌ Eintrag nicht gefunden.</div><?php endif; ?>

  <div class="card">
    <h2 style="margin:0 0 10px;">Bestehende Einträge</h2>

    <?php foreach ($rows as $r):
      $id = $r['id'];
      $entered = (isset($signups[$id]) && is_array($signups[$id])) ? count($signups[$id]) : 0;
    ?>
      <div class="entryCard">
        <div class="entryHeader">
          <div>
            <div class="entryTitle"><?= h($r['task']) ?></div>
            <div class="muted"><?= h($r['day']) ?>, <?= h($r['date']) ?></div>
            <div class="muted">Plätze: <?= (int)$r['slots'] ?> · Eingetragen: <?= (int)$entered ?><?= !empty($r['hidden']) ? ' · <strong>Versteckt</strong>' : '' ?></div>
          </div>

          <div class="rowActions">
            <form method="post" action="admin.php" class="miniForm">
              <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['csrf'] ?? '')) ?>">
              <input type="hidden" name="action" value="toggle_hide">
              <input type="hidden" name="id" value="<?= h($id) ?>">
              <button type="submit" class="btn-secondary" title="<?= !empty($r['hidden']) ? 'Einblenden' : 'Verstecken' ?>">
                <?= !empty($r['hidden']) ? 'Einblenden' : 'Verstecken' ?>
              </button>
            </form>

            <form method="post" action="admin.php" class="miniForm">
              <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['csrf'] ?? '')) ?>">
              <input type="hidden" name="action" value="delete_row">
              <input type="hidden" name="id" value="<?= h($id) ?>">
              <button type="submit" class="btn-danger" onclick="return confirm('Eintrag wirklich löschen?')">Löschen</button>
            </form>
          </div>
        </div>

        <form method="post" action="admin.php" class="row">
          <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['csrf'] ?? '')) ?>">
          <input type="hidden" name="action" value="update_row">
          <input type="hidden" name="old_id" value="<?= h($id) ?>">

          <input name="date"  value="<?= h($r['date']) ?>" placeholder="Datum" required>
          <input name="day"   value="<?= h($r['day']) ?>" placeholder="Tag" required>
          <input name="task"  value="<?= h($r['task']) ?>" placeholder="Aufgabe" required>
          <input name="slots" value="<?= (int)$r['slots'] ?>" type="number" min="1" max="99" required style="width:90px;">
          <button type="submit" class="btn-save">Speichern</button>
        </form>
      </div>
    <?php endforeach; ?>

    <?php if (count($rows) === 0): ?>
      <div class="note err">Keine Einträge gefunden.</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2 style="margin:0 0 10px;">Neuen Eintrag hinzufügen</h2>
    <form method="post" action="admin.php" class="row">
      <input type="hidden" name="csrf" value="<?= h((string)($_SESSION['csrf'] ?? '')) ?>">
      <input type="hidden" name="action" value="add_row">
      <input name="date" placeholder="Datum (z.B. 21.02.2026)" required>
      <input name="day"  placeholder="Tag (z.B. Samstag)" required>
      <input name="task" placeholder="Aufgabe (z.B. Helfer)" required>
      <input name="slots" type="number" min="1" max="99" value="8" required style="width:90px;">
      <button type="submit" class="btn-save">Hinzufügen</button>
    </form>
  </div>

</div>
</body>
</html>