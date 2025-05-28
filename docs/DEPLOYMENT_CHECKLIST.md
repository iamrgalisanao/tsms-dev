# TSMS Deployment Checklist for Ubuntu Server

## 1. Pre-Deployment Tasks

### Server Preparation (Ubuntu)

-   [ ] Update server packages: `sudo apt update && sudo apt upgrade -y`
-   [ ] Install required dependencies:

    ```bash
    # Add PHP 8.2 repository
    sudo add-apt-repository ppa:ondrej/php
    sudo apt update

    # Install Apache and PHP 8.2 packages
    sudo apt install -y apache2 mysql-server php8.2 php8.2-common php8.2-mysql \
    php8.2-xml php8.2-curl php8.2-gd php8.2-mbstring php8.2-zip php8.2-bcmath \
    php8.2-redis libapache2-mod-php8.2 unzip git supervisor redis-server
    ```

-   [ ] Verify PHP version matches development: `php -v` (should be 8.2.12)
-   [ ] Enable required Apache modules:
    ```bash
    sudo a2enmod rewrite
    sudo a2enmod headers
    sudo a2enmod ssl
    sudo systemctl restart apache2
    ```
-   [ ] Configure Apache virtual host for TSMS
-   [ ] Configure SSL with Let's Encrypt: `sudo certbot --apache`

### Environment Configuration

-   [ ] Copy `.env.example` to `.env` and update for production
-   [ ] Configure database credentials for MySQL on Ubuntu
-   [ ] Set up Redis connection details (typically localhost for Ubuntu)
-   [ ] Configure queue settings for production
-   [ ] Set `APP_ENV=production` and `APP_DEBUG=false`
-   [ ] Update log channel to 'daily' or 'stack' for production
-   [ ] Configure storage directory permissions: `sudo chown -R www-data:www-data storage bootstrap/cache`

### Database

-   [ ] Run database migrations: `php artisan migrate`
-   [ ] Verify migration history is clean
-   [ ] Check for any pending schema changes
-   [ ] Backup existing database
-   [ ] Validate foreign key constraints

### Queue Configuration

-   [ ] Install and configure Supervisor:
    ```bash
    sudo apt install -y supervisor
    ```
-   [ ] Create Supervisor configuration for queue workers:
    ```
    # /etc/supervisor/conf.d/tsms-worker.conf
    [program:tsms-worker]
    process_name=%(program_name)s_%(process_num)02d
    command=php /var/www/tsms/artisan queue:work redis --queue=transactions --tries=3 --timeout=120
    autostart=true
    autorestart=true
    user=www-data
    numprocs=2
    redirect_stderr=true
    stdout_logfile=/var/www/tsms/storage/logs/worker.log
    ```
-   [ ] Update and reload supervisor:
    ```bash
    sudo supervisorctl reread
    sudo supervisorctl update
    sudo supervisorctl start tsms-worker:*
    ```
-   [ ] Verify queue worker status: `sudo supervisorctl status`
-   [ ] Configure failed job handling in `.env`

### Security Checks

-   [ ] Run security audit
-   [ ] Check for any vulnerable dependencies
-   [ ] Verify API authentication settings
-   [ ] Test rate limiting configuration
-   [ ] Validate CORS settings
-   [ ] Check file permissions
-   [ ] Review user role assignments
-   [ ] Configure Apache security settings:
    ```bash
    sudo a2enmod security2
    sudo systemctl restart apache2
    ```
-   [ ] Configure UFW firewall:
    ```bash
    sudo ufw allow 'Apache Full'
    sudo ufw allow ssh
    sudo ufw enable
    ```
-   [ ] Set proper file permissions for web server access:
    ```bash
    sudo chown -R www-data:www-data /var/www/tsms
    sudo find /var/www/tsms -type f -exec chmod 644 {} \;
    sudo find /var/www/tsms -type d -exec chmod 755 {} \;
    ```

### Performance Optimization

-   [ ] Run `php artisan optimize`
-   [ ] Clear all caches:
    ```bash
    php artisan cache:clear
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear
    ```
-   [ ] Optimize class autoloader
-   [ ] Configure opcache settings
-   [ ] Set up Redis caching
-   [ ] Configure OPcache for PHP-FPM:
    ```bash
    sudo nano /etc/php/8.2/fpm/conf.d/10-opcache.ini
    ```
-   [ ] Set up Redis cache configuration in `.env`
-   [ ] Configure Apache caching for static assets
-   [ ] Set proper PHP-FPM pool settings for production workload

## 2. Deployment Process

### Code Deployment

-   [ ] Clone repository to server or deploy via CI/CD
    ```bash
    git clone https://github.com/your-org/tsms.git /var/www/tsms
    ```
-   [ ] Install Composer dependencies:
    ```bash
    cd /var/www/tsms
    composer install --no-dev --optimize-autoloader
    ```
-   [ ] Set proper permissions:
    ```bash
    sudo chown -R www-data:www-data /var/www/tsms/storage /var/www/tsms/bootstrap/cache
    ```

