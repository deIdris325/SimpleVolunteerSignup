<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

ensure_session();
require_access_code();

$scheduleFile = __DIR__ . '/schedule.json';
$signupsFile  = __DIR__ . '/signups.json';

$rows = schedule_rows($scheduleFile);
$signups = load_signups($signupsFile);
$rowById = [];
foreach ($rows as $r) $rowById[$r['id']] = $r;

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="freiwillige_export.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Datum','Tag','Aufgabe','Plaetze','Name'], ';');

foreach ($signups as $id => $names) {
  if (!is_array($names)) continue;
  $r = $rowById[$id] ?? null;
  if (!$r) continue;
  foreach ($names as $name) {
    fputcsv($out, [$r['date'],$r['day'],$r['task'],$r['slots'],(string)$name], ';');
  }
}
fclose($out);
exit;
