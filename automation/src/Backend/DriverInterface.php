<?php

namespace Registrar\Backend;

interface DriverInterface
{
    public function getWdrpDomains(string $currentDate): array;

    public function getValidationEmailRows(): array;

    public function storeValidationEmailToken(array $row, string $token): bool;

    public function getValidationRows(string $registeredAt): array;

    public function getOrCreateValidationToken(array $row): string;

    public function getValidationUrl(string $token): string;

    public function updateValidationNameservers(array $row, string $ns1, string $ns2): void;

    public function updateValidationStatus(array $row): void;

    public function markValidationReminderSent(array $row, mixed $eppResult): void;

    public function getEppConfiguration(string $domain): array;

    public function createUrsTicket(string $domain, string $provider, string $date): bool;

    public function getErrpDomains(): array;

    public function getExpiredDomains(): array;

    public function updateExpiredDomainNameservers(array $row, string $ns1, string $ns2): void;
}