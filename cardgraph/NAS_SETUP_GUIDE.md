# Card Graph — Synology NAS Setup Guide

Follow these steps to deploy Card Graph on your Synology NAS.

---

## Step 1: Install Packages from Package Center

Open **Package Center** on your Synology DSM and install these (in order):

1. **MariaDB 10** — the database engine
2. **Web Station** — the web server (includes Apache/Nginx + PHP)
3. **PHP 8.2** — if not auto-installed with Web Station, install separately
4. **phpMyAdmin** — (optional) for visual database management

### Configure MariaDB 10
1. Open MariaDB 10 in Package Center
2. Set the **root password** — write it down securely
3. Ensure it's listening on `localhost` only (default)

---

## Step 2: Copy Application Files to NAS

Copy the entire `cardgraph/` folder to your NAS at:

```
/WaltersStation/web/cardgraph/
```

You can use:
- **File Station** — upload the folder to the `web` shared folder
- **SCP/SFTP** — `scp -r cardgraph/ user@NAS_IP:/WaltersStation/web/`
- **Synology Drive** — sync the folder

The final structure should be:
```
/WaltersStation/web/cardgraph/
├── config/
├── public/        <-- this is the web root
├── src/
├── sql/
├── storage/
└── setup.php
```

---

## Step 3: Create the Database and User

### Option A: Via phpMyAdmin
1. Open phpMyAdmin from your browser (usually at `http://NAS_IP:8880/phpMyAdmin`)
2. Log in as `root` with your MariaDB root password
3. Go to the **SQL** tab and run:

```sql
CREATE DATABASE card_graph CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'cg_app'@'localhost' IDENTIFIED BY 'YOUR_STRONG_PASSWORD_HERE';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP
    ON card_graph.* TO 'cg_app'@'localhost';
FLUSH PRIVILEGES;
```

### Option B: Via SSH
1. SSH into your NAS: `ssh admin@NAS_IP`
2. Run: `mysql -u root -p`
3. Paste the SQL above

**Write down the `cg_app` password** — you'll need it in the next step.

---

## Step 4: Update Configuration

Edit `/WaltersStation/web/cardgraph/config/secrets.php`:

Change this line:
```php
'password' => 'CHANGE_ME_ON_NAS',
```
To:
```php
'password' => 'YOUR_STRONG_PASSWORD_HERE',  // the cg_app password from Step 3
```

---

## Step 5: Configure Web Station

1. Open **Web Station** from DSM
2. Go to **Web Service Portal** (or **Virtual Host** in older DSM versions)
3. Click **Create**
4. Set:
   - **Portal type**: Name-based or Port-based
   - **Port**: `8880` (or another unused port)
   - **Document root**: `/WaltersStation/web/cardgraph/public`
   - **HTTP backend**: Apache
   - **PHP**: PHP 8.2

### PHP Settings
1. In Web Station, go to **Script Language Settings** → **PHP 8.2**
2. Click **Edit** and ensure these extensions are enabled:
   - `pdo_mysql`
   - `mbstring`
   - `json`
   - `session`
   - `fileinfo`
3. Under **Core** settings:
   - `upload_max_filesize` = `10M`
   - `post_max_size` = `12M`
   - `date.timezone` = `America/Chicago`
4. Click **Save**

---

## Step 6: Run the Setup Script

### Option A: Via SSH
```bash
ssh admin@NAS_IP
cd /WaltersStation/web/cardgraph
php setup.php
```

### Option B: Find PHP path
On Synology, PHP may be at a specific path:
```bash
# Find PHP binary
ls /usr/local/bin/php*

# Run with explicit path
/usr/local/bin/php82 /WaltersStation/web/cardgraph/setup.php
```

The setup script will:
- Create all 11 database tables
- Seed the status types
- Create the admin user (you'll be prompted for a password)

---

## Step 7: Set File Permissions

```bash
# Set ownership (http user varies by DSM version)
chown -R http:http /WaltersStation/web/cardgraph/storage/

# Set permissions
chmod -R 750 /WaltersStation/web/cardgraph/storage/
chmod 640 /WaltersStation/web/cardgraph/config/secrets.php
```

---

## Step 8: Test It

1. Open your browser and go to: `http://NAS_IP:8880/`
2. You should see the **login page**
3. Log in with:
   - Username: `admin`
   - Password: (whatever you set during setup, or `changeme`)
4. **Change your password** after first login via Maintenance > User Management

### Health Check
Visit `http://NAS_IP:8880/api/health` — you should see:
```json
{"status":"ok","database":"connected","timestamp":"...","timezone":"America/Chicago"}
```

---

## Step 9: Upload Your First CSV

1. Go to the **Dashboard** tab
2. Click **Upload Earnings CSV**
3. Select `december_15_december_21_2025_earnings.csv`
4. You should see: "Rows inserted: 1015, Rows skipped: 0"
5. Dashboard will populate with summary data

---

## Troubleshooting

### "Database connection failed"
- Check that MariaDB is running in Package Center
- Verify the password in `config/secrets.php`
- Try connecting via phpMyAdmin to confirm credentials

### "404 Not Found" on all pages
- Verify Web Station document root points to `/WaltersStation/web/cardgraph/public`
- Check that `.htaccess` is present and Apache `mod_rewrite` is enabled
- In Web Station PHP settings, make sure Apache is the HTTP backend (not Nginx, unless you add Nginx rewrite rules)

### "403 Forbidden"
- Check file permissions: `chown -R http:http /WaltersStation/web/cardgraph/`
- The `storage/` directory needs write permission for the web server

### CSV upload fails
- Check PHP settings: `upload_max_filesize` >= 10M
- Check `storage/uploads/` is writable
- Check `storage/logs/error.log` for PHP errors

### "CSRF token validation failed"
- Clear your browser cookies and log in again
- This can happen if the browser has a stale session

---

## Future: Enabling Remote Access

When you're ready to access Card Graph remotely:

1. **HTTPS first** — Set up a Let's Encrypt certificate in DSM > Security > Certificate
2. **Reverse proxy** — In DSM > Application Portal > Reverse Proxy, create a rule:
   - Source: `https://cardgraph.yourdomain.com:443`
   - Destination: `http://localhost:8880`
3. **Firewall** — Only open port 443 (HTTPS), never 8880 directly
4. **Update cookie settings** — In `Auth.php`, set `'secure' => true` for the session cookie
5. **Review** — Consider adding IP whitelisting or VPN for additional security
