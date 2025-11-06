<?php
header('Content-Type: application/json');

function extract_ipv4(string $candidate): ?string {
    $c = trim($candidate);

    // Localhost IPv6 -> IPv4
    if ($c === '::1') return '127.0.0.1';

    // IPv4-mapped IPv6, bv. ::ffff:192.168.0.10
    if (stripos($c, '::ffff:') === 0) {
        $maybe = substr($c, 7);
        if (filter_var($maybe, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $maybe;
    }

    // Echte IPv4?
    if (filter_var($c, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return $c;

    return null;
}

function get_client_ipv4(): ?string {
    // Let op: volgorde is belangrijk als je achter proxies/ LB's zit
    $keys = [
        'HTTP_X_FORWARDED_FOR', // kan meerdere IP’s bevatten, eerste is origineel
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];

    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            // kan "ip1, ip2, ip3" zijn
            $parts = explode(',', $_SERVER[$k]);
            foreach ($parts as $p) {
                $v4 = extract_ipv4($p);
                if ($v4 !== null) return $v4;
            }
        }
    }
    return null;
}

echo json_encode([
    'ip_v4' => get_client_ipv4()
]);
