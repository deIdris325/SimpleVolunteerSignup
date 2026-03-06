<?php
// volunteers/config.php
return [
  // Admin login password (change this!)
  'admin_password' => 'initpasswort',

  // Access code for volunteers (change this!)
  'access_code' => 'initpasswort',

  // Theme (neutral colors: black, blue, green)
  'theme' => [
    'background' => '#f5f7fa',   // sehr helles Grau (neutraler Hintergrund)
    'button'     => '#1d4ed8',   // kräftiges Blau
    'card'       => '#ffffff',   // Weiß
    'tableHead'  => '#e2e8f0',   // dezentes Grau-Blau
    'line'       => '#cbd5e1',   // neutrale Linienfarbe
    'okBg'       => '#dcfce7',   // sanftes Grün für Erfolg
    'errBg'      => '#fee2e2',   // dezentes Rot für Fehler (neutral, nicht pink)
  ],

  // Live refresh interval in ms (client-side)
  'live_refresh_ms' => 4000,
];