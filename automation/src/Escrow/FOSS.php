<?php

namespace Registrar\Escrow;

class FOSS implements EscrowInterface {
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
        // Query the database for the domain data and include domain_id (s.id)
        $stmt = $this->pdo->prepare("SELECT s.id, CONCAT(s.sld, '', s.tld) AS domain, s.ns1, s.ns2, s.ns3, s.ns4, s.expires_at, c.aid, DATE_FORMAT(`expires_at`, '%Y-%m-%dT%H:%i:%sZ') AS `exdate` FROM service_domain s JOIN client c ON s.client_id = c.id");
        $stmt->execute();

        // Open the file for writing
        $file = fopen($this->full, 'w');

        // Write the CSV header row
        fwrite($file, '"domain","ns1","ns2","ns3","ns4","expiration_date","rt-handle","tc-handle","ac-handle","bc-handle","prt-handle","ptc-handle","pac-handle","pbc-handle"' . "\n");

        // Write the data rows to the file
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Second query to get contact IDs from domain_meta based on domain_id
            $stmtMeta = $this->pdo->prepare("SELECT registrant_contact_id, admin_contact_id, tech_contact_id, billing_contact_id FROM domain_meta WHERE domain_id = :domain_id");
            $stmtMeta->bindParam(':domain_id', $row['id'], \PDO::PARAM_INT);
            $stmtMeta->execute();
            $meta = $stmtMeta->fetch(\PDO::FETCH_ASSOC);

            // Assign variables for contact IDs, use empty string if NULL
            $registrantContactId = $meta['registrant_contact_id'] ?? '';
            $adminContactId = $meta['admin_contact_id'] ?? '';
            $techContactId = $meta['tech_contact_id'] ?? '';
            $billingContactId = $meta['billing_contact_id'] ?? '';

            $domainAscii = idn_to_ascii($row['domain'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            // Write the row data to the CSV
            $line = "\"{$domainAscii}\",\"{$row['ns1']}\",\"{$row['ns2']}\",\"{$row['ns3']}\",\"{$row['ns4']}\",\"{$row['exdate']}\",\"{$registrantContactId}\",\"{$techContactId}\",\"{$adminContactId}\",\"{$billingContactId}\",\"\",\"\",\"\",\"\"";
            fwrite($file, "$line\n");
        }

        // Close the file
        fclose($file);
    }

    public function generateHDL(): void {
        // Query the database to fetch data from service_domain and join domain_meta to get the registrant_contact_id
        $stmt = $this->pdo->prepare("
            SELECT dm.registrant_contact_id AS handle, 
                   CONCAT(s.contact_first_name, ' ', s.contact_last_name) AS name,
                   s.contact_address1 AS address,
                   s.contact_state AS state,
                   s.contact_postcode AS zip,
                   s.contact_city AS city,
                   s.contact_country AS country,
                   s.contact_email AS email,
                   CONCAT('+', s.contact_phone_cc, '.', s.contact_phone) AS phone
            FROM service_domain s
            JOIN domain_meta dm ON s.id = dm.domain_id
            WHERE dm.domain_id = (
                SELECT MIN(dm2.domain_id)
                FROM domain_meta dm2
                WHERE dm2.registrant_contact_id = dm.registrant_contact_id
            )
        ");
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

            // Join the values with a comma separator and surround them with double quotes
            $line = '"' . implode('","', $row) . '"';

            // Add the value for fax that does not exist
            $line .= ',""';

            // Write the line to the file
            fwrite($file, "$line\n");
        }

        // Close the file
        fclose($file);
    }

    public function generateRDE(int $ianaID): void {
        // Open file
        $file = fopen($this->full, 'w');

        // ICANN RDE 2024 header
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

        // Fetch all domains and their contact mapping
        $stmt = $this->pdo->prepare("
            SELECT s.id AS domain_id,
                   CONCAT(s.sld, '', s.tld) AS domainname,
                   DATE_FORMAT(s.expires_at, '%Y-%m-%dT%H:%i:%sZ') AS expire,
                   dm.registrant_contact_id,
                   dm.billing_contact_id
            FROM service_domain s
            JOIN domain_meta dm ON s.id = dm.domain_id
        ");
        $stmt->execute();
        $domains = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($domains as $domain) {
            // Fetch registrant and billing contact data
            $contactIds = array_filter([
                $domain['registrant_contact_id'] ?? null,
                $domain['billing_contact_id'] ?? null
            ]);

            if (empty($contactIds)) {
                continue;
            }

            $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
            $stmtContacts = $this->pdo->prepare("
                SELECT id,
                       CONCAT(contact_first_name, ' ', contact_last_name) AS name,
                       contact_address1 AS street,
                       contact_city,
                       contact_state,
                       contact_postcode AS zip,
                       contact_country,
                       CONCAT('+', contact_phone_cc, '.', contact_phone) AS phone,
                       contact_email AS email
                FROM client_contact
                WHERE id IN ($placeholders)
            ");
            $stmtContacts->execute($contactIds);

            $contacts = [];
            while ($c = $stmtContacts->fetch(\PDO::FETCH_ASSOC)) {
                $contacts[$c['id']] = $c;
            }

            $registrant = $contacts[$domain['registrant_contact_id']] ?? [];
            $billing    = $contacts[$domain['billing_contact_id']] ?? [];

            // Compose row for RDE CSV
            $line = [
                idn_to_ascii($domain['domainname'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain['domainname'],
                $domain['expire']             ?? '',
                $ianaID,
                $registrant['name']           ?? '',
                $registrant['street']         ?? '',
                $registrant['contact_city']   ?? '',
                $registrant['contact_state']  ?? '',
                $registrant['zip']            ?? '',
                $registrant['contact_country']?? '',
                $this->normalizePhone($registrant['phone'] ?? ''),
                $registrant['email']          ?? '',
                $billing['name']              ?? ''
            ];

            fputcsv($file, $line);
        }

        fclose($file);
    }

    // Normalize phone to +E.164-like format
    private function normalizePhone(string $number): string {
        return '+' . ltrim(preg_replace('/[^0-9+]/', '', $number), '+');
    }
}