### Application Setup

-   [ ] Run database migrations: `php artisan migrate --force`
-   [ ] Clear and rebuild cache:
    ```bash
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    ```
-   [ ] Create symbolic links for storage: `php artisan storage:link`
-   [ ] Run any scheduled commands manually to verify they work
-   [ ] Set up cron job for scheduler:
    ```
    * * * * * cd /var/www/tsms && php artisan schedule:run >> /dev/null 2>&1
    ```

## 3. Module-Specific Checks

### Transaction Processing

-   [ ] Verify transaction API endpoints
-   [ ] Test text format parser
-   [ ] Validate queue processing
-   [ ] Check error handling
-   [ ] Test retry mechanisms
-   [ ] Verify circuit breaker settings

### Transaction Module

-   [ ] Verify transaction list display
-   [ ] Test action buttons functionality
-   [ ] Validate status badge display
-   [ ] Check filtering system
-   [ ] Test pagination
-   [ ] Verify real-time updates
-   [ ] Test export functionality

### Authentication & Security

-   [ ] Test login functionality
-   [ ] Verify token management
-   [ ] Check role-based access
-   [ ] Test rate limiting
-   [ ] Verify security logging

### Monitoring & Logging

-   [ ] Configure log channels
-   [ ] Set up log rotation
-   [ ] Test error reporting
-   [ ] Configure monitoring alerts
-   [ ] Verify audit logging

## 4. Post-Deployment Tasks

### Verification

-   [ ] Run health checks
-   [ ] Verify application version
-   [ ] Check system logs
-   [ ] Monitor queue processing
-   [ ] Test critical paths
-   [ ] Verify Apache configuration: `sudo apache2ctl -t`
-   [ ] Check PHP configuration: `php -v`
-   [ ] Verify Redis connection: `redis-cli ping`
-   [ ] Check supervisor processes: `sudo supervisorctl status`
-   [ ] Test application in browser with production URL

### Ubuntu-Specific Maintenance

-   [ ] Set up log rotation for Laravel logs:
    ```bash
    sudo nano /etc/logrotate.d/tsms
    ```
-   [ ] Configure automatic security updates:
    ```bash
    sudo apt install unattended-upgrades
    sudo dpkg-reconfigure unattended-upgrades
    ```
-   [ ] Set up system resource monitoring with tools like Netdata or Prometheus
-   [ ] Configure backup strategy for Ubuntu server and MySQL database

### Monitoring Setup

-   [ ] Configure uptime monitoring
-   [ ] Set up performance monitoring
-   [ ] Configure error alerting
-   [ ] Test notification systems
-   [ ] Install monitoring agent (Netdata or similar):
    ```bash
    bash <(curl -Ss https://my-netdata.io/kickstart.sh)
    ```
-   [ ] Configure Laravel Telescope in production (if applicable)
-   [ ] Set up MySQL monitoring

## 5. Best Practices for Ubuntu Server

### Performance

-   Keep queue workers optimized
-   Monitor memory usage
-   Use proper indexes
-   Implement caching strategies
-   Configure proper timeouts
-   Tune MySQL configuration for production
-   Configure Apache worker processes and connection limits
-   Set up PHP-FPM pool optimization
-   Implement Redis persistence strategy

### Security

-   Regular security audits
-   Keep dependencies updated
-   Monitor failed login attempts
-   Review access logs
-   Maintain backup strategy
-   Configure automatic security updates for Ubuntu
-   Set up intrusion detection (fail2ban)
-   Regular log analysis for suspicious activities
-   Regular system updates schedule

### Maintenance

-   Regular log rotation
-   Database optimization
-   Cache management
-   Queue monitoring
-   Error log review
-   Configure automated backups for database and files
-   Set up log rotation for system and application logs
-   Monitor disk space usage with alerts
-   Plan for regular maintenance windows

## 6. Rollback Plan

### Preparation

-   [ ] Create database backup
-   [ ] Document current configuration
-   [ ] Prepare rollback scripts
-   [ ] Test restoration process

### Emergency Procedures

-   Document emergency contacts
-   List critical services
-   Define incident response
-   Prepare status page updates

## 7. Monitoring Checklist

### Key Metrics

-   [ ] Transaction processing rate
-   [ ] Error rates
-   [ ] Queue sizes
-   [ ] Response times
-   [ ] CPU/Memory usage
-   [ ] Disk space
-   [ ] Cache hit rates

### Alerts Configuration

-   [ ] Set up error rate thresholds
-   [ ] Configure performance alerts
-   [ ] Set up availability monitoring
-   [ ] Configure security alerts

## Version Control

-   Last Updated: 2025-05-28
-   Version: 1.1.0
-   Author: Development Team

## Notes

-   Keep this checklist updated
-   Review regularly
-   Document any deviations
-   Track deployment history
-   For Ubuntu-specific issues, refer to the Ubuntu Server Guide
