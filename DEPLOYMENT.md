# Deploying poapis to Ubuntu + Apache 2

Target: Ubuntu 22.04 / 24.04, Apache 2, PHP 8.3. The app connects to a remote Microsoft SQL Server 2008 / 2008 R2.

## 1. PHP 8.3 and Apache

Ubuntu 24.04 ships PHP 8.3. On 22.04, add `ppa:ondrej/php` first.

```bash
sudo apt update
sudo apt install -y apache2 libapache2-mod-php8.3 \
    php8.3 php8.3-cli php8.3-dev php8.3-mbstring php8.3-xml php8.3-curl \
    php8.3-zip php8.3-bcmath php8.3-intl php-pear unzip git
sudo a2enmod rewrite
```

Install Composer from https://getcomposer.org/download/ if it isn't already installed.

## 2. Microsoft ODBC Driver 17 + PHP sqlsrv drivers

ODBC Driver **17** is the right choice for SQL Server 2008. Driver 18 defaults to `Encrypt=yes`, and Microsoft doesn't support it against 2008.

```bash
curl -fsSL https://packages.microsoft.com/keys/microsoft.asc \
    | sudo gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg
curl -fsSL https://packages.microsoft.com/config/ubuntu/$(lsb_release -rs)/prod.list \
    | sudo tee /etc/apt/sources.list.d/mssql-release.list
sudo apt update
sudo ACCEPT_EULA=Y apt install -y msodbcsql17 mssql-tools unixodbc-dev

sudo pecl install sqlsrv pdo_sqlsrv
printf "; priority=20\nextension=sqlsrv.so\n"     | sudo tee /etc/php/8.3/mods-available/sqlsrv.ini
printf "; priority=30\nextension=pdo_sqlsrv.so\n" | sudo tee /etc/php/8.3/mods-available/pdo_sqlsrv.ini
sudo phpenmod -v 8.3 sqlsrv pdo_sqlsrv
php -m | grep -i sqlsrv      # expect: pdo_sqlsrv, sqlsrv
```

## 3. TLS workaround for SQL Server 2008

An unpatched SQL Server 2008 only speaks TLS 1.0. Ubuntu's OpenSSL 3 refuses TLS 1.0 by default, so the login fails with errors like `SSL Provider: unsupported protocol` or `TCP Provider: Error code 0x2746`. The SQL Server login handshake uses TLS even when `DB_ENCRYPT=no`.

**First, test without any workaround.** If the server has the TLS 1.2 update (KB3135244), you can skip this section:

```bash
/opt/mssql-tools/bin/sqlcmd -S SQLHOST,1433 -U USER -P 'PASSWORD' -Q "SELECT @@VERSION"
```

If that fails, create an OpenSSL config that allows TLS 1.0. Apply it **only to Apache and the commands you run for this app**, not system-wide:

```bash
sudo tee /etc/ssl/openssl-sql2008.cnf > /dev/null <<'EOF'
openssl_conf = default_conf

[default_conf]
ssl_conf = ssl_sect

[ssl_sect]
system_default = system_default_sect

[system_default_sect]
MinProtocol = TLSv1
CipherString = DEFAULT@SECLEVEL=0
EOF

# Test again with it:
OPENSSL_CONF=/etc/ssl/openssl-sql2008.cnf /opt/mssql-tools/bin/sqlcmd -S SQLHOST,1433 -U USER -P 'PASSWORD' -Q "SELECT @@VERSION"

# Apply it to Apache:
echo 'export OPENSSL_CONF=/etc/ssl/openssl-sql2008.cnf' | sudo tee -a /etc/apache2/envvars
```

Also prefix `php artisan …` commands that touch the database with `OPENSSL_CONF=/etc/ssl/openssl-sql2008.cnf`.

### Fallback: FreeTDS (pdo_dblib)

Use this only if the ODBC driver still won't connect. Laravel automatically uses `dblib` when `pdo_sqlsrv` isn't loaded.

