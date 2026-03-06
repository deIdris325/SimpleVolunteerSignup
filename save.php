<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

ensure_session();
//require_access_code();

$scheduleFile = __DIR__ . '/schedule.json';
$signupsFile  = __DIR__ . '/signups.json';

if (!csrf_check()) { header('Location: index.php?msg=csrf'); exit; }

$id = (string)($_POST['id'] ?? '');
$name = normalize_name((string)($_POST['name'] ?? ''));

if ($id === '' || $name === '' || mb_strlen($name) < 2) { header('Location: index.php?msg=bad'); exit; }

$rows = schedule_rows($scheduleFile);
$slotsMap = schedule_map_slots($rows);
$slots = (int)($slotsMap[$id] ?? 0);
if ($slots <= 0) { header('Location: index.php?msg=err'); exit; }

// After the normal slots are full, allow a small reserve list.
$reserveMax = 5;

$data = load_signups($signupsFile);
$list = $data[$id] ?? [];
if (!is_array($list)) $list = [];

if (count($list) >= ($slots + $reserveMax)) { header('Location: index.php?msg=full'); exit; }

$willBeReserve = (count($list) >= $slots);

if (!in_array($name, $list, true)) {
  $list[] = $name;
  $data[$id] = $list;
}

if (!save_signups_locked($signupsFile, $data)) { header('Location: index.php?msg=err'); exit; }

header('Location: index.php?msg=' . ($willBeReserve ? 'reserve' : 'ok')); exit;
