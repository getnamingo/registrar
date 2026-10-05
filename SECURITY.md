# Security Policy

Security is a critical part of Namingo Registrar.

Namingo Registrar is designed to manage domain registrations and communicate with registries and other external services. A security issue may therefore affect domain names, registrant information, authentication credentials, registry connections, DNS configuration, or other sensitive operations.

We take security reports seriously and encourage responsible disclosure of any vulnerability that may affect Namingo Registrar or its users.

## Supported Versions

Only actively supported versions of Namingo Registrar receive security fixes.

| Version | Supported |
| ------- | --------- |
| 1.2.7 and later | ✅ Yes |
| Earlier than 1.2.7 | ❌ No |

### Upgrade Notice

**All Namingo Registrar installations should be upgraded to version 1.2.7 or later.**

Versions earlier than **1.2.7 are no longer supported and may contain known security issues or lack security-related improvements included in later releases.**

If you are operating an older installation, please upgrade before requesting support or reporting behavior that may already have been corrected in a supported release.

For production systems, we strongly recommend running the **latest available stable release**, rather than relying only on the minimum supported version.

Security fixes may not be backported to older releases.

---

## Reporting a Vulnerability

**Do not report suspected security vulnerabilities through public GitHub issues, discussions, pull requests, forums, or other public channels.**

Please report vulnerabilities privately by email:

**help@namingo.org**

Use a subject such as:

`Security Vulnerability Report - Namingo Registrar`

Please provide as much information as possible so that we can reproduce, assess, and resolve the issue efficiently.

A useful vulnerability report should include:

- The Namingo Registrar version affected
- Operating system and relevant environment information
- PHP, database, web server, and other relevant software versions
- The affected component, file, endpoint, or function
- A clear description of the vulnerability
- Steps required to reproduce the issue
- Proof-of-concept code or requests, where appropriate
- Authentication or privilege requirements
- The potential security impact
- Whether exploitation requires local or remote access
- Whether you believe the vulnerability is currently being exploited
- Any suggested mitigation or fix, if available
- Your preferred name or organization for acknowledgment, if desired

Please remove or redact unnecessary personal data, passwords, API credentials, registry credentials, private keys, authentication tokens, and other secrets before submitting a report.

If sensitive credentials are required to demonstrate an issue, please contact us first so that an appropriate method of sharing the information can be arranged.

---

## Vulnerabilities We Want to Know About

Examples of issues that should be reported privately include, but are not limited to:

- Authentication bypasses
- Authorization or privilege escalation vulnerabilities
- Remote code execution
- SQL injection
- Command injection
- Server-side request forgery (SSRF)
- Cross-site scripting (XSS)
- Cross-site request forgery (CSRF) with meaningful security impact
- Path traversal
- Arbitrary file access, modification, upload, or deletion
- Insecure deserialization
- Authentication token or session vulnerabilities
- Password reset vulnerabilities
- Exposure of credentials, API keys, EPP credentials, or other secrets
- Unauthorized access to registrant or customer information
- Unauthorized domain registration, renewal, transfer, deletion, or modification
- Unauthorized nameserver or DNSSEC changes
- Vulnerabilities affecting registry or registrar communications
- Improper validation that creates a meaningful security boundary bypass
- Cryptographic implementation weaknesses
- Security-sensitive race conditions
- Vulnerabilities in bundled dependencies that are exploitable through Namingo Registrar
- Installation or upgrade behavior that causes sensitive information to become publicly accessible

If you are unsure whether something qualifies as a security vulnerability, please report it privately rather than publishing it.

---

## Issues That Are Generally Not Security Vulnerabilities

The following normally do not require private security reporting unless they can be demonstrated to have a meaningful security impact:

- General software bugs without a security consequence
- Missing functionality or feature requests
- User-interface issues
- Recommendations for additional security hardening
- Vulnerabilities that exist only because documented security requirements were intentionally disabled
- Attacks requiring unrestricted administrative or operating-system access when no additional privilege can be gained
- Missing security headers without a demonstrated exploitable impact
- Self-XSS without a realistic attack against another user
- Clickjacking on pages that do not perform sensitive actions
- Denial-of-service reports based only on extremely unrealistic traffic volumes
- Issues affecting unsupported versions only
- Vulnerabilities exclusively in third-party software where Namingo Registrar does not expose or worsen the vulnerable behavior

Normal bugs may be submitted through the project's regular GitHub issue tracker.

---

## What Happens After You Report an Issue

After receiving a security report, we will make a reasonable effort to:

1. **Acknowledge the report within 48 hours.**
2. Review the report and attempt to reproduce the vulnerability.
3. Determine affected versions and components.
4. Assess the severity and potential impact.
5. Develop and test a fix or mitigation where necessary.
6. Prepare an updated release.
7. Coordinate disclosure with the reporter where appropriate.
8. Publish a security advisory after users have had a reasonable opportunity to update.

Complex vulnerabilities, upstream dependency issues, or reports requiring coordination with third parties may require additional time.

We may contact the reporter for further technical information during the investigation.

---

## Coordinated Disclosure

We ask security researchers and users to give us a reasonable opportunity to investigate and resolve a vulnerability before making technical details public.

Please do not publicly disclose:

- Exploit code
- Detailed reproduction instructions
- Vulnerable endpoints or parameters
- Credentials or confidential information
- Information that would materially simplify exploitation

