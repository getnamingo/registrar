# Namingo Registrar Upgrade Guide

## v1.0.0 to v1.2.0

> Upgrade scripts **must be run sequentially** without skipping versions.
>
> For example, to upgrade from **v1.1.6** to **v1.2.0**, first run the **v1.1.7** upgrade, then the **v1.2.0** upgrade.

- v1.1.7 to v1.2.0 - download and run the [update120.sh](update120.sh) script.

- v1.1.6 to v1.1.7 - download and run the [update117.sh](update117.sh) script.

- v1.1.5 to v1.1.6 - download and run the [update116.sh](update116.sh) script.

- v1.1.4 to v1.1.5 - download and run the [update115.sh](update115.sh) script.

- v1.1.3 to v1.1.4 - download and run the [update114.sh](update114.sh) script.

- v1.1.2 to v1.1.3 - download and run the [update113.sh](update113.sh) script.

- v1.1.1 to v1.1.2 - download and run the [update112.sh](update112.sh) script.

- v1.1.0 to v1.1.1 - download and run the [update111.sh](update111.sh) script.

- v1.0.5 to v1.1.0 - download and run the [update110.sh](update110.sh) script.

- v1.0.4 to v1.0.5 - download and run the [update105.sh](update105.sh) script.

- v1.0.3 to v1.0.4 - download and run the [update104.sh](update104.sh) script.

- v1.0.2 to v1.0.3 - download and run the [update103.sh](update103.sh) script.

- v1.0.0/v1.0.1 to v1.0.2 - backup registrar, download and run the [update102.sh](update102.sh) script.

## FOSSBilling Upgrade Path

> [!WARNING]
> This section applies to installations running older FOSSBilling releases (such as v0.7.x) together with Namingo Registrar.
>
> Before proceeding, ensure you have a complete backup of:
>
> - The FOSSBilling database
> - `/var/www`
> - `/opt/registrar`
>
> Do not continue unless you have verified that your backups can be restored.

> [!IMPORTANT]
> For the best compatibility and long-term support, consider upgrading your server to **PHP 8.5** before or shortly after completing the FOSSBilling upgrade process.
>
> Namingo Registrar v1.1.7 and future releases are tested primarily against PHP 8.5.

### Step 1: Upgrade FOSSBilling

First, upgrade FOSSBilling to v0.8.x by following the official FOSSBilling upgrade documentation:

https://docs.fossbilling.org/maintenance/updating/0-7-to-0-8/

Carefully review all requirements and post-upgrade actions described in the official documentation before continuing.

### Step 2: Upgrade the Tide Theme

Clone the latest Tide theme into a temporary directory:

```bash
cd /tmp
git clone https://github.com/getpinga/tide
```

> [!IMPORTANT]
> If you have customized the Tide theme, back up your changes before proceeding.
>
> To avoid overwriting your existing theme configuration, do **not** replace:
>
> `config/settings_data.json`

Copy the updated theme files manually, excluding `config/settings_data.json`.

After copying the files, ensure the correct permissions are applied:

```bash
chmod 755 /var/www/themes/tide/assets
chmod 755 /var/www/themes/tide/config/settings_data.json

chown www-data:www-data /var/www/themes/tide/assets
chown www-data:www-data /var/www/themes/tide/config/settings_data.json
```

### Step 3: Upgrade Namingo Modules

#### Validation Module

```bash
cd /tmp
git clone https://github.com/getnamingo/fossbilling-validation
mv fossbilling-validation/Validation /var/www/modules/
```

#### TMCH Module

```bash
cd /tmp
git clone https://github.com/getnamingo/fossbilling-tmch
mv fossbilling-tmch/Tmch /var/www/modules/
```

#### Whois Module

```bash
cd /tmp
git clone https://github.com/getnamingo/fossbilling-whois
mv fossbilling-whois/Whois /var/www/modules/
```

#### Contact Module

```bash
cd /tmp
git clone https://github.com/getnamingo/fossbilling-contact
mv fossbilling-contact/Contact /var/www/modules/
```

