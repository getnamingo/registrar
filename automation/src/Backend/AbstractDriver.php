<?php

namespace Registrar\Backend;

use PDO;

abstract class AbstractDriver implements DriverInterface
{
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

    protected function notificationTable(): string
    {
        return 'registrar_notifications';
    }
}