until a fix has been released or disclosure has otherwise been coordinated with the Namingo team.

Once the issue has been resolved, we aim to disclose relevant security information transparently. Depending on the severity of the issue, this may include:

- A GitHub Security Advisory
- Release notes
- Upgrade instructions
- Mitigation instructions
- A CVE identifier, where appropriate
- Credit to the reporter, if requested and appropriate

We may delay publication of detailed exploitation information when immediate disclosure would create an unreasonable risk to installations that have not yet been upgraded.

---

## Security Advisories and Updates

Security fixes are normally distributed through new Namingo Registrar releases.

Administrators are responsible for keeping their installations up to date.

We strongly recommend:

- Monitoring Namingo Registrar releases
- Reviewing security advisories and release notes
- Applying security updates promptly
- Keeping PHP, the database server, web server, operating system, and dependencies supported and updated
- Restricting administrative access
- Protecting registry, EPP, database, API, and other credentials
- Using HTTPS for all production interfaces
- Maintaining tested backups
- Monitoring logs for suspicious activity

**Running an unsupported version of Namingo Registrar is strongly discouraged.**

At present, the minimum supported release is:

**Namingo Registrar 1.2.7**

If you are running an earlier release, **upgrade to 1.2.7 or later as soon as possible.**

---

## Third-Party Components

Namingo Registrar depends on third-party libraries and may interact with third-party services, registries, registrars, DNS providers, payment providers, and other systems.

A vulnerability in a third-party component should be reported directly to that project's security team when the issue exists independently of Namingo Registrar.

However, please report the issue to us as well if:

- Namingo Registrar exposes the vulnerable component in an exploitable manner
- Namingo Registrar uses the component insecurely
- A dependency vulnerability requires a Namingo Registrar update
- The vulnerability creates additional impact specifically within Namingo Registrar

We may coordinate with upstream maintainers when necessary.

---

## Security of Registry and External Credentials

Namingo Registrar installations may contain highly sensitive credentials, including credentials used to communicate with domain registries and other external systems.

If you discover exposed credentials while investigating a vulnerability:

1. Do not use them except to the minimum extent necessary to establish that exposure exists.
2. Do not access unrelated accounts, domains, registrant data, or infrastructure.
3. Do not include active credentials in public reports.
4. Notify us immediately so that affected credentials can be rotated.

Researchers should avoid making real domain modifications, transfers, deletions, DNS changes, or other irreversible operations when demonstrating a vulnerability.

---

## Testing and Responsible Research

We welcome good-faith security research.

When testing Namingo Registrar, please:

- Use systems and accounts that you own or have explicit permission to test
- Avoid accessing data belonging to other users
- Avoid disrupting production services
- Avoid modifying or deleting real domain registrations
- Avoid destructive testing
- Avoid denial-of-service testing against infrastructure you do not own
- Stop testing if you encounter confidential data beyond what is necessary to demonstrate the vulnerability
- Report the issue privately as soon as reasonably possible

Do not attempt to gain persistent access, pivot into unrelated systems, install malware, or use discovered vulnerabilities for any purpose other than responsible security research.

---

## Safe Harbor for Good-Faith Research

We consider security research conducted in accordance with this policy to be authorized good-faith research.

If you:

- Make a genuine effort to comply with this policy
- Avoid harming users or infrastructure
- Access only the minimum information necessary to demonstrate the issue
- Report vulnerabilities promptly and privately
- Give us a reasonable opportunity to fix the vulnerability before public disclosure

we will not pursue action against you solely for performing that research.

This safe-harbor statement does not authorize testing against infrastructure, systems, registries, services, or accounts belonging to third parties without their permission.

---

## Vulnerability Severity

We assess vulnerabilities based on factors including:

- Required privileges
- Attack complexity
- Whether exploitation can occur remotely
- Confidentiality impact
- Integrity impact
- Availability impact
- Impact on domain registrations or DNS
- Exposure of registrant information or credentials
- Potential for privilege escalation
- Number of installations potentially affected
- Whether exploitation is already occurring

Critical vulnerabilities may result in an expedited release and security advisory.

---

## Security Fixes

For security-sensitive changes, we may intentionally limit technical details in commits, pull requests, or release notes until an updated version has been made available.

Depending on the issue, fixes may be developed privately before being published in the main repository.

Users should not rely on reviewing public commits as their primary method of identifying security updates.

Always follow official Namingo Registrar releases and security notices.

---

## Bug Bounty

Namingo Registrar does **not currently operate a paid bug bounty program** unless explicitly announced otherwise.

Submitting a vulnerability report does not create an entitlement to payment or other compensation.

We may acknowledge researchers who responsibly disclose valid vulnerabilities, subject to their consent.

---

## Security Support for Unsupported Versions

Versions earlier than **1.2.7 are unsupported**.

Security issues reported against older versions may be reviewed to determine whether supported versions are affected, but fixes will normally be provided only for supported releases.

Users of older versions may be asked to upgrade before further investigation.

**Please upgrade to Namingo Registrar 1.2.7 or later before deploying or continuing to operate the software in production.**

---

## Contact

Security reports:

**help@namingo.org**

General bugs, documentation issues, and feature requests may be reported through the normal project issue tracker.

Please keep suspected vulnerabilities private until coordinated disclosure has taken place.

Thank you for helping keep Namingo Registrar and its users secure.