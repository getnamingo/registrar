<?php

namespace Registrar\Escrow;

class WHMCS implements EscrowInterface {
    private $pdo;
    private $full;
    private $hdl;

    public function __construct(\PDO $pdo, $full, $hdl)
    {
        $this->pdo = $pdo;
        $this->full = $full;
        $this->hdl = $hdl;
    }

    public function generateFull(): void {
        // Query to get id, registrant, admin, tech, billing, name, crdate, exdate from namingo_domain
        $sqlDomain = "SELECT d.id, d.registrant, d.admin, d.tech, d.billing, d.name, DATE_FORMAT(d.crdate, '%Y-%m-%dT%H:%i:%sZ') AS crdate, DATE_FORMAT(d.exdate, '%Y-%m-%dT%H:%i:%sZ') AS exdate FROM namingo_domain d";
        $stmtDomain = $this->pdo->prepare($sqlDomain);
        $stmtDomain->execute();
        $domains = $stmtDomain->fetchAll(\PDO::FETCH_ASSOC);

        // Open the file for writing and write the CSV header row
        $file = fopen($this->full, 'w');
        fwrite($file, '"domain","status","registration_date","expiry_date","next_due_date","rt-handle","tc-handle","ac-handle","bc-handle","prt-handle","ptc-handle","pac-handle","pbc-handle"' . "\n");
        fclose($file);

        // Loop through each domain and gather additional data
        foreach ($domains as $domain) {
            $domainId = $domain['id'];

            // Get status from namingo_domain_status, default to 'ok' if empty
            $sqlStatus = "SELECT status FROM namingo_domain_status WHERE domain_id = :domain_id";
            $stmtStatus = $this->pdo->prepare($sqlStatus);
            $stmtStatus->bindParam(':domain_id', $domainId, \PDO::PARAM_INT);
            $stmtStatus->execute();
            $status = $stmtStatus->fetchColumn();
            $domain['status'] = $status ? $status : 'ok';

            // Prepare to store contacts by type, including the identifier
            $domain['contacts'] = [
                'registrant' => $domain['registrant'] ?? null,
                'admin' => $domain['admin'] ?? null,
                'tech' => $domain['tech'] ?? null,
                'billing' => $domain['billing'] ?? null,
            ];
            
            // Collect contact IDs
            $contactIds = array_filter([$domain['registrant'], $domain['admin'], $domain['tech'], $domain['billing']]);

            // Prepare the SQL to fetch identifiers for all contact IDs in one query
            $sqlContacts = "SELECT id, identifier FROM namingo_contact WHERE id IN (" . implode(',', array_fill(0, count($contactIds), '?')) . ")";
            $stmtContacts = $this->pdo->prepare($sqlContacts);
            $stmtContacts->execute($contactIds);

            // Map the identifiers to contact IDs
            $identifiers = [];
            while ($row = $stmtContacts->fetch(\PDO::FETCH_ASSOC)) {
                $identifiers[$row['id']] = $row['identifier'];
            }

            // Replace the contact IDs with identifiers
            $domain['contacts'] = [
                'registrant' => $identifiers[$domain['registrant']] ?? null,
                'admin' => $identifiers[$domain['admin']] ?? null,
                'tech' => $identifiers[$domain['tech']] ?? null,
                'billing' => $identifiers[$domain['billing']] ?? null,
            ];

            // Add to the domains array for CSV writing
            $this->writeToCsv($domain);
        }
    }

    public function generateHDL(): void {
        // Query the database to get data from both tables
        $sql = "
            SELECT identifier, voice AS phone, fax, email, name, street1 AS address, city, sp AS state, pc AS postcode, cc AS country
            FROM namingo_contact c
            WHERE c.id = (
                SELECT MIN(c2.id)
                FROM namingo_contact c2
                WHERE c2.identifier = c.identifier
            )
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        // Open the file for writing
        $file = fopen($this->hdl, 'w');

        // Write the CSV header row
        fwrite($file, '"handle","name","address","state","zip","city","country","email","phone","fax"' . "\n");

        // Write the data rows to the file
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Ensure the phone number starts with a single '+'
            if (isset($row['phone'])) {
                $row['phone'] = '+' . ltrim($row['phone'], '+');
            }

            // Ensure the fax number starts with a single '+'
            if (isset($row['fax'])) {
                $row['fax'] = '+' . ltrim($row['fax'], '+');
            } else {
                // Add a default value if no fax number is present
                $row['fax'] = '';
            }

            // Prepare the row by joining values with commas and surrounding with double quotes
            $line = '"' . $row['identifier'] . '","' . $row['name'] . '","' . $row['address'] . '","' . $row['state'] . '","' . $row['postcode'] . '","' . $row['city'] . '","' . $row['country'] . '","' . $row['email'] . '","' . $row['phone'] . '","' . $row['fax'] . '"';
            
            // Write the line to the file
            fwrite($file, "$line\n");
        }

