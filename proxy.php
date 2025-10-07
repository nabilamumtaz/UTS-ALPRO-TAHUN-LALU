<?php
// proxy.php — JSON relay dengan allowlist + fallback tanpa cURL

// ====== KONFIGURASI ======
$allowed = [
  'http://mimik.cyou/alpro/',
  'https://mimik.cyou/alpro/',
  'http://localhost/UTS_Alpro_Endpoint_Table_with_proxy/alpro.json',
];
// =========================

$url = isset($_GET['url']) ? trim($_GET['url']) : '';

/** Validasi URL terhadap allowlist */
$ok = false;
foreach ($allowed as $a) {
  if (stripos($url, $a) === 0) { $ok = true; break; }
}
if (!$ok) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'URL tidak diizinkan', 'detail' => $url], JSON_UNESCAPED_UNICODE);
  exit;
}

/** Ambil konten JSON (pakai cURL jika ada; jika tidak, fallback ke file_get_contents) */
function fetch_json($url) {
  // 1) cURL tersedia?
  if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt_array($ch, [
      CURLOPT_URL            => $url,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 8,
      CURLOPT_TIMEOUT        => 15,
      CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return [null, $err ?: 'cURL error', $code ?: 502];
    return [$body, null, $code ?: 200];
  }

  // 2) Fallback: file_get_contents
  if (!ini_get('allow_url_fopen')) {
    return [null, 'allow_url_fopen=Off', 500];
  }
  $ctx = stream_context_create([
    'http' => [
      'method'  => 'GET',
      'timeout' => 15,
      'header'  => "Accept: application/json\r\n",
    ],
  ]);
  $body = @file_get_contents($url, false, $ctx);
  if ($body === false) return [null, 'gagal via fopen', 502];

  $code = 200;
  global $http_response_header;
  if (isset($http_response_header) && is_array($http_response_header)) {
    foreach ($http_response_header as $h) {
      if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int) $m[1]; break; }
    }
  }
  return [$body, null, $code];
}

list($body, $err, $code) = fetch_json($url);
if ($body === null) {
  http_response_code($code ?: 502);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Gagal mengambil dari server asal', 'detail' => $err], JSON_UNESCAPED_UNICODE);
  exit;
}

// Pastikan respon JSON valid
json_decode($body);
if (json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(502);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'error'  => 'Respon bukan JSON yang valid',
    'detail' => substr($body, 0, 200) . '...'
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

// Sukses
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
http_response_code($code ?: 200);
echo $body;
exit;
