# 🚀 Deployment Guide for inodesain.com/agro
## erpAgro - Agrobusiness ERP System

---

## 📋 Pre-Deployment Checklist

- [ ] Backup local database
- [ ] Export database to SQL file
- [ ] Verify all files are present
- [ ] Get hosting credentials (FTP/SSH, Database)
- [ ] Ensure hosting meets requirements (PHP 7.4+, MySQL 5.7+)

---

## 🔧 System Requirements

### Server Requirements:
- **PHP Version**: 7.4 or higher
- **Database**: MySQL 5.7+ or MariaDB 10.3+
- **Web Server**: Apache 2.4+ with mod_rewrite enabled
- **PHP Extensions**: PDO, PDO_MySQL, mbstring, json
- **Disk Space**: Minimum 100MB
- **Memory**: 256MB PHP memory limit recommended

---

## 📦 Step 1: Prepare Local Files

### 1.1 Export Database
```bash
# From XAMPP phpMyAdmin or command line:
# 1. Open http://localhost/phpmyadmin
# 2. Select 'plantation' database
# 3. Click 'Export' tab
# 4. Choose 'Quick' export method
# 5. Format: SQL
# 6. Click 'Go' and save the file as 'plantation_backup.sql'
```

### 1.2 Clean Up Files (Remove from deployment)
Delete or move these files before uploading:
```
- *.bak files (activities.php.bak, index.php.bak, etc.)
- *.md files (except this guide if needed)
- *.sql files in root directory
- database/*.sql files (keep only schema if needed)
- test/debug files
- COPY_NURSERY_FILES.txt
- RUN_THIS_SQL.txt
- *.bat files
```

### 1.3 Verify Critical Files Exist
```
✓ index.php
✓ .htaccess (newly created)
✓ config/database.production.php (newly created)
✓ config/database.php (current local config)
✓ includes/header.php
✓ includes/footer.php
✓ includes/functions.php
✓ All PHP module files (*.php)
```

---

## 🌐 Step 2: Setup Hosting Environment

### 2.1 Access Your Hosting Control Panel
- Login to your hosting provider (cPanel, Plesk, or custom panel)
- Locate the File Manager or FTP credentials

### 2.2 Create Database
1. Go to **MySQL Databases** or **Database Manager**
2. Create a new database (e.g., `inodesain_plantation`)
3. Create a database user with a strong password
4. Grant **ALL PRIVILEGES** to the user for this database
5. **Note down these credentials:**
   - Database Host: (usually `localhost`)
   - Database Name: `_____________`
   - Database User: `_____________`
   - Database Password: `_____________`

### 2.3 Create Directory Structure
```
/public_html/
  └── agro/              ← Create this directory
      ├── config/
      ├── includes/
      ├── reports/
      ├── database/
      └── logs/          ← Create this directory (chmod 755)
```

---

## 📤 Step 3: Upload Files

### Option A: Using FTP Client (FileZilla, WinSCP)
1. Connect to your hosting via FTP
   - Host: `ftp.inodesain.com` or provided FTP host
   - Username: Your FTP username
   - Password: Your FTP password
   - Port: 21 (or 22 for SFTP)

2. Navigate to `/public_html/agro/` directory

3. Upload all files from `c:\xampp\htdocs\agro\` to `/public_html/agro/`
   - **Exclude**: *.bak, *.md, *.sql, *.bat files
   - **Include**: .htaccess file (make sure hidden files are visible)

### Option B: Using cPanel File Manager
1. Login to cPanel
2. Open **File Manager**
3. Navigate to `/public_html/`
4. Create `agro` folder
5. Upload files using the Upload button
6. Extract if uploaded as ZIP

---

## 🗄️ Step 4: Import Database

### 4.1 Using phpMyAdmin
1. Access phpMyAdmin from your hosting control panel
2. Select the database you created (e.g., `inodesain_plantation`)
3. Click **Import** tab
4. Choose the `plantation_backup.sql` file
5. Click **Go** and wait for completion
6. Verify tables are created successfully

### 4.2 Using Command Line (if available)
```bash
mysql -u your_db_user -p your_db_name < plantation_backup.sql
```

---

## ⚙️ Step 5: Configure Production Settings

### 5.1 Update Database Configuration
1. Navigate to `/public_html/agro/config/`
2. **Backup** the original `database.php`:
   ```bash
   # Rename it to database.local.php for reference
   ```
3. **Rename** `database.production.php` to `database.php`
4. **Edit** `database.php` and update:
   ```php
   define('DB_HOST', 'localhost');              // Your DB host
   define('DB_USER', 'inodesain_dbuser');       // Your DB username
   define('DB_PASS', 'your_secure_password');   // Your DB password
   define('DB_NAME', 'inodesain_plantation');   // Your DB name
   ```

### 5.2 Verify .htaccess Configuration
1. Check that `.htaccess` exists in `/public_html/agro/`
2. Verify `RewriteBase /agro/` is set correctly
3. If SSL is available, uncomment HTTPS redirect lines

### 5.3 Set File Permissions
```bash
# Directories: 755
chmod 755 /public_html/agro/
chmod 755 /public_html/agro/config/
chmod 755 /public_html/agro/includes/
chmod 755 /public_html/agro/logs/

