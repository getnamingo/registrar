<?php

namespace Registrar\Backend;

interface DriverInterface
{
    public function getWdrpDomains(string $currentDate): array;

    public function hasRdrpNotification(int $domainId, int $year): bool;

    public function storeRdrpNotification(array $data): void;

    public function getValidationEmailRows(): array;

    public function storeValidationEmailToken(array $row, string $token): bool;

    public function getValidationRows(string $registeredAt): array;

    public function getOrCreateValidationToken(array $row): string;

    public function getValidationUrl(string $token): string;

    public function updateValidationNameservers(array $row, string $ns1, string $ns2): void;

    public function updateValidationStatus(array $row): void;

    public function markValidationReminderSent(array $row, mixed $eppResult): void;

    public function getEppConfiguration(string $domain): array;

    public function findRestoredAccuracyNotification(string $domain): ?array;

    public function storeRestoredAccuracyNotification(array $data): int;

    public function updateRestoredAccuracyNotificationMetadata(
        int $id,
        array $metadata
    ): void;

    public function getPendingRestoredAccuracyNotifications(
        int $afterId = 0,
        int $limit = 500
    ): array;

    public function getActiveRestoredAccuracyDomains(): array;

    public function markRestoredAccuracyVerified(
        string $domain,
        ?int $domainId,
        string $contactHash,
        string $verifiedAt,
        string $method,
        ?string $note = null
    ): bool;

    public function findEppPollNotification(
        string $recipient,
        string $accountKey,
        string $msgId
    ): ?array;

    public function storeEppPollNotification(array $data): int;

    public function updateEppPollNotificationMetadata(int $id, array $metadata): void;

    public function getPendingTransferPollNotifications(int $limit = 500): array;

    public function getTransferRegistrant(string $domain): ?array;

    public function createUrsTicket(string $domain, string $provider, string $date): bool;

    public function findUrsNotification(
        string $messageHash,
        string $caseHash
    ): ?array;

    public function storeUrsNotification(array $data): int;

    public function getErrpDomains(): array;

    public function hasErrpNotification(
        int $domainId,
        string $type,
        string $expirationDate
    ): bool;

    public function storeErrpNotification(array $data): void;

    public function findErrpDnsState(
        int $domainId,
        string $expirationDate
    ): ?array;

    public function storeErrpDnsState(array $data): int;

    public function getActiveErrpDnsStates(
        int $limit = 500,
        int $afterId = 0
    ): array;

    public function updateErrpDnsState(int $id, array $metadata): void;

    public function getExpiredDomains(
        int $limit = 500,
        int $afterId = 0
    ): array;

    public function getExpiredDomainPurgeCandidates(
        string $expiredBefore,
        int $limit = 500,
        int $afterId = 0
    ): array;

    public function purgeExpiredDomain(array $row, int $eppResultCode): bool;

    public function getErrpDnsDomain(int $domainId): ?array;

    public function updateErrpDomainNameservers(
        array $row,
        array $nameservers
    ): void;
}