        // Close the file
        fclose($file);
    }

    public function generateRDE(int $ianaID): void {
        // Open file for writing
        $file = fopen($this->full, 'w');

        // ICANN RDE 2024 CSV header
        $header = [
            'domain',
            'expiration-date',
            'iana',
            'rt-name',
            'rt-street',
            'rt-city',
            'rt-state',
            'rt-zip',
            'rt-country',
            'rt-phone',
            'rt-email',
            'bc-name'
        ];
        fputcsv($file, $header);

        // Fetch domains
        $sql = "SELECT id, registrant, billing, name, exdate FROM namingo_domain";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $domains = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($domains as $domain) {
            // Fetch contacts (registrant and billing only)
            $contactIds = array_filter([$domain['registrant'], $domain['billing']]);

            if (empty($contactIds)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            $stmtContacts = $this->pdo->prepare("
                SELECT id, name, street1, city, sp, pc, cc, voice, email
                FROM namingo_contact
                WHERE id IN ($placeholders)
            ");
            $stmtContacts->execute($contactIds);

            $contacts = [];
            while ($c = $stmtContacts->fetch(\PDO::FETCH_ASSOC)) {
                $contacts[$c['id']] = $c;
            }

            // Build RDE row
            $registrant = $contacts[$domain['registrant']] ?? [];
            $billing    = $contacts[$domain['billing']] ?? [];

            $row = [
                idn_to_ascii($domain['name'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain['name'],
                $domain['exdate']            ?? '',
                $ianaID,
                $registrant['name']          ?? '',
                $registrant['street1']       ?? '',
                $registrant['city']          ?? '',
                $registrant['sp']            ?? '',
                $registrant['pc']            ?? '',
                $registrant['cc']            ?? '',
                $this->normalizePhone($registrant['voice'] ?? ''),
                $registrant['email']         ?? '',
                $billing['name']             ?? ''
            ];

            fputcsv($file, $row);
        }

        fclose($file);
    }

    // Normalize phone numbers to +E.164
    private function normalizePhone(string $number): string {
        $number = preg_replace('/[^0-9+]/', '', $number);
        return '+' . ltrim($number, '+');
    }

    private function writeToCsv(array $domain): void
    {
        // Open the file for appending
        $file = fopen($this->full, 'a');

        // Extract the necessary data from the domain array
        $domainName = idn_to_ascii($domain['name'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        $status = $domain['status'];
        $registrationDate = $domain['crdate'];
        $expiryDate = $domain['exdate'];
        $nextDueDate = ''; // Assuming there is no `nextduedate` from your current data

        // Get handles for the CSV, use empty string if they do not exist
        $rtHandle = $domain['contacts']['registrant'] ?? '';
        $tcHandle = $domain['contacts']['tech'] ?? '';
        $acHandle = $domain['contacts']['admin'] ?? '';
        $bcHandle = $domain['contacts']['billing'] ?? '';

        // Create privacy-related handles (set them as empty for now)
        $prtHandle = '';
        $ptcHandle = '';
        $pacHandle = '';
        $pbcHandle = '';

        // Build the CSV line
        $line = "\"$domainName\",\"$status\",\"$registrationDate\",\"$expiryDate\",\"$nextDueDate\",\"$rtHandle\",\"$tcHandle\",\"$acHandle\",\"$bcHandle\",\"$prtHandle\",\"$ptcHandle\",\"$pacHandle\",\"$pbcHandle\"";
        fwrite($file, "$line\n");

        // Close the file
        fclose($file);
    }
}