<?php
/**
 * Namingo Registrar Escrow
 *
 * Written in 2023 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

// Include the generator (FOSS or WHMCS)
require_once 'includes/FOSS.php';
require_once 'config.php';

// Use the FOSS class by default
use Namingo\Registrar\FOSS;

// Set up database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}

// Initialise the escrow generator
$escrowGenerator = new FOSS($pdo, $config['escrow']['full'], $config['escrow']['hdl']);

// Generate the escrow deposits
$escrowGenerator->generateFull();
$escrowGenerator->generateHDL();

// Submit the escrow deposits
//exec('./escrow-rde-client -c config.yaml');