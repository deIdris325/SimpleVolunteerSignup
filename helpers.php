<?php
// volunteers/helpers.php
declare(strict_types=1);

function cfg(): array {
  static $c = null;
  if ($c === null) $c = require __DIR__ . '/config.php';
  return $c;
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ensure_session(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
}


function default_text_settings(): array {
  return [
    'site_name' => 'OpenSignup Board',
    'page_title' => 'Anmeldung',
    'page_subtitle' => 'Trage dich hier für Termine, Aufgaben oder Dienste ein.',
    'export_label' => 'Export (CSV)',
    'admin_label' => 'Admin',
    'entry_saved_message' => 'Eintrag gespeichert.',
    'reserve_message' => 'Die regulären Plätze sind voll – du bist auf der Reserveliste.',
    'full_message' => 'Dieser Eintrag ist bereits vollständig belegt.',
    'invalid_name_message' => 'Bitte einen gültigen Namen eingeben.',
    'csrf_message' => 'Sicherheitsfehler. Bitte die Seite neu laden.',
    'generic_error_message' => 'Fehler beim Speichern.',
    'table_date_label' => 'Datum',
    'table_day_label' => 'Tag',
    'table_task_label' => 'Aufgabe',
    'table_slots_label' => 'Plätze',
    'table_names_label' => 'Wer ist eingetragen?',
    'table_count_label' => 'Eingetragen',
    'table_free_label' => 'Noch frei',
    'table_signup_label' => 'Eintragen',
    'name_placeholder' => 'Name',
    'signup_button_label' => 'Eintragen',
    'remove_button_label' => 'Austragen',
    'reserve_label' => 'Reserve',
    'empty_names_label' => '–',
    'mobile_view_label' => 'Mobile Ansicht',
    'status_free_label' => 'Frei',
    'status_reserve_label' => 'Reserve',
    'status_full_label' => 'Voll',
    'admin_panel_title' => 'Admin',
    'admin_back_label' => '← Zur Startseite',
    'admin_logout_label' => 'Logout',
    'admin_existing_entries_label' => 'Bestehende Einträge',
    'admin_no_entries_label' => 'Keine Einträge gefunden.',
    'admin_add_entry_label' => 'Neuen Eintrag hinzufügen',
    'admin_save_button_label' => 'Speichern',
    'admin_add_button_label' => 'Hinzufügen',
    'admin_hide_button_label' => 'Verstecken',
    'admin_show_button_label' => 'Einblenden',
    'admin_delete_button_label' => 'Löschen',
    'admin_delete_confirm' => 'Eintrag wirklich löschen?',
    'admin_date_placeholder' => 'Datum',
    'admin_day_placeholder' => 'Tag',
    'admin_task_placeholder' => 'Aufgabe',
    'admin_new_date_placeholder' => 'Datum (z.B. 21.02.2026)',
    'admin_new_day_placeholder' => 'Tag (z.B. Samstag)',
    'admin_new_task_placeholder' => 'Aufgabe (z.B. Helfer)',
    'admin_settings_label' => 'Texte & Grundeinstellungen',
    'admin_settings_saved' => 'Texte gespeichert.',
    'settings_site_name_label' => 'Projektname',
    'settings_page_title_label' => 'Seitentitel',
    'settings_page_subtitle_label' => 'Untertitel / Hinweis',
    'settings_export_label_label' => 'Text für Export-Link',
    'settings_admin_label_label' => 'Text für Admin-Link',
    'settings_name_placeholder_label' => 'Platzhalter Namensfeld',
    'settings_signup_button_label' => 'Text für Eintragen-Button',
    'settings_remove_button_label' => 'Text für Austragen-Button',
  ];
}

function load_text_settings(): array {
  static $settings = null;
  if ($settings !== null) return $settings;
  $file = __DIR__ . '/settings.json';
  $defaults = default_text_settings();
  if (!file_exists($file)) {
    save_json_locked($file, $defaults);
    $settings = $defaults;
    return $settings;
  }
  $raw = file_get_contents($file);
  $json = json_decode($raw ?: '', true);
  if (!is_array($json)) {
    $settings = $defaults;
    return $settings;
  }
  $settings = array_merge($defaults, $json);
  return $settings;
}

function save_json_locked(string $file, array $data): bool {
  $fp = fopen($file, 'c+');
  if (!$fp) return false;
  if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
  rewind($fp);
  ftruncate($fp, 0);
  $ok = fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  fflush($fp);
  flock($fp, LOCK_UN);
  fclose($fp);
  return $ok !== false;
}

function text_setting(string $key): string {
  $settings = load_text_settings();
  $value = $settings[$key] ?? '';
  return is_string($value) ? $value : '';
}

/**
 * Read schedule rows from CSV.
 *
 * Expected columns (CSV may be ; or , separated):
 *   Datum, Tag, Aufgabe, Plätze, [optional] Versteckt
 *
 * If the optional "Versteckt" column is present (or a 5th value exists),
 * rows marked as hidden are omitted unless $includeHidden is true.
 */
function schedule_rows(string $scheduleFile, bool $includeHidden = false): array {
  $ext = mb_strtolower((string)pathinfo($scheduleFile, PATHINFO_EXTENSION));

  // Preferred: JSON schedule
  if ($ext === 'json') {
    // Auto-migrate from CSV if JSON doesn't exist yet
    if (!file_exists($scheduleFile)) {
      $csvFallback = preg_replace('/\.json$/i', '.csv', $scheduleFile);
      if ($csvFallback && is_string($csvFallback) && file_exists($csvFallback)) {
        $rows = schedule_rows_from_csv($csvFallback, true);
        save_schedule_locked($scheduleFile, $rows);
        return $includeHidden ? $rows : array_values(array_filter($rows, fn($r) => empty($r['hidden'])));
      }
      return [];
    }

    $raw = file_get_contents($scheduleFile);
    $json = json_decode($raw ?: '', true);
    if (!is_array($json)) return [];

    $rows = [];
    foreach ($json as $item) {
      if (!is_array($item)) continue;

      $date  = trim((string)($item['date'] ?? ''));
      $day   = trim((string)($item['day'] ?? ''));
      $task  = trim((string)($item['task'] ?? ''));
      $slots = (int)($item['slots'] ?? 0);
      $hidden = !empty($item['hidden']);

      if ($date === '' || $task === '' || $slots <= 0) continue;

      // Stable ID: date|day|task (NOT slots)
      $id = hash('sha256', $date . '|' . $day . '|' . $task);

      if ($hidden && !$includeHidden) continue;

      $rows[] = [
        'id'     => $id,
        'date'   => $date,
        'day'    => $day,
        'task'   => $task,
        'slots'  => $slots,
        'hidden' => $hidden,
      ];
    }
    return $rows;
  }

  // Legacy: CSV schedule (kept for backwards compatibility)
  return schedule_rows_from_csv($scheduleFile, $includeHidden);
}

function schedule_rows_from_csv(string $scheduleFile, bool $includeHidden = false): array {
  if (!file_exists($scheduleFile)) return [];
  $content = file_get_contents($scheduleFile);
  if ($content === false) return [];
  $lines = preg_split("/
|
|
/", trim($content));
  if (!$lines) return [];

  $start = 0;
  $hasHeader = (stripos($lines[0], 'datum') !== false);
  if ($hasHeader) $start = 1;

  // Detect whether there is a "Versteckt" column in the header.
  $headerDelim = (substr_count($lines[0], ';') >= 3) ? ';' : ',';
  $headerCols = $hasHeader ? array_map('trim', str_getcsv($lines[0], $headerDelim)) : [];
  $hiddenColIndex = null;
  if ($hasHeader) {
    foreach ($headerCols as $idx => $colName) {
      $colLower = mb_strtolower($colName);
      if ($colLower === 'versteckt' || $colLower === 'hidden') {
        $hiddenColIndex = $idx;
        break;
      }
    }
  }

  $rows = [];
  for ($i=$start; $i<count($lines); $i++) {
    $line = trim($lines[$i]);
    if ($line === '') continue;
    $delim = (substr_count($line, ';') >= 3) ? ';' : ',';
    $p = str_getcsv($line, $delim);

    $date  = trim($p[0] ?? '');
    $day   = trim($p[1] ?? '');
    $task  = trim($p[2] ?? '');
    $slots = (int)trim($p[3] ?? '0');

    // Optional hidden flag: either explicit "Versteckt" column or 5th value.
    $rawHidden = '';
    if ($hiddenColIndex !== null) {
      $rawHidden = trim((string)($p[$hiddenColIndex] ?? ''));
    } else {
      $rawHidden = trim((string)($p[4] ?? ''));
    }
    $rawHiddenLower = mb_strtolower($rawHidden);
    $hidden = in_array($rawHiddenLower, ['1', 'ja', 'yes', 'true', 'versteckt', 'hidden'], true);

    if ($hidden && !$includeHidden) continue;

    if ($date === '' || $task === '' || $slots <= 0) continue;

    // Stable ID: date|day|task (NOT slots)
    $id = hash('sha256', $date . '|' . $day . '|' . $task);

    $rows[] = [
      'id'    => $id,
      'date'  => $date,
      'day'   => $day,
      'task'  => $task,
      'slots' => $slots,
      'hidden'=> $hidden,
    ];
  }
  return $rows;
}

function save_schedule_locked(string $scheduleFile, array $rows): bool {
  $fp = fopen($scheduleFile, 'c+');
  if (!$fp) return false;
  if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }

  rewind($fp);
  ftruncate($fp, 0);
  $ok = fwrite($fp, json_encode(array_values($rows), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  fflush($fp);

  flock($fp, LOCK_UN);
  fclose($fp);
  return $ok !== false;
}

function load_signups(string $signupsFile): array {
  if (!file_exists($signupsFile)) return [];
  $raw = file_get_contents($signupsFile);
  $json = json_decode($raw ?: '', true);
  return is_array($json) ? $json : [];
}

function save_signups_locked(string $signupsFile, array $data): bool {
  $fp = fopen($signupsFile, 'c+');
  if (!$fp) return false;
  if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }

  rewind($fp);
  ftruncate($fp, 0);
  $ok = fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  fflush($fp);

  flock($fp, LOCK_UN);
  fclose($fp);
  return $ok !== false;
}

function schedule_map_slots(array $rows): array {
  $m = [];
  foreach ($rows as $r) $m[$r['id']] = (int)$r['slots'];
  return $m;
}

function csrf_token(): string {
  ensure_session();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}

function csrf_check(): bool {
  ensure_session();
  $t = $_POST['csrf'] ?? '';
  return isset($_SESSION['csrf']) && is_string($t) && hash_equals($_SESSION['csrf'], $t);
}

function require_access_code(): void {
  ensure_session();
  $cfg = cfg();
  if (!empty($_SESSION['access_granted'])) return;

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_code'])) {
    $code = trim((string)($_POST['access_code'] ?? ''));
    if ($code !== '' && hash_equals($cfg['access_code'], $code)) {
      $_SESSION['access_granted'] = true;
      header('Location: index.php');
      exit;
    }
  }

  $theme = $cfg['theme'];
  echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>Zugangscode</title>';
  echo '<style>
    body{font-family:system-ui,Arial;background:' . h($theme['background']) . ';margin:0;padding:16px;}
    .card{max-width:520px;margin:10vh auto;background:' . h($theme['card']) . ';border-radius:16px;padding:18px;border:1px solid ' . h($theme['line']) . ';}
    input{width:100%;padding:12px;border-radius:12px;border:1px solid #ddd;font-size:16px;}
    button{width:100%;margin-top:10px;padding:12px;border:none;border-radius:12px;background:' . h($theme['button']) . ';color:#fff;font-weight:800;font-size:16px;}
    .muted{color:#6b7280;font-size:13px;margin-top:10px;}
  </style></head><body>';
  echo '<div class="card"><h2 style="margin:0 0 10px">Zugangscode</h2>';
  echo '<form method="post"><input name="access_code" placeholder="Code eingeben" autocomplete="off" />';
  echo '<button type="submit">Weiter</button></form>';
  echo '<div class="muted">Bitte den Code vom Team verwenden.</div></div></body></html>';
  exit;
}

function is_admin(): bool {
  ensure_session();
  return !empty($_SESSION['admin']);
}

function require_admin(): void {
  ensure_session();
  if (is_admin()) return;

  $cfg = cfg();
  $theme = $cfg['theme'];
  $err = '';

  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_password'])) {
    $pw = (string)($_POST['admin_password'] ?? '');
    if (hash_equals($cfg['admin_password'], $pw)) {
      $_SESSION['admin'] = true;
      header('Location: admin.php');
      exit;
    }
    $err = 'Falsches Passwort.';
  }

  echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>Admin Login</title>';
  echo '<style>
    body{font-family:system-ui,Arial;background:' . h($theme['background']) . ';margin:0;padding:16px;}
    .card{max-width:520px;margin:10vh auto;background:' . h($theme['card']) . ';border-radius:16px;padding:18px;border:1px solid ' . h($theme['line']) . ';}
    input{width:100%;padding:12px;border-radius:12px;border:1px solid #ddd;font-size:16px;}
    button{width:100%;margin-top:10px;padding:12px;border:none;border-radius:12px;background:' . h($theme['button']) . ';color:#fff;font-weight:800;font-size:16px;}
    .err{background:' . h($theme['errBg']) . ';padding:10px 12px;border-radius:12px;margin-bottom:10px;font-weight:700;}
    .muted{color:#6b7280;font-size:13px;margin-top:10px;}
  </style></head><body>';
  echo '<div class="card"><h2 style="margin:0 0 10px">Admin Login</h2>';
  if ($err) echo '<div class="err">' . h($err) . '</div>';
  echo '<form method="post"><input type="password" name="admin_password" placeholder="Passwort" />';
  echo '<button type="submit">Login</button></form>';
  echo '<div class="muted"><a href="index.php">Zurück</a></div></div></body></html>';
  exit;
}

function normalize_name(string $name): string {
  $name = trim($name);
  $name = preg_replace('/\s+/u', ' ', $name);
  $name = strip_tags($name);
  return mb_substr($name, 0, 60);
}
