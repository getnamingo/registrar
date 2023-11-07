<?php
/**
 * Namingo Registrar
 *
 * Written in 2023 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */
 
require_once 'config.php';
require_once 'includes/eppClient.php';

use Pinga\Tembo\eppClient;
$registrar = "Epp";

function connectEpp(string $registry, $config)
{
    try
    {
        $epp = new eppClient();
        $info = [
        "host" => $config["host"],
        "port" => $config["port"], "timeout" => 30, "tls" => "1.3", "bind" => false, "bindip" => "1.2.3.4:0", "verify_peer" => false, "verify_peer_name" => false,
        "verify_host" => false, "cafile" => "", "local_cert" => $config["ssl_cert"], "local_pk" => $config["ssl_key"], "passphrase" => "", "allow_self_signed" => true, ];
        $epp->connect($info);
        $login = $epp->login(["clID" => $config["username"], "pw" => $config["password"],
        "prefix" => "tembo", ]);
        if (array_key_exists("error", $login))
        {
            echo "Login Error: " . $login["error"] . PHP_EOL;
            exit();
        }
        else
        {
            echo "Login Result: " . $login["code"] . ": " . $login["msg"][0] . PHP_EOL;
        }
        return $epp;
    }
    catch(EppException $e)
    {
        return "Error : " . $e->getMessage();
    }
}

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
$stmt = $pdo->prepare("SELECT * FROM service_domain WHERE synced_at = NULL AND registered_at < :registered_at");
$stmt->bindParam(':registered_at', $registration_date);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Loop through domains and send reminder email and EPP command to update nameservers
foreach ($rows as $row) {
  $domain_name = $row['sld'].$row['tld'];
  $registrant_email = $row['contact_email'];
  $token = $row['token'];

  // Send reminder email
  $to = $registrant_email;
  $subject = 'Contact Information Validation Reminder';
  $link = "https://validate.example.com/?$token";
  $message = "Dear Registrant,\n\nThis is a reminder to validate your contact information for the domain $domain_name. Please click the following link to validate your information:\n\n$link\n\nIf you have already validated your information, please disregard this message.\n\nSincerely,\nThe Registrar";
  $headers = "From: noreply@example.com\r\n";
  mail($to, $subject, $message, $headers);

  // Send EPP command to update nameservers and status
  $epp = connectEpp("generic", $config);
  
  // Nameservers to update
  $ns1 = 'ns1.registrar.com';
  $ns2 = 'ns2.registrar.com';

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
  	'domainname' => $row['sld'].$row['tld'],
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
      'domainname' => $row['sld'].$row['tld'],
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
  $stmt = $pdo->prepare("UPDATE service_domain SET validation_reminder_sent_date = NOW(), epp_result = :epp_result WHERE sld = :sld AND tld = :tld");
  $stmt->bindParam(':epp_result', $epp_result);
  $stmt->bindParam(':sld', $row['sld']);
  $stmt->bindParam(':tld', $row['tld']);
  $stmt->execute();
}