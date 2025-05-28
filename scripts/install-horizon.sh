#!/bin/bash

# Install Horizon via Composer
composer require laravel/horizon

# Publish config
php artisan horizon:install

# Copy systemd service file
sudo cp scripts/horizon.service /etc/systemd/system/horizon.service

# Reload systemd
sudo systemctl daemon-reload

# Enable and start Horizon
sudo systemctl enable horizon
sudo systemctl start horizon

# Verify status
sudo systemctl status horizon
