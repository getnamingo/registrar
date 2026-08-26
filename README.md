# Namingo Registrar

Open source ICANN-accredited domain registrar management system.

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

## Introduction

**Namingo Registrar** is an open-source domain registrar management system built for ICANN-accredited registrars. It helps you manage domains efficiently, stay compliant with ICANN requirements, and reduce operational costs, with integrations for [FOSSBilling](https://fossbilling.org/), [WHMCS](https://www.whmcs.com/), [Loom](https://github.com/getargora/loom), and [PNLCS](https://github.com/Panelica/pnlcs) to fit different billing and operational workflows.

## Get Involved

**Namingo Registrar** is built in the open, and contributions are always welcome. Whether you want to improve the code, refine the interface, expand documentation, report issues, test new features, or suggest better ways of doing things, your input can help make Namingo stronger for everyone.

## Features

- **Billing & Registrar Operations**: Manages domain registrations, renewals, transfers, and payments as an ICANN-accredited registrar, with seamless EPP connectivity to supported registries.

- **Registration Data Directory Services (RDDS) using RDAP**: Supports public access to domain registration data via RDAP, with continued support for legacy WHOIS services.

- **Registration Data Escrow**: Performs automated, encrypted data deposits with [DENIC](https://www.denic-services.de/services/data-escrow), an ICANN-approved escrow agent, in compliance with ICANN requirements.

- **Trademark Clearinghouse (TMCH) Integration**: Implements TMCH Claims verification and Claims-based domain registration. Sunrise workflow is not implemented. *Currently available in the WHMCS integration.*

- **Registration Data Reminder Policy (RDRP)**: Sends periodic reminders to registrants to review and update their registration data.

- **Expired Registration Recovery Policy (ERRP)**: Implements ICANN-compliant expiration, redemption, and deletion workflows.

- **Contact Validation**: Performs ICANN-required and NIS2-compliant registrant contact validation and verification workflows.

- **Transfer Management (IRTP/ITRP)**: Handles inter-registrar domain transfers with secure authorization and policy-compliant workflows.

- **ICANN Transfer Notification**: Provides policy-compliant notifications for transfer requests, completions, and failures in accordance with the ICANN Transfer Policy. *Currently available in the WHMCS integration.*

- **Premium Domain Support**: Provides automated detection and pricing of premium domain names via the EPP Fee Extension during availability checks and registration. *Currently available in the WHMCS integration.*

- **Extended EPP Support**: Implements registry-specific extensions and custom provisioning workflows.

- **ICANN MoSAPI Monitoring**: Provides automated monitoring of registrar status, compliance indicators, and domain abuse statistics through ICANN’s MoSAPI platform.

## Documentation

### Installation

**Minimum requirement:** a VPS running Ubuntu 22.04 / 24.04 / 26.04 or Debian 12 / 13, with at least 1 CPU core, 2 GB RAM, and 10 GB hard drive space.

To get started, copy the command below and paste it into your server terminal:

```bash
bash <(wget -qO- https://namingo.org/registrar-install.sh)
```

For detailed installation steps, see:

- [install-fossbilling.md](docs/install-fossbilling.md) – for FOSSBilling setup  
- [install-whmcs.md](docs/install-whmcs.md) – for WHMCS setup
- [install-loom.md](docs/install-loom.md) – for Loom setup ***(beta)***
- [install-custom.md](docs/install-custom.md) – for Custom Billing Platform setup ***(beta)***
- [configuration.md](docs/configuration.md) – configuration

Need help migrating between **FOSSBilling, WHMCS, Loom, or a custom billing platform**? Contact us at [help@namingo.org](mailto:help@namingo.org) to discuss your migration options.

### Upgrade

> [!IMPORTANT]
> Namingo Registrar v1.2.3 is the last version using the legacy sequential upgrade scripts.
>
> Starting with v1.2.4, all upgrades use the universal [`upgrade.sh`](docs/upgrade.sh) script.

> [!WARNING]
> Upgrading Namingo Registrar does **not** automatically upgrade integrated billing systems such as FOSSBilling, WHMCS, Loom, or custom integrations.
>
> See the full **[Upgrade Guide](docs/upgrade.md)** for billing-system requirements, legacy upgrade paths, and version-specific notes.

#### v1.2.3 and later

Upgrade to the latest Namingo Registrar release with:

```bash
sudo /opt/registrar/docs/upgrade.sh
```

Existing v1.2.3 installations that do not yet have `upgrade.sh` should follow the bootstrap instructions in the **[Upgrade Guide](docs/upgrade.md)**.

#### Older than v1.2.3

Use the legacy sequential upgrade scripts until you reach **v1.2.3**, then use the universal upgrader for all future releases.

See the **[Upgrade Guide](docs/upgrade.md)** for the complete upgrade path.

## Support

Need help, found a bug, or have an idea for Namingo Registrar?

- **Email:** [help@namingo.org](mailto:help@namingo.org)
- **Discord:** Join the community on [Discord](https://discord.gg/97R9VCrWgc)
- **GitHub Issues:** Report bugs or request features in [GitHub Issues](https://github.com/getnamingo/registrar/issues)

Questions, feedback, and contributions are always welcome.

## Acknowledgements

Thanks to [**FOSSBilling**](https://fossbilling.org/), [**WHMCS**](https://www.whmcs.com/), [**Loom**](https://github.com/getargora/loom), and [**PNLCS**](https://github.com/Panelica/pnlcs) for their work on billing platforms supported by Namingo Registrar, and to **ChatGPT** for assistance with code and documentation.

## Support This Project

If you find Namingo Registrar useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

Namingo Registrar is licensed under the MIT License.

### Third-Party Software

For DENIC escrow, Namingo Registrar requires the separately installed [escrow-rde-client](https://gitlab.com/team-escrow/escrow-rde-client), licensed under the [LGPL-3.0](https://www.gnu.org/licenses/lgpl-3.0.html).