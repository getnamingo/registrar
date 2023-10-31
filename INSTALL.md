# Installation instructions: (TODO)

add-apt-repository ppa:ondrej/php
add-apt-repository ppa:ondrej/nginx-mainline
apt update && apt upgrade

apt install nginx mariadb-server mariadb-client curl phpmyadmin net-tools whois unzip git wget unzip libxml2 libxml2-utils pbzip2 php8.2 php8.2-fpm php8.2-mysql php8.2-cli php8.2-common php8.2-readline php8.2-mbstring php8.2-xml php8.2-gd php8.2-curl php8.2-gmp php8.2-intl php8.2-swoole certbot python3-certbot-nginx composer haveged pv fail2ban htop nload mlocate -y

Use https://config.fossbilling.org/ and place in /etc/nginx/sites-available/fossbilling.conf

Change phpx.x to php8.2

ln -s /etc/nginx/sites-available/fossbilling.conf /etc/nginx/sites-enabled/
remove default

systemctl restart nginx

certbot --nginx -d your.domain

mysql -u root -p
CREATE DATABASE fossbilling;
CREATE USER 'fossbillinguser'@'localhost' IDENTIFIED BY 'RANDOM_STRONG_PASSWORD';
GRANT ALL PRIVILEGES ON fossbilling.* TO 'fossbillinguser'@'localhost';
FLUSH PRIVILEGES;

wget https://fossbilling.org/downloads/stable and extract at /var/www/icann

make writeable
/var/www/icann/config.php	Yes	
/var/www/icann/data/cache	Yes	
/var/www/icann/data/log	Yes	
/var/www/icann/data/uploads

install
if installer stops with no feedback, just go to https://icann.tanglin.io/admin and try to login.

git clone https://github.com/getpinga/tide
mv tide /var/www/icann/themes/

Install https://github.com/getpinga/fossbilling-epp-rfc for each registry you support

Make all contact details/profile mandatory

## WHOIS