<?php
/**
 * Namingo Registrar
 *
 * Written in 2023-2025 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

require_once 'config.php';
require_once 'helpers.php';
require_once 'vendor/autoload.php';

$backend = $config['escrow']['backend'] ?? 'FOSS';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Registrar\EppClient\Client;

// Set up database connection
try {
    $pdo = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Database connection error: ' . $e->getMessage());
    exit('Oops! Something went wrong.');
}

// Get all domains that have not been validated and were registered more than 15 days ago
$date = new DateTime();
$date->sub(new DateInterval('P15D'));
$registration_date = $date->format('Y-m-d H:i:s');
if ($backend === 'FOSS') {
    $stmt = $pdo->prepare("
        SELECT sd.sld, sd.tld, sd.contact_email, sd.token, sd.id, sd.ns1, sd.ns2, c.custom_2 
        FROM service_domain sd
        INNER JOIN client c ON sd.client_id = c.id
        WHERE sd.synced_at IS NULL 
        AND sd.registered_at < :registered_at 
        AND c.custom_2 = 0
    ");
} elseif ($backend === 'WHMCS') {
    $stmt = $pdo->prepare("
        SELECT d.registrant, d.name, d.id, 
               c.id AS cid, c.email, c.validation, c.validation_stamp, c.validation_log
        FROM namingo_domain d
        INNER JOIN namingo_contact c ON d.registrant = c.id
        WHERE d.crdate < :registered_at
        AND c.validation = 0
    ");
} else {
    echo "Unknown backend: $backend\n";
    exit(1);
}
$stmt->bindParam(':registered_at', $registration_date);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Loop through domains and send reminder email and EPP command to update nameservers
foreach ($rows as $row) {
  if ($backend === 'FOSS') {
      $validationRow = $row['custom_2'];
  } elseif ($backend === 'WHMCS') {
      $validationRow = $row['validation'];
  } else {
      echo "Unknown backend: $backend\n";
      exit(1);
  }
  if ($validationRow == 0) {
      if ($backend === 'FOSS') {
          $domain_name = $row['sld'].$row['tld'];
          $registrant_email = $row['contact_email'];
          $token = $row['token'];
          $link = $config['registrar_url']."validate?token=$token";
      } elseif ($backend === 'WHMCS') {
          $domain_name = $row['name'];
          $registrant_email = $row['email'];
          $token = $row['validation_log'];
          $link = $config['registrar_url']."index.php?m=validation&token=".$token;
      } else {
          echo "Unknown backend: $backend\n";
          exit(1);
      }

      // Send reminder email
      $to = $registrant_email;
      $subject = 'Contact Information Validation Reminder';
      $message = "Dear Registrant,\n\nThis is a reminder to validate your contact information for the domain $domain_name. Please click the following link to validate your information:\n\n$link\n\nIf you have already validated your information, please disregard this message.\n\nSincerely,\nThe Registrar";
      send_email($to, $subject, $message, $config);

      // Send EPP command to update nameservers and status
      $epp = connectEpp("generic", $config);
      
      // Nameservers to update
      $ns1 = $config['ns1'];
      $ns2 = $config['ns2'];

      // Prepare the SQL query
      $sql = "UPDATE service_domain SET ns1 = :ns1, ns2 = :ns2 WHERE id = :id";
      $stmt = $pdo->prepare($sql);

      // Bind the parameters
      $stmt->bindParam(':ns1', $ns1);
      $stmt->bindParam(':ns2', $ns2);
      $stmt->bindParam(':id', $row['id']);

      // Execute the query
      $stmt->execute();

      // Send EPP update to registry
      $params = array(
          'domainname' => $domain_name,
          'ns1' => $ns1,
          'ns2' => $ns2
      );
      $domainUpdateNS = $epp->domainUpdateNS($params);
                
      if (array_key_exists('error', $domainUpdateNS))
      {
          echo 'DomainUpdateNS Error: ' . $domainUpdateNS['error'] . PHP_EOL;
      }
      else
      {
          echo 'ERRP cron 1 completed successfully' . PHP_EOL;
      }
      
      $params = array(
          'domainname' => $domain_name,
          'command' => 'add',
          'status' => 'clientHold'
      );
      $domainUpdateStatus = $epp->domainUpdateStatus($params);
        
      if (array_key_exists('error', $domainUpdateStatus))
      {
          echo 'DomainUpdateStatus Error: ' . $domainUpdateStatus['error'] . PHP_EOL;
      }
      else
      {
          echo 'ERRP cron 2 completed successfully' . PHP_EOL;
      }
      
      $logout = $epp->logout();
      
      // Update database with validation reminder sent date and EPP result
      if ($backend === 'FOSS') {
          $stmt = $pdo->prepare("UPDATE service_domain SET validation_reminder_sent_date = NOW(), epp_result = :epp_result WHERE sld = :sld AND tld = :tld");
          $stmt->bindParam(':epp_result', $epp_result);
          $stmt->bindParam(':sld', $row['sld']);
          $stmt->bindParam(':tld', $row['tld']);
          $stmt->execute();
      } elseif ($backend === 'WHMCS') {
          $stmt = $pdo->prepare("UPDATE namingo_contact SET validation_stamp = NOW() WHERE id = :cid");
          $stmt->bindParam(':cid', $row['cid']);
          $stmt->execute();
      } else {
          echo "Unknown backend: $backend\n";
          exit(1);
      }

  }
}