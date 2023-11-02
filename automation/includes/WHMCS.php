<?php
/**
 * Namingo Registrar Escrow
 *
 * Written in 2023 by Taras Kondratyuk (https://namingo.org/)
 *
 * @license MIT
*/

namespace Namingo\Registrar;

class WHMCS
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
        // Query the WHMCS database for the domain data
        $stmt = $this->pdo->prepare("SELECT d.domain AS domain, d.status, d.registrationdate, d.expirydate, d.nextduedate, c.id AS aid
                                      FROM tbldomains d
                                      JOIN tblclients c ON d.userid = c.id");
        $stmt->execute();

        // Open the file for writing
        $file = fopen($this->full, 'w');

        // Write the CSV header row
        fwrite($file, '"domain","status","registration_date","expiry_date","next_due_date","rt-handle","tc-handle","ac-handle","bc-handle","prt-handle","ptc-handle","pac-handle","pbc-handle"' . "\n");

        // Write the data rows to the file
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $line = "\"{$row['domain']}\",\"{$row['status']}\",\"{$row['registrationdate']}\",\"{$row['expirydate']}\",\"{$row['nextduedate']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\",\"{$row['aid']}\"";
            fwrite($file, "$line\n");
        }

        // Close the file
        fclose($file);
    }

    public function generateHDL(): void
    {
        // Query the WHMCS database for the registrant data
        $stmt = $this->pdo->prepare("SELECT id AS aid, CONCAT(firstname, ' ', lastname) AS name, address1, state, postcode, city, country, email, phonenumber AS phone FROM tblclients");
        $stmt->execute();

        // Open the file for writing
        $file = fopen($this->hdl, 'w');

        // Write the CSV header row
        fwrite($file, '"handle","name","address","state","zip","city","country","email","phone","fax"'."\n");

        // Write the data rows to the file
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            // Ensure the phone number starts with a single '+'
            if (isset($row['phone'])) {
                $row['phone'] = '+' . ltrim($row['phone'], '+');
            }
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
