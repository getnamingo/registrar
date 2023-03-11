<?php
/**
 * Kaya Registrar Escrow library
 *
 * Written in 2023 by Taras Kondratyuk (https://getpinga.com)
 *
 * @license MIT
 */

namespace Pinga\Kaya;

class DENICEscrowGenerator
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
        // Query the database for the domain data
        $stmt = $this->pdo->prepare("SELECT CONCAT(s.sld, '', s.tld) AS domain, s.ns1, s.ns2, s.ns3, s.ns4, s.expires_at, c.aid
		FROM service_domain s JOIN client c ON s.client_id = c.id");
        $stmt->execute();

        // Open the file for writing
        $file = fopen($this->full, 'w');

        // Write the CSV header row
        fwrite($file, '"domain","ns1","ns2","ns3","ns4","expiration_date","rt-handle","tc-handle","ac-handle","bc-handle","prt-handle","ptc-handle","pac-handle","pbc-handle"' . "\n");

        // Write the data rows to the file
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $line = "\"{$row['domain']}\",\"{$row['ns1']}\",\"{$row['ns2']}\",\"{$row['ns3']}\",\"{$row['ns4']}\",\"{$row['expires_at']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\"";
            fwrite($file, "$line\n");
        }

        // Close the file
        fclose($file);
    }

    public function generateHDL(): void
    {
        // Query the database for the registrant data
        $stmt = $this->pdo->prepare("SELECT aid, CONCAT(first_name, ' ', last_name) AS name, address_1, state, postcode, city, country, email, CONCAT('+', phone_cc, '.', phone) AS phone FROM client");
        $stmt->execute();

        // Open the file for writing
        $file = fopen($this->hdl, 'w');

        // Write the CSV header row
        fwrite($file, '"handle","name","address","state","zip","city","country","email","phone","fax"'."\n");

        // Write the data rows to the file
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Join the values with a comma separator and surround them with double quotes
            $line = '"' . implode('","', $row) . '"';
            // Add the value for fax that does not exist
            $line .= ',"0"';
            // Write the line to the file
            fwrite($file, "$line\n");
        }

        // Close the file
        fclose($file);
    }
}
