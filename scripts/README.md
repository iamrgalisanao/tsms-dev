# TSMS Server Setup Script

## Usage via RDP

1. Connect to server via RDP
2. Open terminal
3. Download the script:

```bash
wget https://raw.githubusercontent.com/your-org/tsms/main/scripts/server-setup.sh
```

4. Make script executable:

```bash
chmod +x server-setup.sh
```

5. Run the script:

```bash
./server-setup.sh
```

## What it installs

-   Apache2
-   PHP 8.2 with required extensions
-   MySQL Server
-   Redis
-   Supervisor
-   Git
-   SSL tools
-   Security configurations

## Post-Installation

1. Configure application environment
2. Set up database
3. Deploy application code
4. Configure Apache virtual host
5. Set up SSL certificates

## Security Note

-   Default RDP port (3389) is enabled
-   Basic firewall rules are configured
-   Review security settings after installation

# Server Setup Scripts

## Running the Setup Script

1. Make the script executable:

```bash
chmod +x server-setup.sh
```

2. Run the script with sudo:

```bash
sudo bash server-setup.sh
```

3. Follow the prompts during installation:
    - MySQL root password setup
    - Apache configurations
    - Firewall settings

## What it Does

-   Updates system packages
-   Installs Apache, PHP 8.2, MySQL
-   Configures Apache modules
-   Sets up basic security
-   Installs required dependencies
-   Configures permissions

## After Running

1. Check installed versions:

```bash
php -v
apache2 -v
mysql --version
```

2. Verify services are running:

```bash
systemctl status apache2
systemctl status mysql
```
