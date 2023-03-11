# Indera - an open-source ICANN registrar management system

[![StandWithUkraine](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/badges/StandWithUkraine.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

[![SWUbanner](https://raw.githubusercontent.com/vshymanskyy/StandWithUkraine/main/banner2-direct.svg)](https://github.com/vshymanskyy/StandWithUkraine/blob/main/docs/README.md)

**Indera** aims to develop addons for WHMCS or FOSSBilling, turning these platform into a complete and open-source ICANN accredited registrar management system. The addons will enhance the capabilities of these billing and management systems, allowing them to handle the registration, renewal, and management of domain names as an ICANN accredited registrar. By using these addons, businesses will have access to a powerful and flexible solution for managing their domains and customers, while adhering to the strict standards set by ICANN. The project will enable businesses to streamline their domain management operations, improve efficiency and save costs.

## Billing System

To utilize our solution, you will require a billing system, currently, we offer compatibility with either FOSSBilling or WHMCS. Our EPP modules integrate directly with the registries to provide a seamless experience for domain management.

### FOSSBilling

[EPP Registrar Module for FOSSBilling (Generic RFC EPP)](https://github.com/getpinga/fossbilling-epp-rfc)

[EPP Registrar Module for FOSSBilling (FRED Registry)](https://github.com/getpinga/fossbilling-epp-fred)

[EPP Registrar Module for FOSSBilling (Hostmaster.ua)](https://github.com/getpinga/fossbilling-epp-ua)

### WHMCS

[EPP Registrar Module for WHMCS (Generic RFC EPP)](https://github.com/getpinga/whmcs-epp-rfc)

[EPP Registrar Module for WHMCS (Hostmaster.ua)](https://github.com/getpinga/whmcs-epp-ua)

## WHOIS Server

The WHOIS server is an essential tool for anyone looking to retrieve important information about a domain name, including its owner, registration and expiration dates, and contact information. The ICANN requires all registrars to provide access to this data via a WHOIS server.

To comply with these requirements, the WHOIS server connects to a billing system database to retrieve registration information for a given domain name. This information includes details like the domain name itself, the registrar responsible for its management, and the contact information associated with the domain.

The WHOIS server is designed to handle a large number of concurrent connections efficiently. It retrieves the necessary data from the billing system database and formats it according to the ICANN's WHOIS format before sending it back to the client.

Overall, the WHOIS server is an essential tool for anyone looking to retrieve domain registration information, and by utilizing efficient technology, it can quickly provide this data in compliance with ICANN requirements.

## RDAP Server

An RDAP (Registration Data Access Protocol) server is a tool that provides access to domain registration data in compliance with ICANN's RDAP requirements. Like WHOIS servers, RDAP servers connect to a billing system database to retrieve domain registration data, including details like the domain name, registrar, registration and expiration dates, and contact information.

However, unlike WHOIS servers, RDAP servers use a more modern protocol and data format that provides more structured and standardized data. RDAP servers also support more granular access control, enabling users to access only the data they are authorized to view.

To retrieve data from the RDAP server, clients send requests using HTTP, and the server responds with a JSON-formatted response. This format provides more structure and uniformity than WHOIS, making it easier for clients to parse the data and incorporate it into their own applications.

RDAP servers are also designed to handle a large number of concurrent connections, ensuring that users can access domain registration data quickly and efficiently. They can be integrated with billing systems to automate the retrieval of registration data and ensure that the data provided is always up to date.

Overall, RDAP servers provide a modern, efficient, and standardized approach to domain registration data access that is compliant with ICANN's requirements.

## Escrow

Registrar Data Escrow is a service that protects domain name registrants in the event of a registrar's failure or termination. The service requires registrars to regularly deposit their customer registration data into an escrow agent, who stores the data securely and ensures its availability in case the registrar is no longer able to provide the service.

The data escrow process typically involves the transfer of a copy of the registrar's database to the escrow agent on a regular basis, such as weekly or monthly. This copy contains all customer registration data, including domain names, contact details, and registration and expiry dates.

In the event of a registrar's failure or termination, the escrow agent releases the registration data to a designated successor registrar or ICANN itself. This ensures that domain name registrants can continue to manage their domains and avoid the loss of their domain names due to the registrar's failure.

Registrar Data Escrow is an essential service that helps to protect the interests of domain name registrants and ensures the stability of the domain name system. It is required by ICANN for all accredited registrars and must be performed by a trusted third-party escrow agent.

## TMCH

Support for Trademark Clearinghouse access: This service provides access to the Trademark Clearinghouse, which helps to protect trademarks in the domain name system. It allows trademark holders to register their marks and receive notifications when domain names containing their marks are registered.

## WDRP

Support for ICANN WHOIS Data Reminder policy: This service ensures that domain name registrants receive regular reminders to review and update their WHOIS data. It helps to ensure that accurate and up-to-date registration information is available to those who need it.

## ERRP

Support for ICANN Expired Domains policy: This service ensures compliance with ICANN's Expired Domains policy, which outlines the procedures for managing domain names that have expired. It helps to ensure that expired domain names are properly managed and made available for re-registration.

## Contact Validaiton

Support for contact validation: This service validates contact information provided by domain name registrants, ensuring that it is accurate and up to date. It helps to reduce the risk of fraud and ensures that communication regarding the domain name is delivered to the correct person or organization.

