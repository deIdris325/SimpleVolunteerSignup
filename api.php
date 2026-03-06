<?php
declare(strict_types=1);
require __DIR__ . '/helpers.php';

ensure_session();
require_access_code();

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'signups' => load_signups(__DIR__ . '/signups.json'),
  'csrf' => csrf_token(),
], JSON_UNESCAPED_UNICODE);
