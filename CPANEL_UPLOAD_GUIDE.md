# Upload Files Using cPanel File Manager

## Why Some Files Work and Others Don't
The files that don't work are getting **corrupted during upload**. This happens when:
- Files are uploaded in wrong encoding
- Files have BOM (Byte Order Mark) added
- Browser upload changes file content

## Solution: Upload Correctly Through cPanel

### Step 1: Login to cPanel
1. Go to: **https://inodesain.com:2083**
2. Username: `u208932211_admin`
3. Password: [your password]
4. Click "Login"

### Step 2: Open File Manager
1. Find and click **"File Manager"** icon in cPanel
2. Navigate to: **public_html/agro/**
3. You should see your existing files

### Step 3: Upload inventory_cpo.php

#### Method A: Direct Upload (Recommended)
1. Click **"Upload"** button at the top
2. Click **"Select File"** button
3. Browse to: `c:\xampp\htdocs\agro\inventory_cpo.php`
4. Select the file and click Open
5. **Wait for upload to complete** (green bar reaches 100%)
6. Click **"Go Back to..."** link

#### Method B: Create New File (If upload corrupts)
1. Click **"+ File"** button at the top
2. Enter filename: `inventory_cpo_new.php`
3. Click "Create New File"
4. Right-click the new file → **"Edit"**
5. **IMPORTANT:** In the editor, check encoding at top-right
   - Should be: **UTF-8** (NOT UTF-8 with BOM)
6. Open `c:\xampp\htdocs\agro\inventory_cpo.php` in Notepad++
7. Select All (Ctrl+A) and Copy (Ctrl+C)
8. Paste into cPanel editor
9. Click **"Save Changes"** button
10. Close editor

### Step 4: Verify File
1. Right-click `inventory_cpo.php` (or `inventory_cpo_new.php`)
2. Select **"View"** to check content
3. Look for:
   - ✅ File starts with `<?php` (no spaces before)
   - ✅ No weird characters at start
   - ✅ Code looks clean and readable
   - ❌ No `�` or strange symbols

### Step 5: Set Permissions
1. Right-click the file
2. Select **"Permissions"**
3. Set to: **644**
   - Owner: Read + Write (6)
   - Group: Read (4)
   - Public: Read (4)
4. Click "Change Permissions"

### Step 6: Test
Open in browser:
```
https://inodesain.com/agro/inventory_cpo.php
```

Expected result:
- ✅ Page loads without errors
- ✅ Shows "CPO Inventory Report" title
- ✅ Displays summary cards
- ✅ Shows storage tanks table
- ✅ No debug output

## If File Still Doesn't Work

### Option 1: Use Code Editor in cPanel
1. In File Manager, click **"+ File"**
2. Name it: `inventory_cpo_manual.php`
3. Right-click → **"Code Editor"** (not "Edit")
4. Copy content from local file
5. Paste and save
6. Test at: `https://inodesain.com/agro/inventory_cpo_manual.php`

### Option 2: Create via SSH/Terminal (if available)
```bash
cd /home/u208932211/public_html/agro
nano inventory_cpo.php
# Paste content, save with Ctrl+X, Y, Enter
chmod 644 inventory_cpo.php
```

### Option 3: Use WinSCP Instead of FileZilla
1. Download WinSCP: https://winscp.net/
2. Connect with:
   - Protocol: SFTP
   - Host: inodesain.com
   - Port: 22
   - Username: u208932211_admin
   - Password: [your password]
3. Upload files (WinSCP handles encoding better)

## Troubleshooting

### Problem: File shows blank page after upload
**Cause:** File corrupted during upload (encoding changed)

**Solution:**
1. Delete the uploaded file
2. Use Method B (Create New File) above
3. Or use Code Editor instead of regular Edit
4. Make sure encoding is UTF-8 (not UTF-8 with BOM)

### Problem: File has weird characters
**Cause:** BOM (Byte Order Mark) added during upload

**Solution:**
1. In cPanel editor, check encoding dropdown
2. Change to "UTF-8" (not "UTF-8 with BOM")
3. Save again

### Problem: Permission denied
**Cause:** Wrong file permissions

**Solution:**
```
Right-click file → Permissions → Set to 644
```

## Files to Upload

### Priority 1 (Upload Now):
- ✅ **inventory_cpo.php** (177 lines, ~5.5 KB)
  - Clean production version
  - No debug output

### Already Working (Don't touch):
- ✅ divisions_simple.php
- ✅ .htaccess
- ✅ config/database.php

### Can Delete (After testing):
- ❌ inventory_cpo_debug.php
- ❌ inventory_cpo_clean.php
- ❌ test_divisions.php
- ❌ check_file_issue.php

## Success Checklist

After upload, verify:
- [ ] File exists in `/public_html/agro/`
- [ ] File size is correct (~5.5 KB)
- [ ] File permissions are 644
- [ ] File content looks clean (no weird characters)
- [ ] Page loads at: https://inodesain.com/agro/inventory_cpo.php
- [ ] No debug output visible
- [ ] Data displays correctly

## Why This Method Works

✅ **cPanel File Manager:**
- Direct server access
- No FTP encoding issues
- Can check file immediately
- Can edit if needed

❌ **FileZilla/FTP:**
- Can corrupt files during transfer
- Encoding issues
- Transfer mode problems
- Connection issues

## Next Steps After Success

1. Test the page thoroughly
2. Delete debug files from server
3. Upload other files if needed (blocks.php, etc.)
4. Document what works

---
**Remember:** Always check file encoding in cPanel editor - must be UTF-8 (not UTF-8 with BOM)!