<?php

namespace Registrar\Escrow;

class LOOM implements EscrowInterface {
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
        $stmt = $this->pdo->prepare("
            SELECT service_name, config, 
                   DATE_FORMAT(`expires_at`, '%Y-%m-%dT%H:%i:%sZ') AS exdate
            FROM services
            WHERE service_type = 'domain'
        ");
        $stmt->execute();

        $file = fopen($this->full, 'w');

        fwrite($file, '"domain","ns1","ns2","ns3","ns4","expiration_date","rt-handle","tc-handle","ac-handle","bc-handle","prt-handle","ptc-handle","pac-handle","pbc-handle"' . "\n");

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $config = json_decode($row['config'] ?? '{}', true);

            $domainAscii = idn_to_ascii($row['service_name'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $row['service_name'];

            // Skip ccTLDs (2-letter ASCII TLD)
            $tld = strtolower((string)substr((string)strrchr($domainAscii, '.'), 1));
            if (strlen($tld) === 2 && ctype_alpha($tld)) {
                continue;
            }

            $nameservers = $config['nameservers'] ?? [];
            $ns1 = $nameservers[0] ?? '';
            $ns2 = $nameservers[1] ?? '';
            $ns3 = $nameservers[2] ?? '';
            $ns4 = $nameservers[3] ?? '';

            $contacts = $config['contacts'] ?? [];

            $registrantId = $contacts['registrant']['registry_id'] ?? '';
            $adminId      = $contacts['admin']['registry_id']      ?? '';
            $techId       = $contacts['tech']['registry_id']       ?? '';
            $billingId    = $contacts['billing']['registry_id']    ?? '';

            $line = "\"{$domainAscii}\",\"{$ns1}\",\"{$ns2}\",\"{$ns3}\",\"{$ns4}\",\"{$row['exdate']}\",\"{$registrantId}\",\"{$techId}\",\"{$adminId}\",\"{$billingId}\",\"\",\"\",\"\",\"\"";
            fwrite($file, "$line\n");
        }

        fclose($file);
    }

    public function generateHDL(): void
    {
        // Fetch all services and group by unique registrant registry_id
        $stmt = $this->pdo->prepare("
            SELECT config
            FROM services
            WHERE service_type = 'domain'
        ");
        $stmt->execute();
        $allConfigs = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $handles = [];
        foreach ($allConfigs as $configJson) {
            $config = json_decode($configJson ?? '{}', true);
            $registrant = $config['contacts']['registrant'] ?? [];

            $handle = $registrant['registry_id'] ?? null;
            if (!$handle || isset($handles[$handle])) {
                continue;
            }

            $handles[$handle] = [
                'handle' => $handle,
                'name'   => $registrant['name'] ?? '',
                'address'=> $registrant['street1'] ?? '',
                'state'  => $registrant['sp'] ?? '',
                'zip'    => $registrant['pc'] ?? '',
                'city'   => $registrant['city'] ?? '',
                'country'=> strtoupper($registrant['cc'] ?? ''),
                'email'  => $registrant['email'] ?? '',
                'phone'  => '+' . ltrim($registrant['phone'] ?? '', '+'),
                'fax'    => ''
            ];
        }

        $file = fopen($this->hdl, 'w');
        fwrite($file, '"handle","name","address","state","zip","city","country","email","phone","fax"' . "\n");

        foreach ($handles as $row) {
            $line = '"' . implode('","', $row) . '"';
            fwrite($file, "$line\n");
        }

        fclose($file);
    }

    public function generateRDE(int $ianaID): void
    {
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

        // Fetch all domains with service_type 'domain'
        $stmt = $this->pdo->prepare("
            SELECT service_name, 
                   DATE_FORMAT(expires_at, '%Y-%m-%dT%H:%i:%sZ') AS expire,
                   config
            FROM services
            WHERE service_type = 'domain'
        ");
        $stmt->execute();
        $domains = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($domains as $domain) {
            // Skip ccTLDs (2-letter ASCII TLD)
            $ascii = idn_to_ascii($domain['service_name'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain['service_name'];
            $tld = strtolower((string)substr((string)strrchr($ascii, '.'), 1));
            if (strlen($tld) === 2 && ctype_alpha($tld)) {
                continue;
            }

            $config = json_decode($domain['config'] ?? '{}', true);
            $contacts = $config['contacts'] ?? [];

            $registrant = $contacts['registrant'] ?? [];
            $billing = $contacts['billing'] ?? [];

            $line = [
                idn_to_ascii($domain['service_name'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $domain['service_name'],
                $domain['expire'] ?? '',
                $ianaID,
                $registrant['name'] ?? '',
                $registrant['street1'] ?? '',
                $registrant['city'] ?? '',
                $registrant['sp'] ?? '',
                $registrant['pc'] ?? '',
                strtoupper($registrant['cc'] ?? ''),
                $this->normalizePhone($registrant['phone'] ?? ''),
                $registrant['email'] ?? '',
                $billing['name'] ?? ''
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