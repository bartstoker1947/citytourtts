<?php
// Toon fouten in de browser
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'cnn.php';
header('Content-Type: application/json; charset=utf-8');

// IP ophalen via functie (zie onderaan)
$ip = get_client_ip() ?? 'unknown';

// Parameters uit de URL
$device = isset($_GET['device_id']) ? $conn->real_escape_string($_GET['device_id']) : 'id-mh4t66br-lqnbl9atu';
$ttl    = isset($_GET['ttl']) ? max(1, intval($_GET['ttl'])) : 30;
$new    = isset($_GET['new']) ? $_GET['new'] : '';
$ip1    = isset($_GET['ip']) ? $_GET['ip'] : '777';

// === INSERT ALS DEVICE BEGINT MET 'xxx' ===
if (substr($device, 0, 3) == 'xxx') {
    $device = substr($device, 3);

    $sql = "INSERT INTO clw18od8u_hn4275.wp_city_users
            (device_id, icon, name, ip)
            VALUES (?, 'pegman_red.png', 'new', ?)";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die(json_encode(['ok'=>false, 'error'=>'prepare failed: '.$conn->error]));
    }

    $stmt->bind_param('ss', $device, $ip);
    if (!$stmt->execute()) {
        die(json_encode(value: ['ok'=>false, 'error'=>'execute failed: '.$stmt->error]));
    }
    $stmt->close();
}

// === GEBRUIKER OPVRAGEN ===
$sql = "SELECT device_id, name, own_email, icon, lat, lon, first_action, step_html 
        FROM wp_city_users
        WHERE device_id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die(json_encode(['ok'=>false, 'error'=>'prepare failed: '.$conn->error]));
}

$stmt->bind_param('s', $device);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || !($row = $res->fetch_assoc())) {
    echo json_encode(['ok'=>false, 'error'=>'not found']);
    exit;
}
$stmt->close();

// === DATA BEREKENINGEN ===
$ts    = $row['first_action'] ? strtotime($row['first_action']) : 0;
$fresh = $ts ? (time() - $ts <= $ttl) : false;

// === ICON URL ===
$iconFile = trim($row['icon'] ?? '');
$iconUrl  = '';
if ($iconFile !== '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'];
    $iconUrl = "$scheme://$host/wp-content/uploads/icon_images/" . rawurlencode($iconFile);
}

// === JSON OUTPUT ===
echo json_encode([
    'ok'           => true,
    'device_id'    => $row['device_id'],
    'name'         => $row['name'],
    'own_email'    => $row['own_email'],
    'icon'         => $row['icon'],
    'icon_url'     => $iconUrl,
    'lat'          => (float)$row['lat'],
    'lon'          => (float)$row['lon'],
    'first_action' => $row['first_action'],
    'step_html' => $row['step_html'],
    'fresh'        => $fresh,
    'age_sec'      => $ts ? time() - $ts : null
    

]);

// === FUNCTIE VOOR IP ===
function get_client_ip(): ?string {
    $keys = [
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return null;
}
?>
