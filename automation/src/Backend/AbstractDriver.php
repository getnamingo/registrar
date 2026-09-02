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
