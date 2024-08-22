<?php
/**
 * Namingo Registrar URS
 *
 * Written in 2024 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

require_once 'config.php';

// Connect to the database
try {
    $dbh = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('DB Connection failed: ' . $e->getMessage());
}

// Connect to mailbox
try {
    $inbox = imap_open($config['urs_imap_host'], $config['urs_imap_username'], $config['urs_imap_password']);
    if (!$inbox) {
        throw new Exception('Cannot connect to mailbox: ' . imap_last_error());
    }
    // Search for emails from the two URS providers
    $emailsFromProviderA = imap_search($inbox, 'FROM "urs@adrforum.com" UNSEEN');
    $emailsFromProviderB = imap_search($inbox, 'FROM "urs@adndrc.org" UNSEEN');
    $emailsFromProviderC = imap_search($inbox, 'FROM "urs@mfsd.it" UNSEEN');

    // Combine the arrays of email IDs
    $allEmails = array_merge($emailsFromProviderA, $emailsFromProviderB, $emailsFromProviderC);

    foreach ($allEmails as $emailId) {
        $header = imap_headerinfo($inbox, $emailId);
        $from = $header->from[0]->mailbox . "@" . $header->from[0]->host;
        $subject = $header->subject;
        $date = date('Y-m-d H:i:s', strtotime($header->date)) . '.000';

        // Determine the URS provider based on the email sender
        $providerAEmail = 'urs@adrforum.com';
        $providerBEmail = 'urs@adndrc.org';
        $providerCEmail = 'urs@mfsd.it';

        // Determine the URS provider based on the email sender
        if ($from == $providerAEmail) {
            $ursProvider = 'FORUM';
        } elseif ($from == $providerBEmail) {
            $ursProvider = 'ADNDRC';
        } elseif ($from == $providerCEmail) {
            $ursProvider = 'MFSD';
        } else {
            $ursProvider = 'Unknown';
        }

        // Extract domain name or relevant info from the email (you'd need more specific code here based on the email content)
        $body = imap_fetchbody($inbox, $emailId, 1);
        $domain = extractDomainNameFromEmail($body);

        // Extract TLD from the domain and prepend a dot
        $parts = explode('.', $domain);
        $domainName = $parts[0];
        $tld = "." . end($parts);

        // Insert into the database
        $stmt = $dbh->prepare("SELECT sld, tld, client_id FROM service_domain WHERE sld = ?");
        $stmt->execute([$domainName]);
        $domainResult = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($domainResult) {
            $domainName = $domain;
            $clientId = $domainResult['client_id'];

            // Prepare the current date and time in the required format
            $currentDateTime = date('Y-m-d H:i:s');
            
            // Insert into the support_ticket table
            $stmt = $dbh->prepare("INSERT INTO support_ticket (support_helpdesk_id, client_id, priority, subject, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([1, $clientId, 100, 'New URS case for ' . $domainName, 'on_hold', $currentDateTime, $currentDateTime]);

            // Get the last inserted ID from the support_ticket table
            $supportTicketId = $dbh->lastInsertId();

            // Get the client IP address, default to '127.0.0.1' if not available
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

            // Insert into the support_ticket_message table using the last inserted ID
            $stmt = $dbh->prepare("INSERT INTO support_ticket_message (support_ticket_id, client_id, admin_id, content, attachment, ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$supportTicketId, null, 1, 'New URS case for ' . $domainName . ' submitted by ' . $ursProvider . ' on ' . $date . '. Please act accordingly', null, $clientIp, $currentDateTime, $currentDateTime]);
        } else {
            error_log('Domain ' . $domainName . ' does not exists in registry');
        }
    }

    imap_close($inbox);
} catch (Exception $e) {
    error_log('IMAP connection error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('Error: ' . $e->getMessage());
}

function extractDomainNameFromEmail($emailBody) {
    // This is just a basic example
    preg_match("/domain: (.*?) /i", $emailBody, $matches);
    return $matches[1] ?? '';
}