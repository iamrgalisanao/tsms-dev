#!/bin/bash

# Download Composer installer
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"

# Verify installer
HASH="$(wget -q -O - https://composer.github.io/installer.sig)"
php -r "if (hash_file('SHA384', 'composer-setup.php') === '$HASH') { echo 'Installer verified'; } else { echo 'Installer corrupt'; unlink('composer-setup.php'); } echo PHP_EOL;"

# Install Composer globally
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer

# Clean up
php -r "unlink('composer-setup.php');"

# Verify installation
composer --version
