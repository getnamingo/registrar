<?php

use WHMCS\Database\Capsule;

/*
|--------------------------------------------------------------------------
| Notify when a transfer is requested
|--------------------------------------------------------------------------
*/
add_hook('PreRegistrarTransferDomain', 1, function ($vars) {

    $domainId = $vars['domainid'] ?? null;

    if (!$domainId) {
        return;
    }

    $domain = Capsule::table('tbldomains')
        ->where('id', $domainId)
        ->first();

    if (!$domain) {
        return;
    }

    localAPI('SendEmail', [
        'messagename' => 'Domain Transfer Requested',
        'id'          => $domain->userid,
        'customtype'  => 'domain',
        'customvars'  => base64_encode(serialize([
            'domain' => $domain->domain,
        ])),
    ]);
});


/*
|--------------------------------------------------------------------------
| Notify when a transfer is completed
|--------------------------------------------------------------------------
*/
add_hook('DomainTransferCompleted', 1, function ($vars) {

    $domainName = $vars['domain'] ?? '';

    if (!$domainName) {
        return;
    }

    $domain = Capsule::table('tbldomains')
        ->where('domain', $domainName)
        ->first();

    if (!$domain) {
        return;
    }

    localAPI('SendEmail', [
        'messagename' => 'Domain Transfer Completed',
        'id'          => $domain->userid,
        'customtype'  => 'domain',
        'customvars'  => base64_encode(serialize([
            'domain' => $domainName,
        ])),
    ]);
});


/*
|--------------------------------------------------------------------------
| Notify when a transfer fails
|--------------------------------------------------------------------------
*/
add_hook('DomainTransferFailed', 1, function ($vars) {

    $domainName = $vars['domain'] ?? '';

    if (!$domainName) {
        return;
    }

    $domain = Capsule::table('tbldomains')
        ->where('domain', $domainName)
        ->first();

    if (!$domain) {
        return;
    }

    localAPI('SendEmail', [
        'messagename' => 'Domain Transfer Failed',
        'id'          => $domain->userid,
        'customtype'  => 'domain',
        'customvars'  => base64_encode(serialize([
            'domain' => $domainName,
        ])),
    ]);
});
