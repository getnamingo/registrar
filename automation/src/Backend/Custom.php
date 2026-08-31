<?php

namespace Registrar\Backend;

use RuntimeException;

/**
 * Example custom backend driver.
 *
 * Replace the methods below with queries for your own billing/backend schema.
 * The automation scripts only depend on DriverInterface, so no cron files need
 * to be changed. You may also copy this file to Acme.php, rename the class to
 * Acme and set $config['escrow']['backend'] = 'Acme'.
 *
 * Normalize returned rows as follows:
 * - WDRP / ERRP: domain_name, expires_at, email
 * - validation rows: domain_name, registrant_email, validation
 * - expired domains: domain_name plus any backend keys needed by the update
 */
class Custom extends AbstractDriver
{
    public function getWdrpDomains(string $currentDate): array
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getValidationEmailRows(): array
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function storeValidationEmailToken(array $row, string $token): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getValidationRows(string $registeredAt): array
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getOrCreateValidationToken(array $row): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getValidationUrl(string $token): string
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function updateValidationNameservers(array $row, string $ns1, string $ns2): void
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function updateValidationStatus(array $row): void
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function markValidationReminderSent(array $row, mixed $eppResult): void
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getEppConfiguration(string $domain): array
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function createUrsTicket(string $domain, string $provider, string $date): bool
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getErrpDomains(): array
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function getExpiredDomains(): array
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    public function updateExpiredDomainNameservers(array $row, string $ns1, string $ns2): void
    {
        throw $this->notImplemented(__FUNCTION__);
    }

    private function notImplemented(string $method): RuntimeException
    {
        return new RuntimeException("Custom backend driver method not implemented: {$method}");
    }
}