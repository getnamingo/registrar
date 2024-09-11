<?php
/**
 * Namingo Registrar Escrow
 *
 * Written in 2024 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
*/

namespace Namingo\Registrar;

class FOSS
{
    private $pdo;
    private $full;
    private $hdl;

    public function __construct(\PDO $pdo, $full, $hdl)
    {
        $this->pdo = $pdo;
        $this->full = $full;
        $this->hdl = $hdl;
    }

    public function generateFull(): void
    {
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

            // Write the row data to the CSV
            $line = "\"{$row['domain']}\",\"{$row['ns1']}\",\"{$row['ns2']}\",\"{$row['ns3']}\",\"{$row['ns4']}\",\"{$row['exdate']}\",\"{$registrantContactId}\",\"{$techContactId}\",\"{$adminContactId}\",\"{$billingContactId}\",\"\",\"\",\"\",\"\"";
            fwrite($file, "$line\n");
        }

        // Close the file
        fclose($file);
    }

    public function generateHDL(): void
    {
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
}
