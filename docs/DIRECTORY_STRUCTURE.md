# TSMS Ubuntu Staging Server Directory Structure

```
/var/www/tsms/
├── app/                        # Application core code
│   ├── Console/               # Artisan commands
│   ├── Http/                  # Controllers, Middleware, Requests
│   ├── Models/                # Eloquent models
│   ├── Services/              # Business logic services
│   └── Jobs/                  # Queue jobs
├── config/                    # Configuration files
├── database/                  # Database files
├── docs/                      # Documentation
├── public/                    # Web root directory
├── resources/                 # Frontend resources
├── routes/                    # Route definitions
├── scripts/                   # Server automation
│   ├── deploy/               # Deployment scripts
│   ├── backup/               # Backup scripts
│   └── cron/                 # Cron job scripts
├── storage/                   # Application storage
│   ├── app/                  # File uploads
│   │   └── public/          # Public storage
│   ├── framework/           # Framework storage
│   │   ├── cache/          # Cache files
│   │   ├── sessions/       # Session files
│   │   └── views/          # Compiled views
│   └── logs/               # Application logs
└── vendor/                   # Dependencies
```

## Server Configuration

1. **Apache Virtual Host**

```apache
# /etc/apache2/sites-available/tsms.conf
<VirtualHost *:80>
    ServerName staging.tsms.com
    DocumentRoot /var/www/tsms/public
    <Directory /var/www/tsms/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/tsms-error.log
    CustomLog ${APACHE_LOG_DIR}/tsms-access.log combined
</VirtualHost>
```

2. **Directory Permissions**

```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/tsms

# Set directory permissions
sudo find /var/www/tsms -type f -exec chmod 644 {} \;
sudo find /var/www/tsms -type d -exec chmod 755 {} \;

# Set storage and cache permissions
sudo chmod -R 775 /var/www/tsms/storage
sudo chmod -R 775 /var/www/tsms/bootstrap/cache
```

3. **Environment Files**

```
/var/www/tsms/
├── .env                # Production environment
├── .env.staging       # Staging environment
└── .env.example       # Template configuration
```

4. **Log Files**

```
/var/log/
├── apache2/
│   ├── tsms-access.log
│   └── tsms-error.log
└── tsms/
    └── laravel.log
```

5. **Backup Directory**

```
/var/backups/tsms/
├── database/          # Database backups
├── storage/           # File backups
└── config/           # Configuration backups
```

## Version Control

1. **Repository**

```bash
cd /var/www/tsms
git remote add origin https://github.com/your-org/tsms.git
git config branch.master.remote origin
git config branch.master.merge refs/heads/master
```

2. **Deployment Branch**

```bash
git checkout -b staging
git push -u origin staging
```

## Maintenance Scripts

Located in `/var/www/tsms/scripts/`:

-   `deploy.sh` - Deployment automation
-   `backup.sh` - Backup procedures
-   `maintenance.sh` - Maintenance tasks
-   `monitor.sh` - Server monitoring

## Cron Jobs

Add to `/etc/crontab`:

```bash
* * * * * www-data cd /var/www/tsms && php artisan schedule:run
0 0 * * * www-data /var/www/tsms/scripts/backup.sh
```

## Notes

-   All paths are relative to `/var/www/tsms/`
-   Use staging subdomain for testing
-   Keep logs rotated using logrotate
-   Maintain regular backups
-   Monitor disk space usage
