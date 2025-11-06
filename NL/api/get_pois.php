<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/cnn.php';

$routenaam = isset($_GET['routenaam']) ? $_GET['routenaam'] : 'sesam-ah-kapper';
//$routenaam = isset($_GET['routenaam']) ? $_GET['routenaam'] : 'cityamsterdam';


if ($routenaam === '') { http_response_code(400); echo json_encode(['error'=>true,'message'=>'routenaam is vereist']); exit; }

function fetch_all($conn, $sql, $params) {
  $stmt = $conn->prepare($sql);
  if (!$stmt) return [];
  $types = str_repeat('s', count($params));
  if ($types) $stmt->bind_param($types, ...$params);
  if (!$stmt->execute()) return [];
  $res = $stmt->get_result();
  $rows = [];
  while ($row = $res->fetch_assoc()) $rows[] = $row;
  $stmt->close();
  return $rows;
}

/* 1) BASIS-TABEL EERST (wp_mijn_routes) → alleen echte POI’s (naam <> '') */
$rows = fetch_all($conn,
  "SELECT naam, latitude, longitude , icon
     FROM wp_mijn_routes
    WHERE routenaam = ? AND naam <> ''
 ORDER BY id ASC",
  [$routenaam]
);




/* 2) FALLBACK: SIM (alleen als base niets oplevert) */
if (!$rows) {
  $rows = fetch_all($conn,
    "SELECT seq AS poi_index, naam, latitude, longitude,icon
       FROM wp_mijn_routes
      WHERE routenaam = ? AND naam <> ''
   ORDER BY seq ASC",
    [$routenaam]
  );
}

/* 3) NORMALISEER OUTPUT
      - Base: alleen {name,lat,lng}
      - Sim-fallback: {index,name,lat,lng}  */
$out = [];
foreach ($rows as $r) {
  $item = [
    'name' => strval($r['naam']),
    'lat'  => isset($r['latitude'])  ? floatval($r['latitude'])  : null,
    'lng'  => isset($r['longitude']) ? floatval($r['longitude']) : null,
    'icon' => strval($r['icon'])

  ];
  if (isset($r['poi_index'])) $item['index'] = intval($r['poi_index']);
  $out[] = $item;
  
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
