# ERRP DNS interruption service

This directory contains a minimal DNS and web setup for the DNS interruption performed by `automation/errp_dns.php` under the ICANN Expired Registration Recovery Policy (ERRP).

The design is intentionally independent of WHMCS, FOSSBilling and LOOM. The registrar automation changes an expired domain's delegation to the configured interruption nameservers. CoreDNS then resolves requests for that domain to the interruption web IP, and Caddy serves a page that conspicuously identifies the expiration and tells the registrant how to renew.

No database, API or domain synchronization is required on the DNS/web host.

## Files

- `Corefile.example` - catch-all authoritative CoreDNS configuration.
- `Caddyfile.example` - block to add to an existing Caddyfile.
- `index.html` - standalone expiration and renewal page.

## Recommended layout

A small deployment can use one host with two dedicated public IPv4 addresses:

```text
203.0.113.10  -> ns1.expired.registrar.example
203.0.113.11  -> ns2.expired.registrar.example

CoreDNS :53/udp + :53/tcp on both addresses
Caddy   :80/tcp on both addresses
```

Two addresses on one host satisfy the practical two-nameserver configuration used by the registrar automation, but they are not high availability. Registrars that require infrastructure redundancy should place the nameservers on independent hosts/networks.

The interruption addresses should be dedicated to this purpose. This makes the Caddy catch-all safe to add alongside the registrar's existing sites.

## 1. Prepare the nameservers

Choose two hostnames controlled by the registrar, for example:

```text
ns1.expired.registrar.example
ns2.expired.registrar.example
```

Create A records for them pointing to the two public interruption IPs. If the nameserver hostnames are below a domain that requires registry glue, create the corresponding host/glue records with that registry as well.

Allow inbound DNS on UDP and TCP port 53 and HTTP on TCP port 80.

## 2. Install CoreDNS

Install CoreDNS using the package/release method appropriate for the operating system. This repository does not ship another service unit; use the CoreDNS service supplied by your package/deployment.

Copy the example configuration to the location used by that installation, commonly `/etc/coredns/Corefile`:

```bash
sudo mkdir -p /etc/coredns
sudo cp dns/Corefile.example /etc/coredns/Corefile
```

Edit it and replace:

- `203.0.113.10` and `203.0.113.11` with the two interruption IPs.
- the A answer address with the IP on which Caddy should serve the expiration page.

The configuration answers every A query with the interruption web IP, returns a null MX (`0 .`) so the interrupted domain does not direct mail to the web host, and returns authoritative empty answers for other record types. CoreDNS' `template` plugin supports `ANY` as a wildcard query type, so no zone needs to be generated for each expired domain.

Validate/restart CoreDNS using the commands supplied by your installation.

Example checks:

```bash
dig @203.0.113.10 example.com A
dig @203.0.113.11 example.net A
dig @203.0.113.10 example.com MX
```

The A queries should return the configured interruption web IP.

## 3. Add the expiration page to Caddy

Create the document root and copy the supplied page:

```bash
sudo mkdir -p /var/www/namingo-expired
sudo cp dns/index.html /var/www/namingo-expired/index.html
```

Before deployment, edit `index.html` and replace these placeholders:

```text
https://registrar.example.com/clientarea/
https://registrar.example.com/contact/
```

The first URL should lead the registrant to the registrar account/domain-renewal flow. The second should lead to registrar support.

Then append the block from `Caddyfile.example` to the existing `/etc/caddy/Caddyfile` and replace the two example IP addresses.

The important part is:

```caddyfile
http:// {
    bind 203.0.113.10 203.0.113.11
    root * /var/www/namingo-expired
    try_files {path} /index.html
    file_server
}
```

`http://` is a hostless HTTP catch-all, so it accepts requests whose Host header is any interrupted customer domain. `bind` confines that catch-all to the dedicated interruption IPs and avoids making it the fallback site on the registrar's normal addresses.

Validate and reload Caddy:

```bash
sudo caddy validate --config /etc/caddy/Caddyfile
sudo systemctl reload caddy
```

A useful local test, before changing any real delegation, is:

```bash
curl -H 'Host: expired-example.test' http://203.0.113.10/
```

It should return the expiration page.

## 4. Configure Namingo Registrar

In `automation/config.php`, configure the interruption nameservers already supported by `errp_dns.php`:

```php
'errp' => [
    'nameservers' => [
        'ns1.expired.registrar.example',
        'ns2.expired.registrar.example',
    ],
    'tlds' => [],
],
```

`automation/errp_dns.php` remains responsible for deciding which domains are in scope, saving their original nameservers, changing the registry delegation, and restoring the original DNS after renewal. The service in this directory has no registrar database access and does not need a list of expired domains.

## 5. End-to-end test

Before production use, test with a controlled domain:

1. Delegate it to the two interruption nameservers.
2. Confirm both nameservers answer over UDP and TCP.
3. Confirm `dig domain.example A` returns the interruption web IP.
4. Open `http://domain.example/` and verify that the expiration/renewal notice is displayed.
5. Restore the original nameservers and confirm normal resolution returns.

## HTTPS

This example intentionally serves the interruption page over HTTP only. Caddy cannot present a valid certificate for arbitrary unrelated expired domains without obtaining certificates dynamically for those domains. That adds certificate issuance, validation, abuse and rate-limit concerns that are unnecessary for the ERRP interruption page.

Do not enable catch-all On-Demand TLS merely for this feature unless the operational and certificate-policy implications have been reviewed separately.

## DNSSEC warning

A domain with a DS record at the parent can fail DNSSEC validation after its authoritative nameservers are replaced if the interruption service is not serving a matching signed zone. In that case validating resolvers may return `SERVFAIL` before a browser can reach this page.

DNSSEC handling therefore belongs in the registrar-side ERRP interruption/restoration workflow: any required DS/DNSSEC state must be handled safely and restored together with the registrant's original DNS configuration. Do not assume that changing nameservers alone is sufficient for DNSSEC-enabled domains.

## ERRP page requirement

The supplied page explicitly states that the registration has expired and gives the registrant renewal instructions. Keep those two elements conspicuous if the HTML is customized.
