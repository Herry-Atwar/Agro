# FileZilla FTP Setup for inodesain.com

## Problem
You're using MySQL port (3306) instead of FTP port. Port 3306 is for database connections, not file uploads.

## Correct FileZilla Settings

### For FTP Connection:

1. **Open FileZilla**

2. **Click "File" → "Site Manager"** (or press Ctrl+S)

3. **Click "New Site"** and name it "inodesain.com"

4. **Enter these settings:**

   ```
   Protocol: FTP - File Transfer Protocol
   Host: inodesain.com (or ftp.inodesain.com)
   Port: 21 (leave blank or use 21)
   Encryption: Use explicit FTP over TLS if available
   Logon Type: Normal
   User: u208932211_admin (or your cPanel username)
   Password: [your FTP password - same as cPanel password]
   ```

5. **Click "Connect"**

### Alternative: SFTP (More Secure)

If FTP doesn't work, try SFTP:

```
Protocol: SFTP - SSH File Transfer Protocol
Host: inodesain.com
Port: 22 (or leave blank)
Logon Type: Normal
User: u208932211_admin
Password: [your password]
```

## Quick Connect Method

Instead of Site Manager, you can use Quick Connect bar at the top:

```
Host: ftp.inodesain.com (or inodesain.com)
Username: u208932211_admin
Password: [your password]
Port: 21 (for FTP) or 22 (for SFTP)
```

Then click "Quickconnect"

## Common FTP Ports

- **Port 21** = Standard FTP
- **Port 22** = SFTP (SSH File Transfer)
- **Port 990** = FTPS (FTP over SSL)
- ~~Port 3306~~ = MySQL Database (NOT for file uploads!)

## After Connecting Successfully

1. **Set Transfer Mode to BINARY:**
   - Go to: Transfer → Transfer Type → Binary
   - This is CRITICAL to prevent file corruption!

2. **Navigate to your directory:**
   - Remote site (right panel): `/public_html/agro/`
   - Local site (left panel): `c:\xampp\htdocs\agro\`

3. **Upload files:**
   - Drag files from left to right
   - Or right-click file → Upload

## Troubleshooting

### Problem: "Connection refused" or "Cannot connect"

**Try these solutions:**

1. **Check if FTP is enabled in cPanel:**
   - Login to cPanel at: https://inodesain.com:2083
   - Go to "FTP Accounts"
   - Make sure FTP account exists

2. **Try different host names:**
   - `ftp.inodesain.com`
   - `inodesain.com`
   - Your server IP address

3. **Try different ports:**
   - Port 21 (FTP)
   - Port 22 (SFTP)
   - Port 990 (FTPS)

4. **Check firewall:**
   - Windows Firewall might block FTP
   - Temporarily disable to test

### Problem: "Login incorrect" or "Authentication failed"

**Solutions:**

1. **Verify credentials in cPanel:**
   - Login to cPanel
   - Go to "FTP Accounts"
   - Check username format (might be: `u208932211_admin@inodesain.com`)

2. **Reset FTP password:**
   - In cPanel → FTP Accounts
   - Change password for your FTP account

3. **Try full username format:**
   - `u208932211_admin@inodesain.com`
   - Or just `u208932211_admin`

## Alternative: Use cPanel File Manager

If FileZilla doesn't work, use cPanel File Manager:

1. **Login to cPanel:**
   ```
   https://inodesain.com:2083
   Username: u208932211_admin
   Password: [your password]
   ```

2. **Open File Manager:**
   - Click "File Manager" icon
   - Navigate to: `/public_html/agro/`

3. **Upload files:**
   - Click "Upload" button
   - Select files from your computer
   - Wait for upload to complete

4. **IMPORTANT: Check encoding:**
   - After upload, right-click file
   - Select "Edit"
   - Make sure encoding is UTF-8 without BOM

## Files to Upload

Once connected, upload these files to `/public_html/agro/`:

1. **inventory_cpo.php** (177 lines, ~5.5 KB)
   - Clean production version
   - Upload in BINARY mode

2. **Set permissions after upload:**
   ```
   chmod 644 inventory_cpo.php
   ```

## Test After Upload

Visit: https://inodesain.com/agro/inventory_cpo.php

Expected: Page loads with CPO Inventory Report (no debug output)

## Summary

✅ **Correct Settings:**
- Host: inodesain.com or ftp.inodesain.com
- Port: 21 (FTP) or 22 (SFTP)
- Username: u208932211_admin
- Password: Your cPanel password

❌ **Wrong Settings:**
- Port: 3306 (This is for MySQL, not FTP!)

---
**Note:** Port 3306 is only used for database connections in your `config/database.php` file, NOT for uploading files with FileZilla!