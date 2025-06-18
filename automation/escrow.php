<?php
/**
 * Namingo Registrar Escrow
 *
 * Written in 2023 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */
 
use Registrar\Escrow\EscrowInterface;

require_once __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';
require_once 'helpers.php';

$logFilePath = '/var/log/namingo/escrow.log';
$log = setupLogger($logFilePath, 'Escrow');
$log->info('job started.');

// Set up database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}

// Determine full class name with namespace
$backendClassName = 'Registrar\\Escrow\\' . $config['escrow']['backend'];

// Check if class exists
if (!class_exists($backendClassName)) {
    $log->error("Class $backendClassName not found.");
    exit(1);
}

// Instantiate the class
$escrowGenerator = new $backendClassName($pdo, $config['escrow']['full'], $config['escrow']['hdl']);

// Verify it implements the interface
if (!($escrowGenerator instanceof EscrowInterface)) {
    $log->error("Error: $backendClassName must implement EscrowInterface.");
    exit(1);
}

// Generate the escrow deposits
$escrowGenerator->generateFull();
$escrowGenerator->generateHDL();

// Submit the escrow deposits
$configArray = [
    'ianaID' => $config['escrow']['ianaID'],
    'specification' => $config['escrow']['specification'],
    'depositBaseDir' => $config['escrow']['depositBaseDir'],
    'runDir' => $config['escrow']['runDir'],
    'compressAndEncrypt' => $config['escrow']['compressAndEncrypt'],
    'uploadFiles' => $config['escrow']['uploadFiles'],
    'multi' => $config['escrow']['multi'],
    'useFileSystemCache' => $config['escrow']['useFileSystemCache'],
    'gpg' => [
        'gpgPrivateKeyPath'     => $config['escrow']['gpgPrivateKeyPath'],
        'gpgPrivateKeyPass'     => $config['escrow']['gpgPrivateKeyPass'],
        'gpgReceiverPubKeyPath' => $config['escrow']['gpgReceiverPubKeyPath'],
    ],
    'sftp' => [
        'sshHostname'           => $config['escrow']['sshHostname'],
        'sshPort'               => $config['escrow']['sshPort'],
        'sshUsername'           => $config['escrow']['sshUsername'],
        'sshPrivateKeyPath'     => $config['escrow']['sshPrivateKeyPath'],
        'sshPrivateKeyPassword' => $config['escrow']['sshPrivateKeyPassword'],
        'sshHostPublicKeyPath' => $config['escrow']['sshHostPublicKeyPath'] ?? null,
    ],
];

// Generate YAML string
$yaml = yaml_emit($configArray);

// Save to temp file in current directory
$tempFile = './config-' . uniqid('', true) . '.yaml';
file_put_contents($tempFile, $yaml);

// Execute the command
exec("./escrow-rde-client -c " . escapeshellarg($tempFile) . " 2>&1", $output, $exitCode);

// Delete temp file
unlink($tempFile);

// Log results
foreach ($output as $line) {
    // Extract Go timestamp
    if (preg_match('/^\d{4}-\d{2}-\d{2} (\d{2}:\d{2}:\d{2}\.\d{3})\s+(.*)$/', $line, $matches)) {
        $goTimeOnly = $matches[1];  // just the time portion
        $message = $matches[2] . " [{$goTimeOnly}]";
    } else {
        $message = $line;
    }

    if (stripos($line, 'error') !== false) {
        $log->error($message);
    } elseif (stripos($line, 'expired') !== false) {
        $log->warning($message);
    } elseif (stripos($line, 'validation successful') !== false) {
        $log->info($message);
    } elseif (stripos($line, 'processed') !== false) {
        $log->info($message);
    } elseif (stripos($line, 'terminated successfully') !== false) {
        $log->info($message);
    } else {
        $log->debug($message); // Default catch-all
    }
}

if ($exitCode === 0) {
    $log->info('Escrow job finished successfully.');
} else {
    $log->error("Escrow job failed with exit code: $exitCode", ['output' => $output]);
}