### Step 4: Upgrade Optional Modules

If you use the MOSAPI Monitor module:

```bash
cd /tmp
git clone https://github.com/getnamingo/fossbilling-mosapi-monitor
mv fossbilling-mosapi-monitor/Mosapimonitor /var/www/modules/
```

### Step 5: Upgrade DNS Module

```bash
cd /tmp

wget https://github.com/getnamingo/fossbilling-dns/releases/download/v1.2.1/fossbilling-dns-v1.2.1.tar.gz

tar xzf fossbilling-dns-v1.2.1.tar.gz

cd fossbilling-dns-v1.2.1

mv Servicedns /var/www/modules/
```

### Step 6: Verify Installation

After all upgrades are complete:

1. Log in to the FOSSBilling administration panel.
2. Verify that all Namingo modules load correctly.
3. Check the FOSSBilling activity log for errors.
4. Confirm domain registration, renewal, transfer, DNS, and WHOIS functionality.
5. Review your web server and PHP logs for any warnings.

Once these checks have passed, you may continue with the standard Namingo Registrar upgrade process.

## Upgrade to Namingo Registrar v1.2.0

This section describes the required application upgrades before and after deploying **Namingo Registrar v1.2.0**.

### WHMCS

#### Step 1: Upgrade WHMCS

Ensure your installation is upgraded to **WHMCS v9.0.5** before continuing.

Follow the official WHMCS upgrade documentation if required.

#### Step 2: Upgrade to Namingo Registrar v1.2.0

Download and run the **v1.2.0** upgrade script:

```bash
./update120.sh
```

#### Step 3: Upgrade the Namingo Registrar Module

Upgrade to the latest version of the Namingo Registrar module by following the instructions in:

https://github.com/getnamingo/whmcs-namingo-registrar

#### Step 4: Install and Configure the Contact Validation Module

Install and configure the Contact Validation module by following the instructions in https://github.com/getnamingo/whmcs-contact-validation

Verify that registrant contact validation is functioning correctly before proceeding.

#### Step 5: Upgrade the Namingo EPP Module(s)

Upgrade the Namingo EPP module(s) to the latest available version by following the instructions at:

https://namingo.org/whmcs-module

---

### FOSSBilling

#### Step 1: Upgrade FOSSBilling

Ensure your installation is upgraded to **FOSSBilling v0.8.5** before continuing.

If you are upgrading from an older FOSSBilling release, follow the **FOSSBilling Upgrade Path** above before proceeding.

#### Step 2: Upgrade to Namingo Registrar v1.2.0

Download and run the **v1.2.0** upgrade script:

```bash
./update120.sh
```

#### Step 3: Upgrade the Namingo Registrar Module

Upgrade to the latest version of the Namingo Registrar module by following the instructions in:

https://github.com/getnamingo/fossbilling-registrar

#### Step 4: Install and Configure the Contact Validation Module

Install and configure the Contact Validation module by following the instructions in https://github.com/getnamingo/fossbilling-contact-validation

Verify that registrant contact validation is functioning correctly before proceeding.

#### Step 5: Upgrade the Namingo EPP Module(s)

Upgrade the Namingo EPP module(s) to the latest available version by following the instructions at:

https://namingo.org/foss-module/

#### Step 6: Upgrade Namingo Modules

After upgrading the registrar module, ensure the following modules are also upgraded to their latest versions by following the instructions in each repository:

- Validation Module  
  https://github.com/getnamingo/fossbilling-validation

- TMCH Module  
  https://github.com/getnamingo/fossbilling-tmch

- Whois Module  
  https://github.com/getnamingo/fossbilling-whois

- Contact Module  
  https://github.com/getnamingo/fossbilling-contact

---

### Loom

#### Step 1: Upgrade Loom

Ensure your installation is upgraded to the **latest available version of Loom** before continuing.

#### Step 2: Upgrade to Namingo Registrar v1.2.0

Download and run the **v1.2.0** upgrade script:

```bash
./update120.sh
```