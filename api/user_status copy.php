<?php
require_once 'cnn.php';
header('Content-Type: application/json; charset=utf-8');

$ip = "notyety";


$device = isset($_GET['device_id']) ? $conn->real_escape_string($_GET['device_id']) : 'xxx123';
$ttl    = isset($_GET['ttl']) ? max(1, intval($_GET['ttl'])) : 30;
$new    = isset($_GET['new']) ? $_GET['new']:'';    //$NEW NOT USED , MAYBE later
$ip1     = isset($_GET['ip']) ? $_GET['ip']:'777';    

if (substr($device,0,3)=='xxx'){
$device=substr($device,3);
$sql = "insert into wp_city_users set device_id='$device',icon='pegman_red.png' ,name='new',ip='$ip'";
$res = $conn->query($sql);

} 


$sql = "SELECT device_id, name, own_email, icon, lat, lon, first_action
        FROM wp_city_users
        WHERE device_id='$device' AND id>0
        LIMIT 1";
$res = $conn->query($sql);
if (!$res || !($row = $res->fetch_assoc())) { echo json_encode(['ok'=>false,'error'=>'not found']);
  
  
  exit; 
}

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

function get_client_ip(): ?string {
    $keys = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR', // kan meerdere IPs bevatten
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]); // eerste IP
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return null;
}
