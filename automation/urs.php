<?php
/**
 * Namingo Registrar URS
 *
 * Written in 2024-2025 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
 */

declare(strict_types=1);
date_default_timezone_set('UTC');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

$backend = $config['escrow']['backend'] ?? 'FOSS';

$logFilePath = '/var/log/namingo/urs.log';
$log = setupLogger($logFilePath, 'URS');
$log->info('job started.');

// Connect to the database
try {
    $dbh = new PDO("mysql:host={$config['db']['host']};dbname={$config['db']['dbname']}", $config['db']['username'], $config['db']['password']);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $log->error('Database connection error: ' . $e->getMessage());
    exit(1);
}

// Connect to mailbox
try {
    $inbox = imap_open($config['urs_imap_host'], $config['urs_imap_username'], $config['urs_imap_password']);
    if (!$inbox) {
        $log->error('Cannot connect to mailbox: ' . imap_last_error());
        exit(1);
    }
    // Search for emails from the two URS providers
    $emailsFromProviderA = imap_search($inbox, 'FROM "urs@adrforum.com" UNSEEN');
    $emailsFromProviderB = imap_search($inbox, 'FROM "urs@adndrc.org" UNSEEN');
    $emailsFromProviderC = imap_search($inbox, 'FROM "urs@mfsd.it" UNSEEN');

    // Combine the arrays of email IDs
    $allEmails = array_merge(
        $emailsFromProviderA ?: [],
        $emailsFromProviderB ?: [],
        $emailsFromProviderC ?: []
    );

    foreach ($allEmails as $emailId) {
        $header = imap_headerinfo($inbox, $emailId);
        $from = $header->from[0]->mailbox . "@" . $header->from[0]->host;
        $subject = $header->subject;
        $date = date('Y-m-d H:i:s', strtotime($header->date)) . '.000';

        // Determine the URS provider
        $provider = match ($from) {
            'urs@adrforum.com' => 'FORUM',
            'urs@adndrc.org' => 'ADNDRC',
            'urs@mfsd.it' => 'MFSD',
            default => 'Unknown',
        };

        // Extract domain name or relevant info from the email (you'd need more specific code here based on the email content)
        $body = imap_fetchbody($inbox, $emailId, 1);
        $domain = extractDomainNameFromEmail($body);

        if (!$domain) {
            $log->info("No domain found in email body for email ID $emailId");
            continue;
        }

        // Insert into the database
        if ($backend === 'FOSS') {
            $parts = explode('.', $domain);
            $domainName = $parts[0];

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
                $stmt->execute([$supportTicketId, null, 1, 'New URS case for ' . $domainName . ' submitted by ' . $provider . ' on ' . $date . '. Please act accordingly', null, $clientIp, $currentDateTime, $currentDateTime]);

                $ticketId = $dbh->lastInsertId();
                $log->info("Created support ticket ID $ticketId for domain $domainName.");
            } else {
                $log->error('Domain ' . $domain . ' does not exists in registry');
            }
        } elseif ($backend === 'WHMCS') {
            $stmt = $dbh->prepare("SELECT id, userid FROM tbldomains WHERE domain = ?");
            $stmt->execute([$domain]);
            $domainResult = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($domainResult) {
                $userId = $domainResult['userid'];

                // Insert ticket in WHMCS tbltickets table
                $currentDateTime = date('Y-m-d H:i:s');
                // Generate a unique TID (Ticket ID) similar to WHMCS format, e.g., "SCT-441641"
                $tid = 'SCT-' . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                // Generate a unique alphanumeric code for `c` (Ticket Hash), e.g., "tH7B0ple"
                $c = substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 8);

                $stmt = $dbh->prepare("
                    INSERT INTO tbltickets (
                        tid, userid, did, cc, c, title, message, status, urgency, date, lastreply, ipaddress, 
                        flag, name, email, contactid, requestor_id, admin, attachment, attachments_removed, merged_ticket_id, clientunread, replyingadmin, adminunread, replyingtime, service, editor, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                // Execute with appropriate values for each field
                $stmt->execute([
                    $tid,                           // tid: Unique Ticket ID
                    $userId,                        // userid: User associated with the domain
                    $config['whmcs_department_id'], // did: Department ID
                    '',                             // cc: empty
                    $c,                             // c: Unique Ticket Hash
                    'New URS case for ' . $domain,  // title: Subject line of the ticket
                    'New URS case for ' . $domain . ' submitted by ' . $provider . ' on ' . $date . '. Please review and act accordingly.', // message: Ticket content
                    'Open',                         // status: Ticket status
                    'Medium',                       // urgency: Priority level
                    $currentDateTime,               // date: Ticket creation date
                    $currentDateTime,               // lastreply: Date of last reply (set to creation date initially)
                    '127.0.0.1',                    // ipaddress: Default IP address if not available
                    0,                              // flag: 0
                    'Automated URS System',         // name: Requestor’s name (can use a generic identifier for automated tickets)
                    $config['email']['sender'],     // email: Default email if not available
                    0,                              // contactid: Set to 0 if no specific contact is associated
                    0,                              // requestor_id: Default to 0 if not specified
                    '',                             // admin: empty
                    '',                             // attachment: empty
                    0,                              // attachments_removed: Set to 0, assuming no attachments are initially removed
                    0,                              // merged_ticket_id: Default to 0 if this ticket is standalone
                    1,                              // clientunread: 1
                    0,                              // replyingadmin: 0
                    '',                             // adminunread: empty
                    '0000-00-00 00:00:00',          // replyingtime: 0
                    '',                             // service: empty
                    'plain',                        // editor: Default to 'plain' for message formatting
                    $currentDateTime                // updated_at: Set to current date/time
                ]);

                // Log insertion and retrieve last inserted ticket ID
                $ticketId = $dbh->lastInsertId();
                $log->info("Created support ticket ID $ticketId for domain $domain.");
            } else {
                $log->error('Domain ' . $domain . ' does not exists in registry');
            }
        } elseif ($backend === 'LOOM') {
            $stmt = $dbh->prepare("SELECT user_id FROM services WHERE service_name = ? AND type = 'domain' AND status = 'active' LIMIT 1");
            $stmt->execute([$domain]);
            $svc = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($svc && !empty($svc['user_id'])) {
                $userId      = (int)$svc['user_id'];
                $categoryId  = (int)($config['loom']['support_category_id'] ?? 2);
                $priority    = 'High';
                $subject     = 'New URS case for ' . $domain;
                $message     = 'New URS case for ' . $domain . ' submitted by ' . $provider . ' on ' . $date . '. Please review and act accordingly.';
                $status      = 'Open';

                $stmt = $dbh->prepare("
                    INSERT INTO support_tickets (user_id, category_id, subject, message, status, priority)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $categoryId, $subject, $message, $status, $priority]);

                $ticketId = $dbh->lastInsertId();
                $log->info("Created LOOM support ticket ID $ticketId for domain $domain (user_id=$userId).");
            } else {
                $log->error('Domain ' . $domain . ' does not exist or is not active in LOOM services');
            }
        } else {
            $log->error("Unknown backend: $backend");
            exit(1);
        }
    }

    $log->info('URS job completed.');
    imap_close($inbox);
} catch (Exception $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
    exit(1);
}

function extractDomainNameFromEmail($emailBody) {
    // URS-style: "Domain Name: example.com"
    if (preg_match('/Domain(?:\s+Name)?\s*:\s*([a-z0-9.-]+\.[a-z]{2,})/i', $emailBody, $matches)) {
        return strtolower(trim($matches[1]));
    }

    // Fallback: find first domain-looking string
    if (preg_match('/\b([a-z0-9.-]+\.[a-z]{2,})\b/i', $emailBody, $matches)) {
        return strtolower(trim($matches[1]));
    }

    return '';
}