<?php
/**
 * gps-filler.php — Standalone GPS generator (SIM) + Diagnostics
 * Endpoints (GET/POST/CLI):
 *  - getUsers         -> JSON: [{device_id, own_email}]
 *  - getRoutes        -> JSON: [{value, label}]  (routenaam)
 *  - startSim  (POST) -> JSON/TXT; body: {users:[], route:"<routenaam>", delay:<int>}
 *  - stopSim          -> TXT
 *  - runLoop (CLI)    -> long-running; gebruikt gps-sim.json (state/heartbeat)
 *  - diagnostics      -> JSON met paden, state, heartbeat, point-counts, users-status
 */

include 'api/cnn.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, POST');

$stateFile = __DIR__ . '/gps-sim.json';

/* -------- helpers -------- */
function send_json($data, $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
}
function now_iso() { return gmdate('c'); }

/* -------- action parser (GET/CLI, case-insensitive) -------- */
$action = $_GET['action'] ?? null;
if (PHP_SAPI === 'cli') {
  foreach (array_slice($argv, 1) as $arg) {
    if (stripos($arg, 'action=') === 0) { $action = substr($arg, 7); }
    else { $action = $arg; }
  }
}
$action = strtolower(trim((string)$action));

switch ($action) {
    case 'set': { // pauzeer bij POI aan/uit
  header('Content-Type: application/json; charset=utf-8');
  $state_path = $state_path ?? __DIR__.'/gps-sim.json';
  $pause = isset($_GET['pause']) ? (int)$_GET['pause'] : 0;
  $state = file_exists($state_path) ? json_decode(file_get_contents($state_path), true) : [];
  $state['pause'] = $pause ? 1 : 0;   // 1 = pauzeren op segmentgrens
  if (!isset($state['hold'])) $state['hold'] = 0;
  file_put_contents($state_path, json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  echo json_encode(['ok'=>true,'state'=>$state]);
  break;
}

case 'next': { // handmatig één stap/volgend segment
  header('Content-Type: application/json; charset=utf-8');
  $state_path = $state_path ?? __DIR__.'/gps-sim.json';
  $state = file_exists($state_path) ? json_decode(file_get_contents($state_path), true) : [];
  $state['hold'] = 0; // hef pauze op
  $state['last_index'] = isset($state['last_index']) ? ((int)$state['last_index'] + 1) : 0;
  file_put_contents($state_path, json_encode($state, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
  echo json_encode(['ok'=>true,'state'=>$state]);
  break;
}

  /* -------------------- DATA: USERS -------------------- */
  case 'getusers': {
    $sql = "SELECT device_id, COALESCE(own_email, device_id) AS own_email
            FROM wp_city_users
            ORDER BY own_email ASC";
    $res = $conn->query($sql);
    if (!$res) send_json(['error' => 'DB error: '.$conn->error], 500);
    send_json($res->fetch_all(MYSQLI_ASSOC));
  }

  /* -------------------- DATA: ROUTES -------------------- */
  case 'getroutes': {
    $sql = "SELECT DISTINCT routenaam AS value
            FROM wp_mijn_routes
            WHERE routenaam IS NOT NULL AND routenaam <> ''
            ORDER BY routenaam ASC";
    $res = $conn->query($sql);
    if (!$res) send_json(['error' => 'DB error: '.$conn->error], 500);
    $out = [];
    while ($r = $res->fetch_assoc()) $out[] = ['value' => $r['value'], 'label' => $r['value']];
    send_json($out);
  }

  /* -------------------- START SIM -------------------- */
  case 'startsim': {
    $payload = json_decode(file_get_contents('php://input'), true);
    $users   = $payload['users'] ?? [];
    $route   = trim($payload['route'] ?? '');
    $delay   = max(1, intval($payload['delay'] ?? 5));

    if (!$users || !$route) {
      send_json(['status' => 'error', 'message' => 'Selecteer minimaal één user en een route.'], 400);
    }

    $state = [
      'users'         => array_values($users),
      'route'         => $route,
      'delay'         => $delay,
      'running'       => true,
      // heartbeat:
      'last_index'    => 0,
      'last_tick_iso' => null
    ];
    if (file_put_contents($GLOBALS['stateFile'], json_encode($state, JSON_PRETTY_PRINT)) === false) {
      send_json(['status' => 'error', 'message' => 'Kon gps-sim.json niet schrijven (bestandsrechten).'], 500);
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo "Simulatie gestart: route='{$route}', delay={$delay}s, users=".implode(',', $users);
    exit;
  }

  /* -------------------- STOP SIM -------------------- */
  case 'stopsim': {
    if (file_exists($stateFile)) {
      $state = json_decode(@file_get_contents($stateFile), true) ?: [];
      $state['running'] = false;
      file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo "Simulatie gestopt.";
    exit;
  }

  /* -------------------- RUN LOOP (CLI) -------------------- */
  case 'runloop': {
    ignore_user_abort(true);
    set_time_limit(0);

    if (!file_exists($stateFile)) {
      header('Content-Type: text/plain; charset=utf-8');
      echo "gps-sim.json ontbreekt. Start eerst via de webpagina (Start).";
      exit;
    }
    $state = json_decode(file_get_contents($stateFile), true);
    if (empty($state['running'])) {
      header('Content-Type: text/plain; charset=utf-8');
      echo "State running=false. Start eerst via de webpagina.";
      exit;
    }

    $routeName = $conn->real_escape_string($state['route']);
    $users     = $state['users'];
    $delay     = max(1, intval($state['delay']));

    // SIM-punten (gewenst)
    $points = [];
    $q1 = "SELECT latitude, longitude
           FROM wp_mijn_routes_sim
           WHERE routenaam = '$routeName'
           ORDER BY seq ASC";
    if ($res = $conn->query($q1)) while ($row = $res->fetch_assoc()) $points[] = $row;

    // Fallback: basis-POI
    if (!$points) {
      $q2 = "SELECT latitude, longitude
             FROM wp_mijn_routes
             WHERE routenaam = '$routeName'
             ORDER BY id ASC";
      if ($res2 = $conn->query($q2)) while ($row = $res2->fetch_assoc()) $points[] = $row;
    }

    if (!$points) {
      header('Content-Type: text/plain; charset=utf-8');
      echo "Geen punten gevonden voor routenaam: {$state['route']} (SIM en fallback leeg).";
      exit;
    }

    $index = isset($state['last_index']) ? (int)$state['last_index'] : 0;
    $nPts  = count($points);

    if (PHP_SAPI === 'cli') {
      fwrite(STDOUT, "RUNNING route='{$state['route']}' delay={$delay}s users=".implode(',', $users)." points={$nPts}\n");
    }

    while (true) {
      // herlaad state zodat Stop werkt
      $s = json_decode(@file_get_contents($stateFile), true);
      if (empty($s['running'])) break;

      $p   = $points[$index];
      $lat = (string)$p['latitude'];
      $lon = (string)$p['longitude'];

      // --- UPDATE alle gekozen users (zonder prepared; met escape & jouw id>0 filter)
      $total = 0;
      foreach ($users as $device_id) {
        $latv = sprintf('%.6f', (float)$lat);
        $lonv = sprintf('%.6f', (float)$lon);
        $dev  = $conn->real_escape_string($device_id);

        $sql  = "UPDATE wp_city_users
                 SET lat='{$latv}', lon='{$lonv},step_html='instructie van bart'
                 WHERE device_id='{$dev}' AND id>0";
        if (!$conn->query($sql)) {
          
          if (PHP_SAPI === 'cli') fwrite(STDERR, "SQL error: {$conn->error}\n");
        } else {
          $total += $conn->affected_rows;
        }
      }

      // --- HEARTBEAT wegschrijven
      $s['last_index']    = $index;
      $s['last_tick_iso'] = now_iso();
      file_put_contents($stateFile, json_encode($s, JSON_PRETTY_PRINT));

      // --- logging naar console
      if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, sprintf("[%s] idx=%d lat=%s lon=%s updated=%d rows\n",
          date('H:i:s'), $index, $lat, $lon, $total));
      }

      // volgende punt + wachten
      $index = ($index + 1) % $nPts;
      sleep($delay);
    }

    if (PHP_SAPI === 'cli') fwrite(STDOUT, "STOPPED\n");
    else { header('Content-Type: text/plain; charset=utf-8'); echo "Stopped"; }
    exit;
  }

  /* -------------------- DIAGNOSTICS -------------------- */
  case 'diagnostics': {
    $diag = [
      'script_dir' => __DIR__,
      'cwd'        => getcwd(),
      'php_sapi'   => PHP_SAPI,
      'now_iso'    => now_iso(),
      'state_path' => $stateFile,
      'state_exists' => file_exists($stateFile) ? true : false,
      'state'      => null,
      'points'     => ['sim_count' => null, 'base_count' => null, 'route' => null],
      'users'      => [],
      'errors'     => []
    ];

    // state
    if (file_exists($stateFile)) {
      $state = json_decode(@file_get_contents($stateFile), true);
      $diag['state'] = $state ?: null;
      if (!empty($state['route'])) $diag['points']['route'] = $state['route'];

      // count points for current route
      if (!empty($state['route'])) {
        $routeName = $conn->real_escape_string($state['route']);

        $qSim = "SELECT COUNT(*) AS c FROM wp_mijn_routes_sim WHERE routenaam='$routeName'";
        $rSim = $conn->query($qSim);
        $diag['points']['sim_count']  = $rSim ? (int)$rSim->fetch_assoc()['c'] : null;
        if (!$rSim) $diag['errors'][] = 'SIM count: '.$conn->error;

        $qBase = "SELECT COUNT(*) AS c FROM wp_mijn_routes WHERE routenaam='$routeName'";
        $rBase = $conn->query($qBase);
        $diag['points']['base_count'] = $rBase ? (int)$rBase->fetch_assoc()['c'] : null;
        if (!$rBase) $diag['errors'][] = 'BASE count: '.$conn->error;
      }

      // user status (lat/lon/updated_at/age)
      if (!empty($state['users']) && is_array($state['users'])) {
        // check of updated_at kolom bestaat
        $hasUpdatedAt = false;
        if ($rCol = $conn->query("SHOW COLUMNS FROM wp_city_users LIKE 'updated_at'")) {
          $hasUpdatedAt = (bool)$rCol->num_rows;
        }

        foreach ($state['users'] as $dev) {
          $devEsc = $conn->real_escape_string($dev);
          $sel = $hasUpdatedAt
            ? "SELECT id, device_id, lat, lon, updated_at FROM wp_city_users WHERE device_id='$devEsc' LIMIT 1"
            : "SELECT id, device_id, lat, lon FROM wp_city_users WHERE device_id='$devEsc' LIMIT 1";
          $r = $conn->query($sel);
          if ($r && $row = $r->fetch_assoc()) {
            $age = null;
            if ($hasUpdatedAt && !empty($row['updated_at'])) {
              $age = max(0, time() - strtotime($row['updated_at']));
            }
            $diag['users'][] = [
              'id'         => (int)$row['id'],
              'device_id'  => $row['device_id'],
              'lat'        => $row['lat'],
              'lon'        => $row['lon'],
              'updated_at' => $hasUpdatedAt ? $row['updated_at'] : null,
              'age_seconds'=> $age
            ];
          } else {
            $diag['users'][] = [
              'device_id' => $dev, 'missing' => true, 'error' => $conn->error
            ];
          }
        }
      }
    }

    send_json($diag);
  }
/* -------------------- ONE TICK (no SSH needed) -------------------- */
case 'tick': {
  header('Content-Type: application/json; charset=utf-8');
  // helper om altijd JSON te antwoorden
  $out = function(array $p){ echo json_encode($p, JSON_UNESCAPED_SLASHES); exit; };

  try {
    // 1) DB-verbinding
    require_once __DIR__.'/cnn.php';    // << heel belangrijk

    // 2) State-bestand lezen
    $stateFile = __DIR__.'/gps-sim.json';
    if (!file_exists($stateFile))          $out(['ok'=>false,'error'=>'no state file']);
    $s = json_decode(@file_get_contents($stateFile), true);
    if (empty($s) || !is_array($s))        $out(['ok'=>false,'error'=>'bad state json']);
    if (empty($s['running']))              $out(['ok'=>false,'error'=>'not running']);

    // 3) Route + users normaliseren
    $routeName = $s['route'] ?? '';
    if ($routeName === '')                  $out(['ok'=>false,'error'=>'no route in state']);

    $usersRaw = $s['users'] ?? [];
    if (!is_array($usersRaw)) {
      // één string of komma-/spatiegescheiden → maak er array van
      $usersRaw = preg_split('/[,\s]+/', (string)$usersRaw, -1, PREG_SPLIT_NO_EMPTY);
    }
    $users = [];
    foreach ($usersRaw as $u) {
      $u = trim((string)$u);
      if ($u !== '') $users[] = $u;
    }
    if (!$users)                            $out(['ok'=>false,'error'=>'no users in state']);

    // 4) Punten laden (eerst SIM, dan fallback routes)
    $routeSql = $conn->real_escape_string($routeName);
    $points = [];
    $q = "SELECT latitude, longitude FROM wp_mijn_routes_sim
          WHERE routenaam='$routeSql' ORDER BY seq ASC";
    if ($res = $conn->query($q)) {
      while ($row = $res->fetch_assoc()) $points[] = $row;
    } else {
      $out(['ok'=>false,'error'=>'sql sim select failed','detail'=>$conn->error]);
    }
    if (!$points) {
      $q = "SELECT latitude, longitude FROM wp_mijn_routes
            WHERE routenaam='$routeSql' ORDER BY id ASC";
      if ($res = $conn->query($q)) {
        while ($row = $res->fetch_assoc()) $points[] = $row;
      } else {
        $out(['ok'=>false,'error'=>'sql base select failed','detail'=>$conn->error]);
      }
    }
    if (!$points)                           $out(['ok'=>false,'error'=>'no points for route']);

    // 5) Index bepalen en coord pakken
    $idx = isset($s['last_index']) ? (int)$s['last_index'] : 0;
    if ($idx < 0 || $idx >= count($points)) $idx = 0;

    $lat = sprintf('%.6f', (float)$points[$idx]['latitude']);
    $lon = sprintf('%.6f', (float)$points[$idx]['longitude']);

    // 6) Users updaten
    $updated = 0;
    foreach ($users as $devId) {
      $dev = $conn->real_escape_string($devId);
      // first_action mee updaten zodat je client het als "vers" ziet
      $u = "UPDATE wp_city_users SET lat='$lat', lon='$lon', first_action=NOW()
            WHERE device_id='$dev' AND id>0";
      if (!$conn->query($u)) {
        $out(['ok'=>false,'error'=>'sql update failed','detail'=>$conn->error,'device'=>$dev]);
      }
      $updated += $conn->affected_rows;
    }

    // 7) State opslaan
    $s['last_index']  = ($idx + 1) % count($points);
    $s['last_tick_iso'] = gmdate('c');
    @file_put_contents($stateFile, json_encode($s, JSON_PRETTY_PRINT));

    // 8) Antwoord
    $out([
      'ok'=>true,
      'idx'=>$idx,
      'lat'=>$lat,
      'lon'=>$lon,
      'updated'=>$updated,
      'next_index'=>$s['last_index']
    ]);

  } catch (Throwable $e) {
    // Vang alles af en geef leesbare JSON i.p.v. HTTP 500
    http_response_code(200);
    $out(['ok'=>false,'error'=>'exception','message'=>$e->getMessage(),'line'=>$e->getLine()]);
  }
}


  default:
    send_json(['error' => 'Unknown action', 'received' => $action ?: null], 400);
}
