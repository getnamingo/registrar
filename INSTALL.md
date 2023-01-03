# Installation instructions:

1. Clone the repo to your desired location, e.g. ```git clone https://github.com/getpinga/indera /opt/indera```

2. Create the necessary folders: ```mkdir -p /opt/indera/escrow /opt/indera/denic```

3. Navigate to the ```/opt/indera``` directory and run ```composer update```

4. Download the [escrow-rde client](https://team-escrow.gitlab.io/escrow-rde-client/releases/escrow-rde-client-v2.1.1-linux_x86_64.tar.gz) and extract it to ```/opt/indera/escrow-rde```

5. Run ```/opt/indera/escrow-rde -i``` to create the config file, then rename it to ```escrow.yaml```

6. Edit the ```config.php``` file to update the database details and billing system type. Edit the ```escrow.yaml``` file to update the escrow configuration details.

7. Start the Swoole scripts: ```php whois.php```, ```php rdap.php```, and ```php escrow.php```

It is recommended to use **Ubuntu 22.04** and **PHP 8.1** with the _php8.1-swoole_ extension being mandatory.
