# Namingo Registrar

Open source ICANN-accredited domain registrar management system.

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

## Introduction

**Namingo Registrar** is an open-source domain registrar management system built for ICANN-accredited registrars. It helps you manage domains efficiently, stay compliant with ICANN requirements, and reduce operational costs.

**Namingo Registrar** integrates with [FOSSBilling](https://fossbilling.org/), [WHMCS](https://www.whmcs.com/), and [Loom](https://github.com/getargora/loom) — allowing you to choose the platform that best suits your needs and workflow.

## Get Involved

We're on a mission to make **Namingo** the best it can be, and we need your expertise! Whether you're adept in development, have a keen eye for design, or simply brim with innovative ideas, your contribution can make a world of difference.

## Features

- **Billing & Registrar Operations**: Manages domain registrations, renewals, transfers, and payments as an ICANN-accredited registrar, with seamless EPP connectivity to supported registries.

- **WHOIS & RDAP Services**: Provides public WHOIS services and modern RDAP endpoints.

- **Registration Data Escrow**: Performs automated, encrypted data deposits with [DENIC](https://www.denic-services.de/services/data-escrow), an ICANN-approved escrow agent, in compliance with ICANN requirements.

- **Trademark Clearinghouse (TMCH) Integration**: Implements TMCH Claims verification and Claims-based domain registration. Sunrise workflow is not implemented. *Currently available in the WHMCS integration.*

- **WHOIS Data Reminder Policy (WDRP)**: Sends periodic reminders to registrants to review and update their registration data.

- **Expired Registration Recovery Policy (ERRP)**: Implements ICANN-compliant expiration, redemption, and deletion workflows.

- **Contact Validation**: Performs ICANN-required registrant contact validation and verification workflows.

- **Transfer Management (IRTP/ITRP)**: Handles inter-registrar domain transfers with secure authorization and policy-compliant workflows.

- **ICANN Transfer Notification**: Provides policy-compliant notifications for transfer requests, completions, and failures in accordance with the ICANN Transfer Policy. *Currently available in the WHMCS integration.*

- **Premium Domain Support**: Provides automated detection and pricing of premium domain names via the EPP Fee Extension during availability checks and registration. *Currently available in the WHMCS integration.*

- **Extended EPP Support**: Implements registry-specific extensions and custom provisioning workflows.

- **ICANN MoSAPI Monitoring**: Provides automated monitoring of registrar status, compliance indicators, and domain abuse statistics through ICANN’s MoSAPI platform. *Currently available in the WHMCS integration.*

## Documentation

### Installation

**Minimum requirement:** a VPS running Ubuntu 22.04 / 24.04 or Debian 12 / 13, with at least 1 CPU core, 2 GB RAM, and 10 GB hard drive space.

To get started, copy the command below and paste it into your server terminal:

```bash
bash <(wget -qO- https://namingo.org/registrar-install.sh)
```

For detailed installation steps, see:

- [install-fossbilling.md](docs/install-fossbilling.md) – for FOSSBilling setup  
- [install-whmcs.md](docs/install-whmcs.md) – for WHMCS setup
- [install-loom.md](docs/install-loom.md) – for Loom setup ***(beta)***

### Update

- v1.1.4 to v1.1.5 - backup registrar, download and run the [update115.sh](docs/update115.sh) script.

- v1.1.3 to v1.1.4 - backup registrar, download and run the [update114.sh](docs/update114.sh) script.

- v1.1.2 to v1.1.3 - backup registrar, download and run the [update113.sh](docs/update113.sh) script.

- v1.1.1 to v1.1.2 - backup registrar, download and run the [update112.sh](docs/update112.sh) script.

- v1.1.0 to v1.1.1 - backup registrar, download and run the [update111.sh](docs/update111.sh) script.

- v1.0.5 to v1.1.0 - backup registrar, download and run the [update110.sh](docs/update110.sh) script.

- v1.0.4 to v1.0.5 - backup registrar, download and run the [update105.sh](docs/update105.sh) script.

- v1.0.3 to v1.0.4 - backup registrar, download and run the [update104.sh](docs/update104.sh) script.

- v1.0.2 to v1.0.3 - backup registrar, download and run the [update103.sh](docs/update103.sh) script.

- v1.0.0/v1.0.1 to v1.0.2 - backup registrar, download and run the [update102.sh](docs/update102.sh) script.

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/registrar/issues) section of our GitHub repository.

- **GitHub Discussions**: For general discussions, ideas, or to connect with our community, visit the [Discussion](https://github.com/getnamingo/registrar/discussions) page on our GitHub project.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Acknowledgements

Special thanks to the [**FOSSBilling**](https://fossbilling.org/), [**WHMCS**](https://www.whmcs.com/), and [**Loom**](https://github.com/getargora/loom) teams for their work on powerful billing platforms that Namingo builds upon.

Additionally, we extend our gratitude to:
- **ChatGPT** for invaluable assistance with code and text writing.

## Support This Project

If you find Namingo Registry useful, consider donating:

- [Donate via Stripe](https://donate.stripe.com/7sI2aI4jV3Offn28ww)
- BTC: `bc1q9jhxjlnzv0x4wzxfp8xzc6w289ewggtds54uqa`
- ETH: `0x330c1b148368EE4B8756B176f1766d52132f0Ea8`

## Licensing

Namingo Registrar is licensed under the MIT License.