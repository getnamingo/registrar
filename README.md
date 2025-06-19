# Namingo Registrar Platform

Open source ICANN-accredited domain registrar management system.

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

## Introduction

**Namingo Registrar** is an open-source domain registrar management system built for ICANN-accredited registrars. It helps you manage domains efficiently, stay compliant with ICANN requirements, and reduce operational costs.

**Namingo Registrar** works with either [FOSSBilling](https://fossbilling.org/) or [WHMCS](https://www.whmcs.com/), giving you the flexibility to choose the platform that fits your workflow.

## Get Involved

We're on a mission to make **Namingo** the best it can be, and we need your expertise! Whether you're adept in development, have a keen eye for design, or simply brim with innovative ideas, your contribution can make a world of difference.

## Features

- **Billing System**: Manages domain registrations, renewals, and payments as an ICANN accredited registrar, while also establishing EPP connections to registries for seamless domain operations.

- **WHOIS Server**: Provides instant domain registration information in line with ICANN's format.

- **RDAP Server**: Modern protocol offering structured domain registration data, accessible via HTTP in JSON format.

- **Escrow**: Safeguards domain registrants by depositing registration data with a trusted escrow agent, mandated by ICANN.

- **TMCH**: Allows trademark holders to register their marks and receive domain registration alerts.

- **WDRP**: Sends regular reminders to domain name registrants to update their WHOIS data, ensuring accuracy.

- **ERRP**: Ensures compliance with ICANN's policies on managing expired domain names.

- **Contact Validation**: Validates domain name registrant's contact details for accuracy and authenticity.

## Documentation

### Installation

**Minimum requirement:** a VPS running Ubuntu 22.04 or 24.04, with at least 1 CPU core, 2 GB RAM, and 10 GB hard drive space.

To get started, copy the command below and paste it into your server terminal:

```bash
wget https://namingo.org/registrar-install.sh -O install.sh && chmod +x install.sh && ./install.sh
```

For detailed installation steps, see:

- [install-fossbilling.md](install-fossbilling.md) – for FOSSBilling setup  
- [install-whmcs.md](install-whmcs.md) – for WHMCS setup

### Update

- v1.0.3 to v1.0.4 - backup registrar, download and run the [update104.sh](update104.sh) script.

- v1.0.2 to v1.0.3 - backup registrar, download and run the [update103.sh](update103.sh) script.

- v1.0.0/v1.0.1 to v1.0.2 - backup registrar, download and run the [update.sh](update.sh) script.

## Support

Your feedback and inquiries are invaluable to Namingo's evolutionary journey. If you need support, have questions, or want to contribute your thoughts:

- **Email**: Feel free to reach out directly at [help@namingo.org](mailto:help@namingo.org).

- **Discord**: Or chat with us on our [Discord](https://discord.gg/97R9VCrWgc) channel.
  
- **GitHub Issues**: For bug reports or feature requests, please use the [Issues](https://github.com/getnamingo/registrar/issues) section of our GitHub repository.

- **GitHub Discussions**: For general discussions, ideas, or to connect with our community, visit the [Discussion](https://github.com/getnamingo/registrar/discussions) page on our GitHub project.

We appreciate your involvement and patience as Namingo continues to grow and adapt.

## Acknowledgements

Special thanks to the **FOSSBilling** and **WHMCS** teams for their work on powerful billing platforms that Namingo builds upon.  
Their efforts have made it possible to offer flexible registrar solutions for both [FOSSBilling](https://fossbilling.org/) and [WHMCS](https://www.whmcs.com/).

Additionally, we extend our gratitude to:
- **ChatGPT** for invaluable assistance with code and text writing.

## Licensing

Namingo is licensed under the MIT License.