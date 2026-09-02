# 🚀 How to Upload divisions.php to inodesain.com/agro

## ✅ Test Result: divisions.php is MISSING on server

The diagnostic confirmed the file doesn't exist on your production server. Here's how to upload it:

---

## Method 1: Using FTP Client (Recommended)

### Step 1: Download FTP Client
If you don't have one, download **FileZilla** (free):
- https://filezilla-project.org/download.php?type=client

### Step 2: Connect to Your Server
1. Open FileZilla
2. Enter your FTP credentials:
   - **Host**: `ftp.inodesain.com` (or your FTP host)
   - **Username**: Your FTP username
   - **Password**: Your FTP password
   - **Port**: 21 (or 22 for SFTP)
3. Click **Quickconnect**

### Step 3: Navigate to Correct Directory
**Left side (Local):**
- Navigate to: `c:\xampp\htdocs\agro\`
- Find `divisions.php`

**Right side (Remote):**
- Navigate to: `/public_html/agro/`
- This is where the file should go

### Step 4: Upload the File
1. **Right-click** on `divisions.php` in the left panel
2. Select **Upload**
3. Wait for transfer to complete
4. **Verify** the file appears in the right panel
5. Check file size matches (should be ~15-20 KB)

### Step 5: Set Permissions
1. **Right-click** on `divisions.php` in the right panel
2. Select **File permissions**
3. Set to: **644** (or check: Owner Read+Write, Group Read, Public Read)
4. Click **OK**

---

## Method 2: Using cPanel File Manager

### Step 1: Access cPanel
1. Login to your hosting control panel
2. Find and click **File Manager**

### Step 2: Navigate to Directory
1. Go to: `/public_html/agro/`
2. You should see other PHP files here (index.php, companies.php, business_units.php, etc.)

### Step 3: Upload File
1. Click **Upload** button at the top
2. Click **Select File**
3. Browse to: `c:\xampp\htdocs\agro\divisions.php`
4. Select the file and click **Open**
5. Wait for upload to complete (100%)
6. Close the upload dialog

### Step 4: Verify Upload
1. Back in File Manager, refresh the page
2. Look for `divisions.php` in the file list
3. Check file size (should be around 15-20 KB)
4. If size is 0 KB or very small, the upload failed - try again

### Step 5: Set Permissions
1. **Right-click** on `divisions.php`
2. Select **Change Permissions**
3. Set to: **644**
   - Owner: Read + Write (checked)
   - Group: Read (checked)
   - World: Read (checked)
4. Click **Change Permissions**

---

## Method 3: Using WinSCP (Alternative)

### Step 1: Download WinSCP
- https://winscp.net/eng/download.php

### Step 2: Connect
1. Open WinSCP
2. Select **SFTP** or **FTP** protocol
3. Enter:
   - **Host name**: inodesain.com
   - **User name**: Your FTP username
   - **Password**: Your FTP password
4. Click **Login**

### Step 3: Upload
1. **Left panel**: Navigate to `c:\xampp\htdocs\agro\`
2. **Right panel**: Navigate to `/public_html/agro/`
3. **Drag** `divisions.php` from left to right
4. Confirm upload
5. Set permissions to 644

---

## ✅ Verification Steps

After uploading, verify:

### 1. Check File Exists
Visit: `https://inodesain.com/agro/test_divisions.php`
- Should now show: "✓ divisions.php exists"
- Should show file size (not 0 bytes)

### 2. Test the Page
Visit: `https://inodesain.com/agro/divisions.php`
- Should load the Divisions Management page
- Should show divisions list (or "No divisions found" if database is empty)

### 3. Check from Dashboard
Visit: `https://inodesain.com/agro/`
- Click **Divisions** in the sidebar
- Should navigate to divisions page successfully

---

## 🔧 Troubleshooting

### Issue: Upload keeps failing
**Solution:**
- Check your internet connection
- Try uploading during off-peak hours
- Use Binary transfer mode (not ASCII)
- Try a different FTP client

### Issue: File uploads but shows 0 bytes
**Solution:**
- The upload was interrupted
- Delete the file and upload again
- Check disk space on your hosting account

### Issue: File exists but page still blank
**Solution:**
1. Check file permissions (should be 644)
2. Check PHP error log in cPanel
3. Run test_divisions.php to see database errors
4. The file might be corrupted - re-upload

### Issue: Permission denied
**Solution:**
- You might not have write access to /public_html/agro/
- Contact your hosting provider
- Check if you're in the correct directory

---

## 📝 Quick Checklist

- [ ] FTP credentials ready
- [ ] Connected to server successfully
- [ ] Navigated to `/public_html/agro/` directory
- [ ] Located `divisions.php` in local `c:\xampp\htdocs\agro\`
- [ ] Uploaded file successfully
- [ ] File size matches (~15-20 KB, not 0 bytes)
- [ ] Permissions set to 644
- [ ] Tested with test_divisions.php (shows file exists)
- [ ] Tested divisions.php page (loads successfully)
- [ ] Can access from dashboard sidebar

---

## 🎯 Expected Result

After successful upload:
- File exists at: `/public_html/agro/divisions.php`
- File size: ~15-20 KB
- Permissions: 644
- Page loads at: `https://inodesain.com/agro/divisions.php`
- Shows divisions management interface

---

## 📞 Need Help?

If you're still having issues:
1. Take a screenshot of your FTP client showing the file list
2. Check the file size on both local and remote
3. Run test_divisions.php and share the results
4. Check your hosting control panel for error logs

---

**After successful upload, you can delete the test files:**
- `test_divisions.php`
- `test_business_units.php`

Good luck! 🚀