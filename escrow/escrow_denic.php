<?php
/**
 * Kaya Registrar Escrow library
 *
 * Written in 2023 by Taras Kondratyuk (https://getpinga.com)
 *
 * @license MIT
 */

// Include the generator
require_once 'includes/DENICEscrowGenerator.php';

// Use the DENICEscrowGenerator class
use Pinga\Kaya\DENICEscrowGenerator;

$full = '/opt/kaya/full.csv';
$hdl = '/opt/kaya/hdl.csv';
$pdo = new PDO("mysql:host=localhost;dbname=database", 'username', 'password');

// Create the Escrow generator
$escrowGenerator = new DENICEscrowGenerator($pdo, $full, $hdl);

// Generate the escrow deposits
$escrowGenerator->generateFull();
$escrowGenerator->generateHDL();

// Submit the escrow deposits
//exec('./escrow-rde-client -c config.yaml');
