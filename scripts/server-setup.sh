#!/bin/bash

# Colors for output
GREEN='\033[0;32m'
NC='\033[0m'

echo -e "${GREEN}Starting TSMS server setup...${NC}"

# Update system packages
echo -e "${GREEN}Updating system packages...${NC}"
sudo apt update && sudo apt upgrade -y

# Install Apache and PHP repository
echo -e "${GREEN}Installing Apache and PHP...${NC}"
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install required packages
echo -e "${GREEN}Installing required packages...${NC}"
sudo apt install -y \
    apache2 \
    mysql-server \
    php8.2 \
    php8.2-cli \
    php8.2-common \
    php8.2-mysql \
    php8.2-xml \
    php8.2-curl \
    php8.2-gd \
    php8.2-mbstring \
    php8.2-zip \
    php8.2-bcmath \
    php8.2-redis \
    libapache2-mod-php8.2 \
    unzip \
    git \
    supervisor \
    redis-server

# Enable Apache modules
echo -e "${GREEN}Enabling Apache modules...${NC}"
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod ssl
sudo systemctl restart apache2

# Set permissions
echo -e "${GREEN}Setting up permissions...${NC}"
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html

# Install Composer
echo -e "${GREEN}Installing Composer...${NC}"
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer

# Configure MySQL
echo -e "${GREEN}Configuring MySQL...${NC}"
sudo mysql_secure_installation

# Install certbot for SSL
echo -e "${GREEN}Installing Certbot...${NC}"
sudo apt install -y certbot python3-certbot-apache

# Setup UFW firewall
echo -e "${GREEN}Configuring firewall...${NC}"
sudo ufw allow 'Apache Full'
sudo ufw allow ssh
sudo ufw allow 3389/tcp # For RDP
sudo ufw enable

echo -e "${GREEN}Installation complete!${NC}"
echo -e "Next steps:"
echo "1. Configure Apache virtual host"
echo "2. Set up SSL certificates"
echo "3. Configure MySQL database"
echo "4. Deploy application code"

# Change file permissions to make the script executable
chmod +x server-setup.sh
