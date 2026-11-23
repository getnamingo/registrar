<?php

/*
 * Namingo Registrar
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

// Run from a configured installation with:
// php automation/tests/validation_preview_registry_test.php
// No database queries or external EPP connections are made by this test.
define('NAMINGO_VALIDATION_NO_AUTORUN', true);
require_once dirname(__DIR__) . '/validation_test.php';

final class ValidationPreviewFakeEpp
{
    public int $infoCalls = 0;

    public function domainInfo(array $params): array
    {
        ++$this->infoCalls;
        return [
            'code' => 1000,
            'status' => ['clientHold', 'clientTransferProhibited', 'serverTransferProhibited'],
        ];
    }
}

function requirePreviewStatus(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$GLOBALS['validationPreviewRegistryStatuses'] = [];
$epp = new ValidationPreviewFakeEpp();

// First read sees the real (fake-registry) statuses.
$initial = validationRegistryStatuses($epp, 'Example.TEST.');
requirePreviewStatus(count($initial) === 3, 'Initial registry status read failed');

// Simulated restoration removes the process-owned statuses.
foreach (['clientHold', 'clientTransferProhibited'] as $status) {
    validationEppUpdate($epp, [
        'domainname' => 'example.test', 'command' => 'rem', 'status' => $status,
    ]);
}
$afterRestore = validationRegistryStatuses($epp, 'EXAMPLE.TEST');
requirePreviewStatus($afterRestore === ['serverTransferProhibited'],
    'The restoration overlay did not remove process-owned statuses');

// A later overdue replacement challenge must re-add both statuses, despite
// the real registry still reporting that they were present before restoration.
foreach (['clientHold', 'clientTransferProhibited'] as $status) {
    validationEppUpdate($epp, [
        'domainname' => 'example.test.', 'command' => 'add', 'status' => $status,
    ]);
}
validationEppUpdate($epp, [
    'domainname' => 'example.test', 'command' => 'add', 'status' => 'clientHold',
]);
$afterResuspend = validationRegistryStatuses($epp, 'example.test');
requirePreviewStatus(count($afterResuspend) === 3
    && in_array('clientHold', $afterResuspend, true)
    && in_array('clientTransferProhibited', $afterResuspend, true),
    'Replacement challenge statuses were not restored in the overlay');
requirePreviewStatus($epp->infoCalls === 1, 'The preview re-read stale live EPP statuses');

echo "Validation EPP preview overlay regression test passed.\n";
