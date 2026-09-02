<?php

namespace Registrar\Backend;

use PDO;

abstract class AbstractDriver implements DriverInterface
{
    private const RESTORED_ACCURACY_TYPE = 'restored_accuracy';

    public function __construct(
        protected PDO $pdo,
        protected array $config,
        protected object $log
    ) {
    }

    public function getEppConfigurations(): array
    {
        throw new \RuntimeException(
            static::class . ' does not implement EPP account enumeration.'
        );
    }

    public function findRestoredAccuracyNotification(string $domain): ?array
    {
        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            SELECT id, domain_id, domain, metadata, sent_at
            FROM {$table}
            WHERE type = '" . self::RESTORED_ACCURACY_TYPE . "'
              AND LOWER(domain) = LOWER(:domain)
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.state'))
                    IN ('deleted', 'hold_pending', 'on_hold')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute(['domain' => $domain]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function storeRestoredAccuracyNotification(array $data): int
    {
        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            INSERT INTO {$table} (
                domain_id,
                domain,
                type,
                recipient,
                subject,
                body,
                metadata,
                sent_at
            ) VALUES (
                :domain_id,
                :domain,
                '" . self::RESTORED_ACCURACY_TYPE . "',
                '',
                :subject,
                :body,
                :metadata,
                :sent_at
            )
        ");
        $stmt->execute([
            'domain_id' => $data['domain_id'] ?? null,
            'domain' => (string)$data['domain'],
            'subject' => (string)($data['subject'] ?? 'Restored Names Accuracy state'),
            'body' => (string)($data['body'] ?? 'Restored Names Accuracy lifecycle state.'),
            'metadata' => $this->encodeNotificationMetadata($data['metadata'] ?? []),
            'sent_at' => (string)$data['sent_at'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateRestoredAccuracyNotificationMetadata(
        int $id,
        array $metadata
    ): void {
        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            UPDATE {$table}
            SET metadata = :metadata
            WHERE id = :id
              AND type = '" . self::RESTORED_ACCURACY_TYPE . "'
        ");
        $stmt->execute([
            'metadata' => $this->encodeNotificationMetadata($metadata),
            'id' => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            $check = $this->pdo->prepare("
                SELECT 1
                FROM {$table}
                WHERE id = :id
                  AND type = '" . self::RESTORED_ACCURACY_TYPE . "'
            ");
            $check->execute(['id' => $id]);
            if (!$check->fetchColumn()) {
                throw new \RuntimeException(
                    "Restored Names Accuracy notification not found: {$id}"
                );
            }
        }
    }

    public function getPendingRestoredAccuracyNotifications(
        int $afterId = 0,
        int $limit = 500
    ): array {
        $table = $this->notificationTable();
        $limit = max(1, min($limit, 1000));
        $stmt = $this->pdo->prepare("
            SELECT id, domain_id, domain, metadata, sent_at
            FROM {$table}
            WHERE type = '" . self::RESTORED_ACCURACY_TYPE . "'
              AND id > :after_id
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.state'))
                    IN ('hold_pending', 'on_hold')
            ORDER BY id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':after_id', max(0, $afterId), PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveRestoredAccuracyDomains(): array
    {
        $table = $this->notificationTable();
        $stmt = $this->pdo->query("
            SELECT DISTINCT LOWER(domain)
            FROM {$table}
            WHERE type = '" . self::RESTORED_ACCURACY_TYPE . "'
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.state'))
                    IN ('deleted', 'hold_pending', 'on_hold')
        ");

        return array_values(array_filter(array_map(
            'strval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        )));
    }

    public function markRestoredAccuracyVerified(
        string $domain,
        ?int $domainId,
        string $contactHash,
        string $verifiedAt,
        string $method,
        ?string $note = null
    ): bool {
        $row = $this->findRestoredAccuracyNotification($domain);
        if ($row === null) {
            return false;
        }

        $metadata = json_decode((string)($row['metadata'] ?? ''), true);
        if (!is_array($metadata)) {
            throw new \RuntimeException(
                'Invalid Restored Names Accuracy metadata for ' . $domain
            );
        }

        $deletedAtValue = (string)($metadata['deleted_at'] ?? '');
        if ($deletedAtValue === '' || $verifiedAt === '') {
            throw new \RuntimeException(
                'Missing Restored Names Accuracy timestamp for ' . $domain
            );
        }

        try {
            $deletedAt = new \DateTimeImmutable($deletedAtValue);
            $verified = new \DateTimeImmutable($verifiedAt);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Invalid Restored Names Accuracy verification timestamp for ' . $domain,
                0,
                $e
            );
        }

        if ($verified <= $deletedAt) {
            return false;
        }

        $deletedHash = (string)($metadata['deletion_contact_hash'] ?? '');
        $confirmedMethods = ['email', 'manual', 'external_hook'];
        $freshConfirmation = in_array($method, $confirmedMethods, true);
        $contactChanged = $deletedHash !== ''
            && $contactHash !== ''
            && !hash_equals($deletedHash, $contactHash);

        if (!$freshConfirmation && !$contactChanged) {
            return false;
        }

        $existing = is_array($metadata['verification'] ?? null)
            ? $metadata['verification']
            : [];
        if (($existing['verified_at'] ?? null) === $verifiedAt
            && ($existing['contact_hash'] ?? null) === $contactHash
            && ($existing['method'] ?? null) === $method
            && ($existing['note'] ?? null) === $note) {
            return false;
        }

        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            UPDATE {$table}
            SET domain_id = COALESCE(domain_id, :domain_id),
                metadata = JSON_SET(
                    COALESCE(metadata, JSON_OBJECT()),
                    '$.verification', JSON_OBJECT(
                        'contact_hash', :contact_hash,
                        'method', :method,
                        'verified_at', :verified_at,
                        'note', :note
                    )
                )
            WHERE id = :id
              AND type = '" . self::RESTORED_ACCURACY_TYPE . "'
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.state'))
                    IN ('deleted', 'hold_pending', 'on_hold')
        ");
        $stmt->bindValue(':domain_id', $domainId, $domainId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':contact_hash', $contactHash);
        $stmt->bindValue(':method', $method);
        $stmt->bindValue(':verified_at', $verifiedAt);
        $stmt->bindValue(':note', $note, $note === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':id', (int)$row['id'], PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    public function findEppPollNotification(
        string $recipient,
        string $accountKey,
        string $msgId
    ): ?array {
        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            SELECT id, metadata
            FROM {$table}
            WHERE type = 'epp_poll'
              AND recipient = :recipient
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.epp.account_key')) = :account_key
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.epp.msg_id')) = :msg_id
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'recipient' => $recipient,
            'account_key' => $accountKey,
            'msg_id' => $msgId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function storeEppPollNotification(array $data): int
    {
        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            INSERT INTO {$table} (
                domain_id,
                domain,
                type,
                recipient,
                subject,
                body,
                metadata,
                sent_at
            ) VALUES (
                NULL,
                :domain,
                'epp_poll',
                :recipient,
                :subject,
                :body,
                :metadata,
                :sent_at
            )
        ");
        $stmt->execute([
            'domain' => (string)($data['domain'] ?? ''),
            'recipient' => (string)$data['recipient'],
            'subject' => (string)$data['subject'],
            'body' => (string)$data['body'],
            'metadata' => json_encode(
                $data['metadata'],
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            ),
            'sent_at' => (string)$data['sent_at'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updateEppPollNotificationMetadata(int $id, array $metadata): void
    {
        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            UPDATE {$table}
            SET metadata = :metadata
            WHERE id = :id
              AND type = 'epp_poll'
        ");
        $stmt->execute([
            'metadata' => json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            ),
            'id' => $id,
        ]);
    }

    public function getPendingTransferPollNotifications(int $limit = 500): array
    {
        $table = $this->notificationTable();
        $limit = max(1, min($limit, 1000));

        $stmt = $this->pdo->prepare("
            SELECT id, domain, recipient, metadata, sent_at
            FROM {$table}
            WHERE type = 'epp_poll'
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.epp.event')) = 'domain_transfer'
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.epp.transfer.our_role')) = 'losing'
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.epp.transfer.status')) = 'pending'
              AND JSON_EXTRACT(metadata, '$.transfer_policy.foa.sent_at') IS NULL
            ORDER BY id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findUrsNotification(
        string $messageHash,
        string $caseHash
    ): ?array {
        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            SELECT id, domain, metadata, sent_at
            FROM {$table}
            WHERE type = 'urs'
              AND (
                    JSON_UNQUOTE(
                        JSON_EXTRACT(metadata, '$.urs.message_hash')
                    ) = :message_hash
                 OR (
                        :has_case_hash = 1
                    AND JSON_UNQUOTE(
                            JSON_EXTRACT(metadata, '$.urs.case_hash')
                        ) = :case_hash
                 )
              )
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'message_hash' => $messageHash,
            'has_case_hash' => $caseHash !== '' ? 1 : 0,
            'case_hash' => $caseHash,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function storeUrsNotification(array $data): int
    {
        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            INSERT INTO {$table} (
                domain_id,
                domain,
                type,
                recipient,
                subject,
                body,
                metadata,
                sent_at
            ) VALUES (
                NULL,
                :domain,
                'urs',
                :recipient,
                :subject,
                :body,
                :metadata,
                :sent_at
            )
        ");
        $stmt->execute([
            'domain' => (string)$data['domain'],
            'recipient' => (string)$data['recipient'],
            'subject' => (string)$data['subject'],
            'body' => (string)$data['body'],
            'metadata' => $this->encodeNotificationMetadata($data['metadata']),
            'sent_at' => (string)$data['sent_at'],
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function findErrpDnsState(
        int $domainId,
        string $expirationDate
    ): ?array {
        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            SELECT id, domain_id, domain, metadata, sent_at, created_at
            FROM {$table}
            WHERE type = 'errp_dns'
              AND domain_id = :domain_id
              AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.expires_at')) = :expires_at
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            'domain_id' => $domainId,
            'expires_at' => $expirationDate,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function storeErrpDnsState(array $data): int
    {
        $table = $this->notificationTable();
        $metadata = $data['metadata'];
        $expirationDate = (string)($metadata['expires_at'] ?? '');

        if ($expirationDate === '') {
            throw new \InvalidArgumentException(
                'ERRP DNS state requires an expiration date.'
            );
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO {$table} (
                domain_id,
                domain,
                type,
                recipient,
                subject,
                body,
                metadata,
                sent_at
            )
            SELECT
                :domain_id,
                :domain,
                'errp_dns',
                '',
                'ERRP DNS lifecycle state',
                '',
                :metadata,
                :sent_at
            WHERE NOT EXISTS (
                SELECT 1
                FROM {$table}
                WHERE type = 'errp_dns'
                  AND domain_id = :existing_domain_id
                  AND JSON_UNQUOTE(
                        JSON_EXTRACT(metadata, '$.expires_at')
                      ) = :existing_expires_at
            )
        ");
        $stmt->execute([
            'domain_id' => (int)$data['domain_id'],
            'domain' => (string)$data['domain'],
            'metadata' => json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            ),
            'sent_at' => (string)$data['sent_at'],
            'existing_domain_id' => (int)$data['domain_id'],
            'existing_expires_at' => $expirationDate,
        ]);

        if ($stmt->rowCount() > 0) {
            return (int)$this->pdo->lastInsertId();
        }

        $existing = $this->findErrpDnsState(
            (int)$data['domain_id'],
            $expirationDate
        );

        if ($existing === null) {
            throw new \RuntimeException('Unable to create or find ERRP DNS state.');
        }

        return (int)$existing['id'];
    }

    public function getActiveErrpDnsStates(
        int $limit = 500,
        int $afterId = 0
    ): array
    {
        $table = $this->notificationTable();
        $limit = max(1, min($limit, 1000));
        $stmt = $this->pdo->prepare("
            SELECT id, domain_id, domain, metadata, sent_at, created_at
            FROM {$table}
            WHERE type = 'errp_dns'
              AND id > :after_id
              AND COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.state')),
                    ''
                  ) NOT IN ('restored', 'closed', 'not_applicable')
            ORDER BY id ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':after_id', max(0, $afterId), PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateErrpDnsState(int $id, array $metadata): void
    {
        $table = $this->notificationTable();
        $stmt = $this->pdo->prepare("
            UPDATE {$table}
            SET metadata = :metadata
            WHERE id = :id
              AND type = 'errp_dns'
        ");
        $stmt->execute([
            'metadata' => json_encode(
                $metadata,
                JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_THROW_ON_ERROR
            ),
            'id' => $id,
        ]);
    }

    final public function purgeExpiredDomain(
        array $row,
        int $eppResultCode
    ): bool {
        if (!in_array($eppResultCode, [2201, 2303], true)) {
            throw new \InvalidArgumentException(
                'A domain may only be purged after registry absence is confirmed.'
            );
        }

        $domainId = (int)($row['id'] ?? 0);
        $domain = strtolower(rtrim(trim((string)(
            $row['domain_name'] ?? ''
        )), '.'));
        $rawExpiration = trim((string)($row['expires_at'] ?? ''));

        if ($domainId < 1 || $domain === '' || $rawExpiration === '') {
            throw new \InvalidArgumentException('Malformed domain purge candidate.');
        }

        try {
            $expiration = (new \DateTimeImmutable(
                $rawExpiration,
                new \DateTimeZone('UTC')
            ))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                "Invalid expiration date for {$domain}.",
                0,
                $e
            );
        }

        if ($this->pdo->inTransaction()) {
            throw new \RuntimeException(
                'Domain purge cannot run inside an existing transaction.'
            );
        }

        $purgedAt = gmdate('Y-m-d H:i:s');
        $reason = $eppResultCode === 2303
            ? 'registry_object_does_not_exist'
            : 'registry_no_longer_sponsoring_client';

        $this->pdo->beginTransaction();

        try {
            $snapshot = $this->expiredDomainPurgeSnapshot($row);

            if (!$this->deleteExpiredDomainData($row, $domain, $purgedAt)) {
                $this->pdo->rollBack();

                return false;
            }

            $retainUntil = (new \DateTimeImmutable(
                $purgedAt,
                new \DateTimeZone('UTC')
            ))->modify('+15 months')->format('Y-m-d H:i:s');
            $this->storeErrpNotification([
                'domain_id' => $domainId,
                'domain' => $domain,
                'type' => 'domain_purge',
                'recipient' => '',
                'subject' => 'Expired domain purge record',
                'body' => '',
                'metadata' => [
                    'policy' => 'ICANN Registration Data Policy 12',
                    'state' => 'purged',
                    'reason' => $reason,
                    'registry_result_code' => $eppResultCode,
                    'expires_at' => $expiration,
                    'purged_at' => $purgedAt,
                    'retain_until' => $retainUntil,
                    'record' => $snapshot,
                ],
                'sent_at' => $purgedAt,
            ]);
            $this->closeErrpDnsStateAfterPurge(
                $domainId,
                $expiration,
                $eppResultCode,
                $reason,
                $purgedAt
            );
            $this->closeRestoredAccuracyStateAfterPurge(
                $domain,
                $eppResultCode,
                $reason,
                $purgedAt
            );
            $this->pdo->commit();

            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    protected function expiredDomainPurgeSnapshot(array $row): array
    {
        return $this->redactDomainPurgeSnapshot($row);
    }

    protected function deleteExpiredDomainData(
        array $row,
        string $domain,
        string $purgedAt
    ): bool {
        throw new \RuntimeException(
            static::class . ' does not implement expired-domain deletion.'
        );
    }

    private function closeErrpDnsStateAfterPurge(
        int $domainId,
        string $expiration,
        int $eppResultCode,
        string $reason,
        string $purgedAt
    ): void {
        $state = $this->findErrpDnsState($domainId, $expiration);

        if ($state === null) {
            return;
        }

        $metadata = json_decode((string)($state['metadata'] ?? ''), true);

        if (!is_array($metadata)) {
            throw new \RuntimeException(
                "Invalid ERRP DNS state for purged domain {$domainId}."
            );
        }

        if (in_array(
            (string)($metadata['state'] ?? ''),
            ['restored', 'closed', 'not_applicable'],
            true
        )) {
            return;
        }

        $metadata['state'] = 'closed';
        $metadata['closed_reason'] = $reason;
        $metadata['registry_result_code'] = $eppResultCode;
        $metadata['closed_at'] = str_replace(' ', 'T', $purgedAt) . 'Z';
        $metadata['updated_at'] = $metadata['closed_at'];
        $metadata['last_error'] = null;

        $this->updateErrpDnsState((int)$state['id'], $metadata);
    }

    private function closeRestoredAccuracyStateAfterPurge(
        string $domain,
        int $eppResultCode,
        string $reason,
        string $purgedAt
    ): void {
        $state = $this->findRestoredAccuracyNotification($domain);

        if ($state === null) {
            return;
        }

        $metadata = json_decode((string)($state['metadata'] ?? ''), true);

        if (!is_array($metadata)) {
            throw new \RuntimeException(
                "Invalid Restored Names Accuracy state for {$domain}."
            );
        }

        $metadata['state'] = 'closed';
        $metadata['closed_reason'] = $reason;
        $metadata['registry_result_code'] = $eppResultCode;
        $metadata['closed_at'] = str_replace(' ', 'T', $purgedAt) . 'Z';
        $metadata['last_error'] = null;

        $this->updateRestoredAccuracyNotificationMetadata(
            (int)$state['id'],
            $metadata
        );
    }

    private function redactDomainPurgeSnapshot(
        mixed $value,
        string $key = ''
    ): mixed {
        $normalizedKey = strtolower((string)preg_replace(
            '/[^a-z0-9]/i',
            '',
            $key
        ));
        if (
            $normalizedKey !== ''
            && preg_match(
                '/(?:authcode|authinfo|authinfopw|password|passwd|passphrase|secret|token|transfercode|^pw$)$/',
                $normalizedKey
            )
        ) {
            return '[redacted]';
        }

        if (is_string($value)) {
            $trimmed = ltrim($value);

            if ($trimmed !== '' && in_array($trimmed[0], ['{', '['], true)) {
                try {
                    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

                    if (is_array($decoded)) {
                        return $this->redactDomainPurgeSnapshot($decoded, $key);
                    }
                } catch (\JsonException) {
                    // Preserve non-JSON text as stored by the billing system.
                }
            }

            return $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $childKey => $childValue) {
            $redacted[$childKey] = $this->redactDomainPurgeSnapshot(
                $childValue,
                (string)$childKey
            );
        }

        return $redacted;
    }

    protected function notificationTable(): string
    {
        return 'registrar_notifications';
    }

    private function encodeNotificationMetadata(array $metadata): string
    {
        return json_encode(
            $metadata,
            JSON_UNESCAPED_SLASHES
            | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR
        );
    }
}
