<?php
require_once 'cnn.php';
header('Content-Type: application/json; charset=utf-8');

$device = isset($_GET['device_id']) ? $conn->real_escape_string($_GET['device_id']) : '';
$ttl    = isset($_GET['ttl']) ? max(1, intval($_GET['ttl'])) : 30;
if(!$device){
$device='id-mee6958o-m5gofa3bx';
}


//if ($device === '') { echo json_encode(['ok'=>false,'error'=>'missing device_id']); exit; }

$sql = "SELECT device_id, name, own_email, icon, lat, lon, first_action
        FROM wp_city_users
        WHERE device_id='$device' AND id>0
        LIMIT 1";
$res = $conn->query($sql);
if (!$res || !($row = $res->fetch_assoc())) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }

$ts    = $row['first_action'] ? strtotime($row['first_action']) : 0;
$fresh = $ts ? (time() - $ts <= $ttl) : false;

$iconFile = trim($row['icon'] ?? '');
$iconUrl  = '';
if ($iconFile !== '') {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'];
  // Volledige URL naar jouw icon-map
  $iconFile = trim($row['icon'] ?? '');                 // bijv. 'pegman_red.png'
$iconUrl  = $iconFile ? "$scheme://$host/wp-content/uploads/icon_images/".rawurlencode($iconFile) : '';
}



echo json_encode([
  'ok'           => true,
  'device_id'    => $row['device_id'],
  'name'         => $row['name'],
  'own_email'    => $row['own_email'],
  'icon'         => $row['icon'],
  'icon_url'     => $iconUrl,          // volledige URL (nieuw)
  'lat'          => (float)$row['lat'],
  'lon'          => (float)$row['lon'],
  'first_action' => $row['first_action'],
  'fresh'        => $fresh,
  'age_sec'      => $ts ? time() - $ts : null
]);
