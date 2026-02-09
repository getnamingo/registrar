# ICANN Transfer Notification for WHMCS

1. Copy `/opt/registrar/docs/hooks/namingo_transfers.php` to `/var/www/html/whmcs/includes/hooks/namingo_transfers.php`
 
2. In WHMCS Admin create **3 email templates** by going to Configuration → Email Templates → Domain and create:

## Domain Transfer Requested

Subject:

`Transfer Request for {$domain}`

Body example:

```
A transfer has been requested for your domain: {$domain}

If this was not authorized, please contact support immediately.
```

## Domain Transfer Completed

Subject:

`Transfer Completed for {$domain}`

Body example:

```
Your domain transfer has been completed successfully.

Domain: {$domain}
```

## Domain Transfer Failed

Subject:

`Transfer Failed for {$domain}`

Body example:

```
Unfortunately, the transfer for {$domain} has failed.

Please contact support if you need assistance.
```