```bash
sudo phpdismod -v 8.3 sqlsrv pdo_sqlsrv
sudo apt install -y php8.3-sybase freetds-common
```

In `/etc/freetds/freetds.conf`, under `[global]`, set:

```
tds version = 7.3
client charset = UTF-8
```

## 4. Deploy the app

```bash
sudo mkdir -p /var/www/poapis && sudo chown $USER:www-data /var/www/poapis
# copy the project (git clone, rsync, or scp) into /var/www/poapis. Do not copy vendor/ or .env
cd /var/www/poapis
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=http://your-server
LOG_LEVEL=warning

DB_CONNECTION=sqlsrv
DB_HOST=SQLHOST
DB_PORT=1433
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
DB_ENCRYPT=no
DB_TRUST_SERVER_CERTIFICATE=true
```

Set the real table and column names in `config/po.php` (or `PO_HEADER_TABLE` / `PO_LINES_TABLE` in `.env`). Then:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
php artisan config:cache
php artisan route:cache
```

**Re-run `php artisan config:cache` after every `.env` or `config/` change.**

## 5. Apache virtual host

`/etc/apache2/sites-available/poapis.conf`:

```apache
<VirtualHost *:80>
    ServerName poapis.example.com
    DocumentRoot /var/www/poapis/public

    <Directory /var/www/poapis/public>
        AllowOverride All
        Require all granted
        # The API has no authentication. Consider limiting it to your network:
        # Require ip 10.0.0.0/8 192.168.0.0/16
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/poapis-error.log
    CustomLog ${APACHE_LOG_DIR}/poapis-access.log combined
</VirtualHost>
```

```bash
sudo a2ensite poapis
sudo a2dissite 000-default
sudo systemctl restart apache2   # restart (not reload) so envvars changes apply
```

## 6. Verify

```bash
curl http://your-server/up                       # Laravel health page, HTTP 200
curl http://your-server/api/health/db            # {"status":"ok","version":"Microsoft SQL Server 2008 ..."}
curl http://your-server/api/getData
```

When something fails, check `storage/logs/laravel.log` and `/var/log/apache2/poapis-error.log`.

## 7. Troubleshooting: 504 Gateway Time-out / requests hang

If `/up` works but `/api/health/db` hangs, PHP cannot open the SQL Server connection. Run the check on the Ubuntu server, from the project folder:

```bash
php artisan config:clear      # make sure the current .env is used
php artisan db:diagnose
```

It checks four things in order and stops at the first one that fails:

| Step that fails | Meaning | Fix |
|---|---|---|
| 2. PHP drivers | `pdo_sqlsrv` isn't loaded for the CLI | Section 2 (`phpenmod`, then check `php -m`) |
| 3. TCP connect | Packets to SQL Server are dropped or refused. **This is the usual cause of a 504.** | Ask the DBA or network team to allow **this server's IP** on TCP 1433, in the Windows Firewall on the SQL host and any network ACL. The old API server's IP is probably already allowed. Check with `nc -vz -w5 10.1.0.57 1433` |
| 4. Login, with "SSL Provider" / "0x2746" / "Login timeout" | TLS handshake problem with SQL 2008 | Section 3: rerun with `OPENSSL_CONF=/etc/ssl/openssl-sql2008.cnf php artisan db:diagnose` |
| 4. Login, with "Login failed for user" | Wrong credentials, or the login is disabled | Fix `DB_USERNAME` / `DB_PASSWORD`, then run `php artisan config:cache` |

The CLI doesn't inherit Apache's `OPENSSL_CONF`, so pass it on the command line as shown above when testing the TLS workaround.

After this fix, connection attempts give up after `DB_LOGIN_TIMEOUT` seconds (default 10) and queries after `DB_QUERY_TIMEOUT` seconds (default 30). The API then returns a JSON 500 error instead of a 504. If you still get a 504 once the database works, the web server's proxy timeout is shorter than the query time. Raise it: Apache's `ProxyTimeout` for PHP-FPM, or the load balancer's idle timeout.
