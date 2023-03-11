<?php
/**
 * Kaya Registrar Escrow library
 *
 * Written in 2023 by Taras Kondratyuk (https://getpinga.com)
 *
 * @license MIT
 */

// Include the Composer autoloader
require_once 'vendor/autoload.php';

// Use the NCCEscrowGenerator class
use Pinga\Kaya\NCCEscrowGenerator;

$ianaid = '1234';
$date = date('Y-m-d');

$full = '/opt/kaya/'.$ianaid.'_RDE_'.$date.'_full_1.csv';
$hdl = '/opt/kaya/'.$ianaid.'_RDE_'.$date.'_hdl_1.csv';
$hash_file = '/opt/kaya/'.$ianaid.'_RDE_'.$date.'_hash.txt';

$gzip_full = '/opt/kaya/'.$ianaid.'_RDE_'.$date.'_full_1.csv.gz';
$gzip_hdl = '/opt/kaya/'.$ianaid.'_RDE_'.$date.'_hdl_1.csv.gz';

$gpg_full = '/opt/kaya/'.$ianaid.'_RDE_'.$date.'_full_1.csv.gz.gpg';
$gpg_hdl = '/opt/kaya/'.$ianaid.'_RDE_'.$date.'_hdl_1.csv.gz.gpg';

$pdo = new PDO("mysql:host=localhost;dbname=database", 'username', 'password');

// Create the Escrow generator
$escrowGenerator = new NCCEscrowGenerator($pdo, $full, $hdl);

// Generate the escrow deposits
$escrowGenerator->generateFull();
$escrowGenerator->generateHDL();

if (file_exists($full)) {
    $csv_contents = file_get_contents($full);
    $hash = hash("sha256", $csv_contents);
    file_put_contents($hash_file, $hash . " " . $full);
} else {
    //todo: throw exception
}

if (file_exists($hdl)) {
    $csv_contents = file_get_contents($hdl);
    $hash = hash("sha256", $csv_contents);
    file_put_contents($hash_file, $hash . " " . $hdl);
} else {
    //todo: throw exception
}

// TODO: Compress, encrypt, sign and upload
$data = file_get_contents($full);
file_put_contents($gzip_full, gzencode($data));

$gpg = new gnupg();
$gpg->addencryptkey("yourGpgKeyId");
$gpg->setsignmode(gnupg::SIG_MODE_DETACH);

$data = file_get_contents($gzip_full);
$enc_data = $gpg->encrypt($data);
file_put_contents($gpg_full, $enc_data);

//Submit here
$connection = ssh2_connect('sftp.example.com', 22);
ssh2_auth_password($connection, 'username', 'password');

ssh2_scp_send($connection, $hash_file, '/path/on/server/hash_file', 0644);
ssh2_scp_send($connection, $gpg_full, '/path/on/server/gpg_full', 0644);
ssh2_scp_send($connection, $gpg_hdl, '/path/on/server/gpg_hdl', 0644);
