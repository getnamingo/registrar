<?php
// Include the Swoole extension
if (!extension_loaded('swoole')) {
    die('Swoole extension must be installed');
}

require_once __DIR__ . '/vendor/autoload.php';

use Swoole\Server;
use Namingo\Rately\Rately;
use Registrar\WHOIS\PlatformFactory;

$c = require_once 'config.php';
require_once 'helpers.php';
$logFilePath = '/var/log/namingo/whois.log';
$log = setupLogger($logFilePath, 'WHOIS');

$privacy = $c['privacy'];
$backend = strtolower($c['backend'] ?? 'foss');
try {
    $adapter = PlatformFactory::create($backend);
} catch (\Throwable $e) {
    echo "WHOIS backend error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// Initialize the PDO connection pool
$pool = new Swoole\Database\PDOPool(
    (new Swoole\Database\PDOConfig())
        ->withDriver($c['db_type'])
        ->withHost($c['db_host'])
        ->withPort($c['db_port'])
        ->withDbName($c['db_database'])
        ->withUsername($c['db_username'])
        ->withPassword($c['db_password'])
        ->withCharset('utf8mb4')
);

// Create a Swoole TCP server
$server = new Server('0.0.0.0', 43);
$server->set([
    'daemonize' => false,
    'log_file' => '/var/log/namingo/whois_application.log',
    'log_level' => SWOOLE_LOG_INFO,
    'worker_num' => swoole_cpu_num() * 2,
    'pid_file' => '/var/run/whois.pid',
    'max_request' => 1000,
    'dispatch_mode' => 2,
    'open_tcp_nodelay' => true,
    'max_conn' => 1024,
    'heartbeat_check_interval' => 60,
    'heartbeat_idle_time' => 120,
    'buffer_output_size' => 2 * 1024 * 1024, // 2MB
    'enable_reuse_port' => true,
    'package_max_length' => 8192, // 8KB
    'open_eof_check' => true,
    'package_eof' => "\r\n"
]);

$rateLimiter = new Rately();
$log->info('server started.');

// Register a callback to handle incoming connections
$server->on('connect', function ($server, $fd) use ($log) {
    $log->info('new client connected: ' . $fd);
});

// Register a callback to handle incoming requests
$server->on('receive', function ($server, $fd, $reactorId, $data) use ($c, $pool, $log, $rateLimiter, $adapter) {
    // Get a PDO connection from the pool
    $pdo = $pool->get();
    $parsedQuery = parseQuery($data);
    $queryType = $parsedQuery['type'];
    $queryData = $parsedQuery['data'];
    
    $clientInfo = $server->getClientInfo($fd);
    $remoteAddr = $clientInfo['remote_ip'];

    if (($c['rately'] == true) && ($rateLimiter->isRateLimited('whois', $remoteAddr, $c['limit'], $c['period']))) {
        $log->error('rate limit exceeded for ' . $remoteAddr);
        $server->send($fd, "rate limit exceeded. Please try again later");
        $server->close($fd);
        return;
    }

    // Handle the WHOIS query
    try {
        switch ($queryType) {
            case 'domain':
                $result = $adapter->handleDomainQuery($queryData, $pdo, $server, $fd, $log);
            default:
                // Handle unknown query type
                $log->error('Error');
                $server->send($fd, "Error");
        }
    } catch (PDOException $e) {
        // Handle database exceptions
        $log->error('Database error: ' . $e->getMessage());
        $server->send($fd, "Error connecting to the whois database");
        $server->close($fd);
    } catch (Throwable $e) {
        // Catch any other exceptions or errors
        $log->error('Error: ' . $e->getMessage());
        $server->send($fd, "Error");
        $server->close($fd);
    } finally {
        // Return the connection to the pool
        $pool->put($pdo);
        $server->close($fd);
    }
});

// Register a callback to handle client disconnections
$server->on('close', function ($server, $fd) use ($log) {
    $log->info('client ' . $fd . ' disconnected.');
});

// Start the server
$server->start();