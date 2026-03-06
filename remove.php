<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

ensure_session();
require_access_code();

$signupsFile  = __DIR__ . '/signups.json';

if (!csrf_check()) { header('Location: index.php?msg=csrf'); exit; }

$id = (string)($_POST['id'] ?? '');
$name = normalize_name((string)($_POST['name'] ?? ''));

if ($id === '' || $name === '') { header('Location: index.php'); exit; }

$data = load_signups($signupsFile);
$list = $data[$id] ?? [];
if (!is_array($list)) $list = [];

$data[$id] = array_values(array_filter($list, fn($n)=> (string)$n !== $name));
save_signups_locked($signupsFile, $data);

header('Location: index.php'); exit;
