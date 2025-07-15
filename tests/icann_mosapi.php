<?php
/**
 * ICANN MoSAPI Registrar Monitor
 * Fetches registrar state and latest Domain METRICA report
 *
 * Author: Taras Kondratyuk (https://namingo.org)
 * License: MIT
 */

// ===== CONFIGURATION =====
$config = [
    'base_url' => 'https://mosapi.icann.org/rr/your-iana-id',
    'username'     => 'your-rr-user',
    'password'     => 'your-password',
    'version'      => 'v2',
    'cookie_file'  => __DIR__ . '/cookies.txt',
    'timeout'      => 10,
];
// ==========================

if (!function_exists('apcu_fetch')) {
    die("APCu not enabled. Enable it with `apcu.enable_cli=1` in CLI php.ini\n");
}

function is_cli() {
    return php_sapi_name() === 'cli';
}

function output($message) {
    echo is_cli() ? $message . PHP_EOL : "<pre>$message</pre>";
}

function login($config) {
    $ch = curl_init("{$config['base_url']}/login");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => "{$config['username']}:{$config['password']}",
        CURLOPT_COOKIEJAR      => $config['cookie_file'],
        CURLOPT_TIMEOUT        => $config['timeout'],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new Exception("Login failed (HTTP $status): " . trim($response));
    }
    return true;
}

function fetch_json($url, $config) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $config['cookie_file'],
        CURLOPT_TIMEOUT        => $config['timeout'],
        CURLOPT_ENCODING       => 'gzip',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Accept-Encoding: gzip',
        ],
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200) {
        throw new Exception("Failed to fetch data (HTTP $status): $response");
    }

    $json = json_decode($response, true);

    if ($json === null) {
        output("❗ JSON decode failed — raw response:\n$response");
        throw new Exception("Invalid JSON received from $url");
    }

    return $json;
}

function logout($config) {
    $ch = curl_init("{$config['base_url']}/logout");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $config['cookie_file'],
        CURLOPT_TIMEOUT        => $config['timeout'],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function display_state($data) {
    output("Registrar State:");
    output("  ID       : " . ($data['tld'] ?? $data['registrarID']));
    output("  Status   : " . $data['status']);
    output("  Updated  : " . date('Y-m-d H:i:s', $data['lastUpdateApiDatabase']));
    foreach ($data['testedServices'] as $name => $service) {
        $threshold = isset($service['emergencyThreshold']) && is_numeric($service['emergencyThreshold'])
            ? $service['emergencyThreshold'] . '%'
            : '0%';
        $status = $service['status'] ?? 'Unknown';
        $threshold = isset($service['emergencyThreshold']) && is_numeric($service['emergencyThreshold'])
            ? $service['emergencyThreshold'] . '%'
            : '0%';
        output("  - $name: $status / Emergency: $threshold");
        if (!empty($service['incidents'])) {
            foreach ($service['incidents'] as $incident) {
                $end = $incident['endTime'] ? date('Y-m-d H:i:s', $incident['endTime']) : 'Active';
                output("     Incident {$incident['incidentID']}: {$incident['state']} since " . date('Y-m-d H:i:s', $incident['startTime']) . " (end: $end)");
            }
        }
    }
}

function display_metrica($data) {
    output("\nDomain METRICA:");
    output("  Report Date   : " . $data['domainListDate']);
    output("  IANA ID       : " . ($data['ianaId'] ?? 'N/A'));
    output("  Unique Abuses : " . $data['uniqueAbuseDomains']);

    foreach ($data['domainListData'] as $entry) {
        $count = ($entry['count'] < 0) ? 'N/A' : $entry['count'];
        output("  Threat: {$entry['threatType']} (Count: $count)");
        if (!empty($entry['domains'])) {
            foreach ($entry['domains'] as $domain) {
                output("    - $domain");
            }
        }
    }
}

// MAIN EXECUTION
try {
    $cacheKeyState   = 'mosapi_state';
    $cacheKeyMetrica = 'mosapi_metrica';

    $stateData = apcu_fetch($cacheKeyState);
    $metricaData = apcu_fetch($cacheKeyMetrica);

    if (!$stateData || !$metricaData) {
        login($config);

        $stateUrl   = "{$config['base_url']}/{$config['version']}/monitoring/state";
        $metricaUrl = "{$config['base_url']}/{$config['version']}/metrica/domainList/latest";

        $stateData   = fetch_json($stateUrl, $config);
        $metricaData = fetch_json($metricaUrl, $config);

        apcu_store($cacheKeyState, $stateData, 290);
        apcu_store($cacheKeyMetrica, $metricaData, 290);

        logout($config);
    } else {
        output("Using cached MoSAPI data (valid for 5 minutes)");
    }

    display_state($stateData);
    display_metrica($metricaData);
} catch (Exception $e) {
    output("ERROR: " . $e->getMessage());
}