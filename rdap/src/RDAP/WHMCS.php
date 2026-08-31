<?php
namespace Registrar\RDAP;

use Swoole\Database\PDOProxy;
use \PDO;

class WHMCS implements RdapInterface
{
    public function isValidTLD(PDOProxy $pdo, string $tld): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tbldomainpricing WHERE extension = :tld");
        $stmt->bindParam(':tld', $tld);
        $stmt->execute();
        return $stmt->fetchColumn() > 0;
    }

    public function getDomainByName(PDOProxy $pdo, string $domain): ?array
    {
        $stmt = $pdo->prepare("SELECT *,
                DATE_FORMAT(crdate, '%Y-%m-%dT%H:%i:%sZ')      AS crdate,
                DATE_FORMAT(lastupdate, '%Y-%m-%dT%H:%i:%sZ') AS `update`,
                DATE_FORMAT(exdate, '%Y-%m-%dT%H:%i:%sZ')     AS exdate
            FROM namingo_domain WHERE name = :domain");
        $stmt->bindParam(':domain', $domain);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getContacts(PDOProxy $pdo, string $domain, array $domainDetails): array
    {
        return [
            'registrant' => $this->getContact($pdo, $domainDetails['registrant'], $domain),
            'administrative' => $this->getContact($pdo, $domainDetails['admin'], $domain),
            'technical' => $this->getContact($pdo, $domainDetails['tech'], $domain),
            'billing' => $this->getContact($pdo, $domainDetails['billing'], $domain),
        ];
    }

    private function getContact(PDOProxy $pdo, ?int $id, string $domain = ''): array
    {
        if ($id === null) $id = 0;

        $stmt = $pdo->prepare("SELECT * FROM namingo_contact WHERE id = :id");
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        $contact = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($contact !== false) {
            return $contact;
        }

        if ($domain === '') {
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT
                CASE
                    WHEN tct.id IS NOT NULL THEN tct.country
                    ELSE tc.country
                END AS country,
                CASE
                    WHEN tct.id IS NOT NULL THEN tct.state
                    ELSE tc.state
                END AS state
            FROM tbldomains td
            JOIN tblclients tc
                ON tc.id = td.userid
            LEFT JOIN tblorders o
                ON o.id = td.orderid
            LEFT JOIN tblcontacts tct
                ON tct.id = o.contactid
               AND tct.userid = td.userid
            WHERE td.domain = :domain
            ORDER BY td.id DESC
            LIMIT 1
        ");
        $stmt->bindValue(':domain', $domain);
        $stmt->execute();

        $location = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$location || empty($location['country'])) {
            return [];
        }

        return [
            'cc' => strtoupper($location['country']),
            'sp' => $location['state'] ?? '',
        ];
    }

    public function getDomainStatuses(PDOProxy $pdo, int $domainId): array
    {
        $stmt = $pdo->prepare("SELECT status FROM namingo_domain_status WHERE domain_id = :domain_id");
        $stmt->bindParam(':domain_id', $domainId);
        $stmt->execute();
        $statuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $map = [
            'addPeriod'                 => 'add period',
            'autoRenewPeriod'           => 'auto renew period',
            'clientDeleteProhibited'    => 'client delete prohibited',
            'clientHold'                => 'client hold',
            'clientRenewProhibited'     => 'client renew prohibited',
            'clientTransferProhibited'  => 'client transfer prohibited',
            'clientUpdateProhibited'    => 'client update prohibited',
            'inactive'                  => 'inactive',
            'linked'                    => 'associated',
            'ok'                        => 'active',
            'pendingCreate'             => 'pending create',
            'pendingDelete'             => 'pending delete',
            'pendingRenew'              => 'pending renew',
            'pendingRestore'            => 'pending restore',
            'pendingTransfer'           => 'pending transfer',
            'pendingUpdate'             => 'pending update',
            'redemptionPeriod'          => 'redemption period',
            'renewPeriod'               => 'renew period',
            'serverDeleteProhibited'    => 'server delete prohibited',
            'serverRenewProhibited'     => 'server renew prohibited',
            'serverTransferProhibited'  => 'server transfer prohibited',
            'serverUpdateProhibited'    => 'server update prohibited',
            'serverHold'                => 'server hold',
            'transferPeriod'            => 'transfer period',
        ];

        return $statuses
            ? array_map(fn($status) => $map[$status] ?? $status, $statuses)
            : ['active'];
    }

    public function getNameservers(array $domain): array
    {
        $ns = [];
        for ($i = 1; $i <= 5; $i++) {
            if (!empty($domain["ns$i"])) {
                $ns[] = ['name' => $domain["ns$i"], 'host_id' => $i];
            }
        }
        return $ns;
    }

    public function getDNSSEC(PDOProxy $pdo, int $domainId): array
    {
        $stmt = $pdo->prepare("SELECT key_tag, algorithm, digest_type, digest FROM namingo_domain_dnssec WHERE domain_id = :domain_id");
        $stmt->bindParam(':domain_id', $domainId);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mapContactToVCard(array $contact, string $role, array $config, string $domain): array
    {
        return [
            'objectClassName' => 'entity',
            ...rdapIfNotMinData($config, ['handle' => $contact['identifier'] ?? '']),
            'roles' => [$role],
            'vcardArray' => [
                "vcard",
                [
                    ['version', new \stdClass(), 'text', '4.0'],
                    ["fn", new \stdClass(), 'text', rdapValue($contact['name'] ?? '', $config)],
                    ...rdapIfNotMinData($config, [
                        ["org", new \stdClass(), 'text', rdapValue($contact['org'] ?? '', $config)],
                    ]),
                    ["adr", ["cc" => strtoupper($contact['cc'] ?? '')], 'text', [
                        "",
                        rdapValue($contact['street1'] ?? '', $config), // Extended address
                        rdapValue($contact['street2'] ?? '', $config), // Street address
                        rdapValue($contact['city'] ?? '', $config),    // Locality
                        $contact['sp'] ?? '',      // Region
                        rdapValue($contact['pc'] ?? '', $config),      // Postal code
                        ""
                    ]],
                    ...rdapIfNotMinData($config, [
                        ["tel", ["type" => "voice"], 'text', rdapValue($contact['voice'] ?? '', $config)],
                        ["tel", ["type" => "fax"], 'text', rdapValue($contact['fax'] ?? '', $config)],
                    ]),
                    rdapEmailOrContactUriProp($contact['email'] ?? '', $config, $domain),
                ]
            ],
        ];
    }

    public function getDomainHandle(array $domain): string
    {
        return (string) $domain['registry_domain_id'];
    }
}