# Files: 644
chmod 644 /public_html/agro/*.php
chmod 644 /public_html/agro/.htaccess
chmod 644 /public_html/agro/config/database.php

# Logs directory: writable
chmod 755 /public_html/agro/logs/
```

---

## 🧪 Step 6: Test Deployment

### 6.1 Access the Application
Visit: `https://inodesain.com/agro/` or `http://inodesain.com/agro/`

### 6.2 Verify Functionality
- [ ] Homepage loads without errors
- [ ] Dashboard displays statistics correctly
- [ ] Navigation menu works
- [ ] Can access Companies page
- [ ] Can access Business Units page
- [ ] Can access Blocks page
- [ ] Database queries execute successfully

### 6.3 Check for Errors
1. If you see errors, check:
   - Database credentials in `config/database.php`
   - Database tables are imported correctly
   - File permissions are correct
   - `.htaccess` RewriteBase is set to `/agro/`

2. Check error logs:
   - PHP error log: `/public_html/agro/logs/php_error.log`
   - Server error log: Usually in cPanel or hosting control panel

---

## 🔒 Step 7: Security Hardening

### 7.1 Secure Sensitive Files
```apache
# Already configured in .htaccess:
- Blocks access to .sql, .md, .bak files
- Blocks access to config/ directory
- Blocks access to database/ directory
- Disables directory listing
```

### 7.2 Additional Security Measures
1. **Change default database prefix** (if using one)
2. **Enable SSL/HTTPS** (recommended)
3. **Regular backups** - Schedule automatic backups
4. **Update PHP version** - Keep PHP updated
5. **Monitor logs** - Check error logs regularly

### 7.3 Remove Development Files
Ensure these are NOT on production:
```
❌ *.bak files
❌ *.md documentation files (except if needed)
❌ database/*.sql files
❌ test/debug PHP files
❌ .git directory (if exists)
```

---

## 🔄 Step 8: Post-Deployment Tasks

### 8.1 Create Backup Schedule
- **Database**: Daily automated backups
- **Files**: Weekly backups
- Store backups off-server

### 8.2 Monitor Performance
- Check page load times
- Monitor database query performance
- Review error logs weekly

### 8.3 Documentation
- Document any custom configurations
- Keep credentials in a secure password manager
- Document backup/restore procedures

---

## 🆘 Troubleshooting

### Issue: "Database connection error"
**Solution:**
1. Verify database credentials in `config/database.php`
2. Check if database user has correct privileges
3. Verify database host (might not be 'localhost')
4. Check if database exists and tables are imported

### Issue: "404 Not Found" or "Page not found"
**Solution:**
1. Verify `.htaccess` file exists and is uploaded
2. Check `RewriteBase /agro/` in `.htaccess`
3. Verify mod_rewrite is enabled on server
4. Check file permissions (755 for directories, 644 for files)

### Issue: "Internal Server Error (500)"
**Solution:**
1. Check PHP error log: `/public_html/agro/logs/php_error.log`
2. Verify PHP version compatibility (7.4+)
3. Check file permissions
4. Review `.htaccess` syntax

### Issue: Blank white page
**Solution:**
1. Enable error display temporarily in `config/database.php`:
   ```php
   error_reporting(E_ALL);
   ini_set('display_errors', 1);
   ```
2. Check for PHP syntax errors
3. Verify all required files are uploaded
4. Check PHP memory limit

### Issue: CSS/Styling not loading
**Solution:**
1. Check if `includes/header.php` paths are correct
2. Verify Bootstrap CDN links are accessible
3. Check browser console for 404 errors
4. Clear browser cache

---

## 📞 Support Contacts

### Hosting Support
- Contact your hosting provider for:
  - Database access issues
  - Server configuration
  - PHP version updates
  - SSL certificate installation

### Application Support
- For application-specific issues:
  - Review error logs
  - Check database schema
  - Verify file integrity

---

## ✅ Deployment Completion Checklist

- [ ] Database exported and imported successfully
- [ ] All files uploaded to `/public_html/agro/`
- [ ] `config/database.php` updated with production credentials
- [ ] `.htaccess` file in place with correct RewriteBase
- [ ] File permissions set correctly
- [ ] Application accessible at `https://inodesain.com/agro/`
- [ ] Dashboard loads and displays data
- [ ] All modules accessible (Companies, Business Units, Blocks, etc.)
- [ ] No PHP errors displayed
- [ ] Logs directory created and writable
- [ ] Development files removed from production
- [ ] Backup schedule configured
- [ ] SSL/HTTPS enabled (if available)
- [ ] Security measures implemented

---

## 📝 Notes

**Deployment Date:** _________________

**Database Name:** _________________

**Database User:** _________________

**Hosting Provider:** _________________

**PHP Version:** _________________

**MySQL Version:** _________________

**Additional Notes:**
```
_________________________________________________
_________________________________________________
_________________________________________________
```

---

## 🎉 Congratulations!

Your erpAgro application should now be live at:
**https://inodesain.com/agro/**

For any issues, refer to the troubleshooting section or contact your hosting provider.

---

*Generated for inodesain.com/agro deployment*
*Last Updated: 2026-06-22*