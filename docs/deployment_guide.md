# Deployment Guide: Laravel-RAGFLOW-router on Azure VM

This guide details the steps to deploy the application on a fresh Azure VM running **Ubuntu 22.04 LTS**.

## 1. Infrastructure Setup

1.  **Create Azure VM**:
    - Image: Ubuntu Server 22.04 LTS.
    - Size: Standard_B2s (2 vCPUs, 4GB RAM) minimum recommended. Python services can be memory intensive.
    - Networking: Allow ports 22 (SSH), 80 (HTTP), 443 (HTTPS).

2.  **Access VM**:
    ```bash
    ssh azureuser@<your-vm-ip>
    ```

## 2. System Dependencies

Run the following commands to install PHP 8.2, Python, Redis, and Nginx.

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install common tools
sudo apt install -y software-properties-common git curl zip unzip supervisor redis-server nginx

# Add PHP Repository
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# Install PHP and extensions (adjust based on composer.json requirements)
sudo apt install -y php8.2 php8.2-fpm php8.2-cli php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip php8.2-sqlite3 php8.2-mysql php8.2-redis php8.2-bcmath

# Install Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Install Python 3.10+ and pip
sudo apt install -y python3 python3-pip python3-venv

# Install Node.js (for frontend build)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

### 3. Configure Persistent Git Access (Optional)
To avoid entering your username/password on every pull, use one of these methods:

**Option A: SSH Keys (Recommended)**
1.  Run `ssh-keygen -t ed25519 -C "your_email@example.com"` on the VM.
2.  Run `cat ~/.ssh/id_ed25519.pub` and copy the output.
3.  Go to **GitHub Settings > SSH and GPG Keys > New SSH Key** and paste it.
4.  Switch to SSH: `git remote set-url origin git@github.com:vanalexgr/Laravel-RAGFLOW-router.git`

**Option B: Credential Store (Easier)**
1.  Run `git config --global credential.helper store`
2.  Run `git pull`. You will be asked for your password once, and it will be saved forever.

---

## 4. Deployment Steps

1.  **Clone Repository**:
    ```bash
    cd /var/www
    sudo git clone https://github.com/vanalexgr/Laravel-RAGFLOW-router laravel-ragflow
    sudo chown -R $USER:www-data laravel-ragflow
    cd laravel-ragflow
    ```

2.  **Laravel Setup**:
    ```bash
    # Install PHP dependencies
    composer install --optimize-autoloader --no-dev

    # Environment Setup
    cp .env.example .env
    # EDIT .env NOW: Set correct DB creds, AZURE_OPENAI vars, RAGFLOW vars
    nano .env

    # Generate Key
    php artisan key:generate

    # Fix Permissions
    sudo chown -R www-data:www-data storage bootstrap/cache
    sudo chmod -R 775 storage bootstrap/cache

    # Frontend Build (if required)
    npm install
    npm run build
    ```

3.  **Python Service Setup**:
    ```bash
    cd ragflow_service
    python3 -m venv venv
    source venv/bin/activate
    pip install -r requirements.txt
    # Test run
    # uvicorn app:app --host 0.0.0.0 --port 8000
    deactivate
    cd ..
    ```

## 5. Configuration

### Nginx Configuration
Create `/etc/nginx/sites-available/laravel-ragflow`:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /var/www/laravel-ragflow/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable it:
```bash
sudo ln -s /etc/nginx/sites-available/laravel-ragflow /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl restart nginx
```

### Supervisor (Python Service)
Create `/etc/supervisor/conf.d/ragflow-service.conf`:

```ini
[program:ragflow-service]
process_name=%(program_name)s
directory=/var/www/laravel-ragflow/ragflow_service
command=/var/www/laravel-ragflow/ragflow_service/venv/bin/uvicorn app:app --host 127.0.0.1 --port 8000
autostart=true
autorestart=true
user=azureuser
redirect_stderr=true
stdout_logfile=/var/www/laravel-ragflow/storage/logs/ragflow-service.log
environment=
    AZURE_OPENAI_API_KEY="your-key",
    AZURE_OPENAI_ENDPOINT="your-endpoint",
    RAGFLOW_API_KEY="your-retrieval-key"
```

Start Supervisor:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start ragflow-service
```

## 6. Maintenance & Troubleshooting

### Restarting Services (If Server is Down)

If you are using **Laravel Sail (Docker)**:
```bash
cd ~/Laravel-RAGFLOW-router

# Check status
./vendor/bin/sail ps

# Restart all services
./vendor/bin/sail restart

# View logs
./vendor/bin/sail logs -f
```

If you are using **Manual Deployment (Nginx/Supervisor)**:
```bash
# Restart Web Server
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm

# Restart Python Bridge
sudo supervisorctl restart ragflow-service
```

### 500 Server Errors
Check the Laravel logs to diagnose the issue:
```bash
tail -n 50 storage/logs/laravel.log
```

---

## 7. Security: Internal Networking

For improved security and speed between your **Laravel VM** and **OpenWebUI VM**:

1.  **VNet Peering**: Ensure both VMs are in the same Azure Virtual Network (VNet).
2.  **Private IPs**: Use the Private IP address (e.g., `10.0.0.x`) instead of the Public IP.
    *   **Laravel**: Configure web server (Caddy/Nginx) to listen on `0.0.0.0` or the private IP.
    *   **OpenWebUI**: Point the pipeline URL to `http://<LARAVEL_PRIVATE_IP>/api/v1/retrieve`.
3.  **Firewall (NSG)**: 
    *   Create an Inbound Rule to **Allow** traffic on port 80/8080 from the OpenWebUI VM's Private IP.
    *   **Deny** traffic on port 80/8080 from the Internet (`*`).

## 8. Security (SSL)

```bash
sudo apt install certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```
