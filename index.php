<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

ensure_session();
//require_access_code();

$scheduleFile = __DIR__ . '/schedule.json';
$signupsFile  = __DIR__ . '/signups.json';

$cfg = cfg();
$texts = load_text_settings();
$rows = schedule_rows($scheduleFile);
$signups = load_signups($signupsFile);
$csrf = csrf_token();
$msg = (string)($_GET['msg'] ?? '');

function stats(array $row, array $signups): array {
  $id = $row['id'];
  $all = $signups[$id] ?? [];
  if (!is_array($all)) $all = [];

  $slots = (int)($row['slots'] ?? 0);
  $reserveMax = 5;

  $confirmed = array_slice($all, 0, $slots);
  $reserve   = array_slice($all, $slots, $reserveMax);

  $confirmedCount = count($confirmed);
  $reserveCount   = count($reserve);

  $free = max(0, $slots - $confirmedCount);
  $reserveFree = max(0, $reserveMax - $reserveCount);

  // state: frei -> reserve -> voll
  $state = ($free > 0) ? 'frei' : (($reserveFree > 0) ? 'reserve' : 'voll');

  return [$confirmed, $reserve, $confirmedCount, $reserveCount, $free, $reserveFree, $state];
}
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="style.css">
<title><?= h($texts['site_name']) ?></title>

