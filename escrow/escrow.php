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

// Use the FOSS class by default
use Namingo\Registrar\FOSS;

$full = '/opt/namingo/escrow/full.csv';
$hdl = '/opt/namingo/escrow/hdl.csv';
$pdo = new PDO("mysql:host=localhost;dbname=database", 'username', 'password');

// Initialise the escrow generator
$escrowGenerator = new FOSS($pdo, $full, $hdl);

// Generate the escrow deposits
$escrowGenerator->generateFull();
$escrowGenerator->generateHDL();

// Submit the escrow deposits
//exec('./escrow-rde-client -c config.yaml');