<!-- Kleine UI-Upgrades nur für diese Seite (bleibt Blau-Theme) -->
<style>
  :root{
    --blue:#2563eb;
    --blue2:#1d4ed8;
    --bg:#eff6ff;
    --text:#0f172a;
    --muted:#475569;
    --line:#e2e8f0;
    --shadow:0 8px 30px rgba(2,6,23,.10);
  }

  /* Header (Topbar) */
  .topbar{
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
    padding:16px 16px;
    border-radius:18px;
    background: linear-gradient(135deg, var(--blue), #3b82f6);
    color:white;
    box-shadow: var(--shadow);
    margin-bottom:14px;
  }
  .topbar h1{ margin:0; font-size:22px; letter-spacing:-0.02em; }
  .topbar .sub{ margin:6px 0 0; opacity:.9; }
  .headerActions{ display:flex; gap:10px; }

  /* Links als Buttons (override, falls style.css anderes setzt) */
  .linkBtn{
    text-decoration:none;
    background: rgba(255,255,255,.18);
    color:white;
    padding:9px 14px;
    border-radius:12px;
    font-weight:700;
    border:1px solid rgba(255,255,255,.25);
    display:inline-flex;
    align-items:center;
    gap:8px;
  }
  .linkBtn:hover{ background: rgba(255,255,255,.26); }
  .linkBtn.ghost{ background: rgba(255,255,255,.10); }

  /* Karten/Wrapper */
  .shadow{ box-shadow: var(--shadow); }
  .tableWrap{ border-radius:18px; overflow:auto; }

  /* Tabelle: Sticky Header + Zebra */
  table{ border-radius:18px; overflow:hidden; }
  thead th{
    position: sticky;
    top: 0;
    z-index: 2;
    background: linear-gradient(135deg, var(--blue), var(--blue2));
  }
  td, th{ padding:14px 12px; }
  tbody tr:nth-child(even){ background:#f8fafc; }
  tbody tr:hover{ background:#eef2ff; }

  /* Pills: Status-Zahlen */
  .pill{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:34px;
    height:28px;
    padding:0 10px;
    border-radius:999px;
    font-weight:800;
    font-size:13px;
    border:1px solid transparent;
    background:#dbeafe;
  }
  .pill.ok{
    background:#dcfce7;
    color:#166534;
    border-color:#bbf7d0;
  }
  .pill.bad{
    background:#fee2e2;
    color:#7f1d1d;
    border-color:#fecaca;
  }

  .pill.warn{
    background:#fef9c3;
    color:#854d0e;
    border-color:#fde68a;
  }

  /* <?= h($texts['remove_button_label']) ?>: Secondary */
  button.secondary{
    background:#e0f2fe;
    color:#0c4a6e;
    border:1px solid #bae6fd;
    border-radius:10px;
    padding:6px 10px;
    font-weight:800;
  }
  button.secondary:hover{ background:#bae6fd; }

  /* Inputs in Inline-Form */
  .inline input[type="text"]{ min-width: 160px; }

  /* Mobile Card Markup (deine Klassen) */
  .card .title{ margin:0; font-weight:900; font-size:16px; }
  .card .meta{ margin:3px 0 0; color:var(--muted); font-size:13px; }
  .grid{
    display:grid;
    grid-template-columns: 1fr 1fr;
    gap:10px;
    margin-top:12px;
  }
  .box{
    background:#f1f5f9;
    border:1px solid #e2e8f0;
    padding:10px;
    border-radius:14px;
  }
  .boxLabel{ margin:0; font-size:12px; color:var(--muted); }
  .boxValue{ margin:4px 0 0; font-size:16px; font-weight:900; }
  .fullWidth{ grid-column: 1 / -1; }

  @media(max-width:700px){
    .inline.mobile{ flex-direction:column; align-items:stretch; }
  }
</style>
</head>

<body>
<div class="wrap">

  <header class="topbar">
    <div>
      <h1><?= h($texts['page_title']) ?></h1>
      <p class="sub"><?= h($texts['page_subtitle']) ?></p>
    </div>
    <nav class="headerActions" aria-label="Seitenlinks">
      <a class="linkBtn ghost" href="export.php"><?= h($texts['export_label']) ?></a>
      <a class="linkBtn" href="admin.php"><?= h($texts['admin_label']) ?></a>
    </nav>
  </header>

  <?php if ($msg==='ok'): ?><div class="note ok">✅ <?= h($texts['entry_saved_message']) ?></div><?php endif; ?>
  <?php if ($msg==='reserve'): ?><div class="note" style="background:#fef9c3;border:1px solid #fde68a;color:#854d0e">🟡 <?= h($texts['reserve_message']) ?></div><?php endif; ?>
  <?php if ($msg==='full'): ?><div class="note err">❌ <?= h($texts['full_message']) ?></div><?php endif; ?>
  <?php if ($msg==='bad'): ?><div class="note err">❌ <?= h($texts['invalid_name_message']) ?></div><?php endif; ?>
  <?php if ($msg==='csrf'): ?><div class="note err">❌ <?= h($texts['csrf_message']) ?></div><?php endif; ?>
  <?php if ($msg==='err'): ?><div class="note err">❌ <?= h($texts['generic_error_message']) ?></div><?php endif; ?>

  <div class="tableWrap shadow" aria-label="Tabelle der Dienste">
    <table>
      <thead>
        <tr>
          <th><?= h($texts['table_date_label']) ?></th>
          <th><?= h($texts['table_day_label']) ?></th>
          <th><?= h($texts['table_task_label']) ?></th>
          <th class="right"><?= h($texts['table_slots_label']) ?></th>
          <th><?= h($texts['table_names_label']) ?></th>
          <th class="right"><?= h($texts['table_count_label']) ?></th>
          <th class="right"><?= h($texts['table_free_label']) ?></th>
          <th><?= h($texts['table_signup_label']) ?></th>
        </tr>
      </thead>

      <tbody>
      <?php foreach($rows as $row):
        [$confirmed,$reserve,$confirmedCount,$reserveCount,$free,$reserveFree,$state] = stats($row,$signups);
        $closed = ($state==='voll');
      ?>
        <tr data-id="<?= h($row['id']) ?>" data-slots="<?= (int)$row['slots'] ?>">
          <td><?= h($row['date']) ?></td>
          <td><?= h($row['day']) ?></td>
          <td><strong><?= h($row['task']) ?></strong></td>
          <td class="right"><?= (int)$row['slots'] ?></td>

          <td class="nameList" data-field="names">
            <?php if(!$confirmedCount && !$reserveCount): ?><span style="color:var(--muted)"><?= h($texts['empty_names_label']) ?></span><?php else: ?>
              <?php foreach($confirmed as $nm): ?>
                <span class="nameChip">
                  <span><?= h((string)$nm) ?></span>
                  <form action="remove.php" method="post" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= h($row['id']) ?>">
                    <input type="hidden" name="name" value="<?= h((string)$nm) ?>">
                    <button type="submit" class="secondary"><?= h($texts['remove_button_label']) ?></button>
                  </form>
                </span>
              
              <?php endforeach; ?>
              <?php foreach($reserve as $nm): ?>
                <span class="nameChip" style="background:#fef9c3;border:1px solid #fde68a;">
                  <span><?= h((string)$nm) ?> (<?= h($texts['reserve_label']) ?>)</span>
                  <form action="remove.php" method="post" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= h($row['id']) ?>">
                    <input type="hidden" name="name" value="<?= h((string)$nm) ?>">
                    <button type="submit" class="secondary"><?= h($texts['remove_button_label']) ?></button>
                  </form>
                </span>
              <?php endforeach; ?>
            <?php endif; ?>
          </td>

          <td class="right">
            <span class="pill <?= $state==='voll'?'ok':($state==='reserve'?'warn':'bad') ?>" data-field="count"><?= $confirmedCount ?></span>
            <div style="font-size:12px;color:var(--muted);margin-top:4px" data-field="reserve"><?= h($texts['reserve_label']) ?>: <?= $reserveCount ?>/5</div>
          </td>
          <td class="right">
            <span class="pill <?= $state==='voll'?'ok':($state==='reserve'?'warn':'bad') ?>" data-field="free"><?= $free ?></span>
          </td>

          <td>
            <form class="inline" action="save.php" method="post">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="id" value="<?= h($row['id']) ?>">
              <input type="text" name="name" placeholder="<?= h($texts['name_placeholder']) ?>" maxlength="60" autocomplete="name" <?= $closed?'disabled':'' ?>>
              <button type="submit" <?= $closed?'disabled':'' ?>><?= h($texts['signup_button_label']) ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if(!count($rows)): ?>
        <tr><td colspan="8" style="color:#b00;">Keine Daten in schedule.json</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Mobile Cards -->
  <div class="cards" id="cards" aria-label="<?= h($texts['mobile_view_label']) ?>">
    <?php foreach($rows as $row):
      [$confirmed,$reserve,$confirmedCount,$reserveCount,$free,$reserveFree,$state] = stats($row,$signups);
      $closed = ($state==='voll');
    ?>
    <div class="card shadow" data-id="<?= h($row['id']) ?>" data-slots="<?= (int)$row['slots'] ?>">
      <div class="cardTop">
        <div>
          <p class="title"><?= h($row['task']) ?></p>
          <p class="meta"><?= h($row['day'].', '.$row['date']) ?></p>
        </div>
        <span class="pill <?= $state==='voll'?'ok':($state==='reserve'?'warn':'bad') ?>" data-field="status"><?= $state==='voll'?h($texts['status_full_label']):($state==='reserve'?h($texts['status_reserve_label']):h($texts['status_free_label'])) ?></span>
      </div>

      <div class="grid">
        <div class="box">
          <p class="boxLabel"><?= h($texts['table_slots_label']) ?></p>
          <p class="boxValue"><?= (int)$row['slots'] ?></p>
        </div>
        <div class="box">
          <p class="boxLabel"><?= h($texts['table_count_label']) ?></p>
          <p class="boxValue" data-field="count"><?= $confirmedCount ?></p>
          <p style="margin:6px 0 0;color:var(--muted);font-size:12px" data-field="reserve"><?= h($texts['reserve_label']) ?>: <?= $reserveCount ?>/5</p>
        </div>
        <div class="box">
          <p class="boxLabel"><?= h($texts['table_free_label']) ?></p>
          <p class="boxValue" data-field="free"><?= $free ?></p>
        </div>

        <div class="box fullWidth">
          <p class="boxLabel"><?= h($texts['table_names_label']) ?></p>
          <div class="nameList" data-field="names">
            <?php if(!$confirmedCount && !$reserveCount): ?><span style="color:var(--muted)"><?= h($texts['empty_names_label']) ?></span><?php else: ?>
              <?php foreach($confirmed as $nm): ?>
                <span class="nameChip">
                  <span><?= h((string)$nm) ?></span>
                  <form action="remove.php" method="post" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= h($row['id']) ?>">
                    <input type="hidden" name="name" value="<?= h((string)$nm) ?>">
                    <button type="submit" class="secondary"><?= h($texts['remove_button_label']) ?></button>
                  </form>
                </span>
              <?php endforeach; ?>
              <?php foreach($reserve as $nm): ?>
                <span class="nameChip" style="background:#fef9c3;border:1px solid #fde68a;">
                  <span><?= h((string)$nm) ?> (<?= h($texts['reserve_label']) ?>)</span>
                  <form action="remove.php" method="post" style="margin:0">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="id" value="<?= h($row['id']) ?>">
                    <input type="hidden" name="name" value="<?= h((string)$nm) ?>">
                    <button type="submit" class="secondary"><?= h($texts['remove_button_label']) ?></button>
                  </form>
                </span>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="fullWidth">
          <form class="inline mobile" action="save.php" method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="id" value="<?= h($row['id']) ?>">
            <input type="text" name="name" placeholder="<?= h($texts['name_placeholder']) ?>" maxlength="60" autocomplete="name" <?= $closed?'disabled':'' ?>>
            <button type="submit" <?= $closed?'disabled':'' ?>><?= h($texts['signup_button_label']) ?></button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

</div>

<script>
(() => {
  const refreshMs = <?= (int)($cfg['live_refresh_ms'] ?? 4000) ?>;
  const reserveMax = 5;
  const labels = {
    remove: <?= json_encode($texts['remove_button_label'], JSON_UNESCAPED_UNICODE) ?>,
    reserve: <?= json_encode($texts['reserve_label'], JSON_UNESCAPED_UNICODE) ?>,
    empty: <?= json_encode($texts['empty_names_label'], JSON_UNESCAPED_UNICODE) ?>,
    full: <?= json_encode($texts['status_full_label'], JSON_UNESCAPED_UNICODE) ?>,
    reserveStatus: <?= json_encode($texts['status_reserve_label'], JSON_UNESCAPED_UNICODE) ?>,
    free: <?= json_encode($texts['status_free_label'], JSON_UNESCAPED_UNICODE) ?>
  };

  function esc(s){
    return String(s).replace(/[&<>"']/g,c=>({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#039;" }[c]));
  }

  function renderNames(id,names,slots,csrf,labels){
    if(!names || !names.length) return '<span style="color:var(--muted)">'+esc(labels.empty)+'</span>';
    return names.map((n, idx)=>{
      const isReserve = idx >= slots;
      const display = isReserve ? `${esc(n)} (${esc(labels.reserve)})` : esc(n);
      const style = isReserve ? ' style="background:#fef9c3;border:1px solid #fde68a;"' : '';
      return `
      <span class="nameChip"${style}>
        <span>${display}</span>
        <form action="remove.php" method="post" style="margin:0">
          <input type="hidden" name="csrf" value="${esc(csrf)}">
          <input type="hidden" name="id" value="${esc(id)}">
          <input type="hidden" name="name" value="${esc(n)}">
          <button type="submit" class="secondary">${esc(labels.remove)}</button>
        </form>
      </span>`;
    }).join('');
  }

  async function tick(){
    try{
      const r = await fetch('api.php',{cache:'no-store'});
      const p = await r.json();
      const signups = p.signups || {};
      const csrf = p.csrf || '';

      document.querySelectorAll('[data-id]').forEach(el=>{
        const id = el.getAttribute('data-id');
        const slots = parseInt(el.getAttribute('data-slots')||'0',10);
        const names = Array.isArray(signups[id]) ? signups[id] : [];
        const confirmedCount = Math.min(names.length, slots);
        const reserveCount = Math.max(0, Math.min(reserveMax, names.length - slots));
        const free = Math.max(0, slots - confirmedCount);
        const state = (free > 0) ? 'frei' : ((reserveCount < reserveMax) ? 'reserve' : 'voll');
        const closed = state === 'voll';

        const namesEl = el.querySelector('[data-field="names"]');
        if(namesEl) namesEl.innerHTML = renderNames(id,names,slots,csrf,labels);

        const countEl = el.querySelector('[data-field="count"]');
        if(countEl){
          countEl.textContent = String(confirmedCount);
          countEl.classList.toggle('ok', state==='voll');
          countEl.classList.toggle('warn', state==='reserve');
          countEl.classList.toggle('bad', state==='frei');
        }

        const reserveEl = el.querySelector('[data-field="reserve"]');
        if(reserveEl){ reserveEl.textContent = `${labels.reserve}: ${reserveCount}/${reserveMax}`; }

        const freeEl = el.querySelector('[data-field="free"]');
        if(freeEl){
          freeEl.textContent = String(free);
          freeEl.classList.toggle('ok', state==='voll');
          freeEl.classList.toggle('warn', state==='reserve');
          freeEl.classList.toggle('bad', state==='frei');
        }

        const statusEl = el.querySelector('[data-field="status"]');
        if(statusEl){
          statusEl.textContent = state==='voll' ? labels.full : (state==='reserve' ? labels.reserveStatus : labels.free);
          statusEl.classList.toggle('ok', state==='voll');
          statusEl.classList.toggle('warn', state==='reserve');
          statusEl.classList.toggle('bad', state==='frei');
        }

        const input = el.querySelector('input[name="name"]');
        const btn = el.querySelector('button[type="submit"]');
        if(input && btn){ input.disabled = closed; btn.disabled = closed; }
      });
    }catch(e){}
  }

  setInterval(tick, refreshMs);
})();
</script>
</body>
</html>
