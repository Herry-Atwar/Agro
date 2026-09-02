# Upload inventory_cpo.php to Production Server

## Overview
This guide explains how to upload the clean `inventory_cpo.php` file to your production server at `inodesain.com/agro`.

## What We Fixed
- ✅ Removed all debug echo statements
- ✅ Removed all flush() calls
- ✅ Removed debug comments and markers
- ✅ Removed "DEBUG MODE" from page title
- ✅ Removed debug alert boxes
- ✅ Kept all functional code intact
- ✅ Clean, production-ready code (177 lines)

## Files to Upload

### Main File
- **inventory_cpo.php** (177 lines, ~5.5 KB)
  - Clean production version
  - No debug output
  - All functionality preserved

## Upload Instructions

### Step 1: Backup Current File (if exists)
```bash
# On server, rename existing file
mv inventory_cpo.php inventory_cpo.php.old
```

### Step 2: Upload Using FileZilla

1. **Open FileZilla**
2. **Connect to your server**
   - Host: inodesain.com
   - Username: your_username
   - Password: your_password

3. **IMPORTANT: Set Transfer Mode to BINARY**
   - Go to: Transfer → Transfer Type → Binary
   - This prevents file corruption!

4. **Navigate to directory**
   - Remote site: `/public_html/agro/`
   - Local site: `c:\xampp\htdocs\agro\`

5. **Upload the file**
   - Drag `inventory_cpo.php` from local to remote
   - Wait for upload to complete
   - Verify file size matches (should be ~5.5 KB)

### Step 3: Set File Permissions
```bash
chmod 644 inventory_cpo.php
```

### Step 4: Test the Page

1. **Open in browser:**
   ```
   https://inodesain.com/agro/inventory_cpo.php
   ```

2. **Expected Result:**
   - Page loads without errors
   - Shows "CPO Inventory Report" title (no DEBUG MODE)
   - Displays summary cards with stock data
   - Shows storage tanks table
   - No debug messages or step-by-step output

3. **If page is blank:**
   - Check file was uploaded in BINARY mode
   - Check file permissions (should be 644)
   - Check PHP error logs on server
   - Try uploading again in BINARY mode

## Verification Checklist

- [ ] File uploaded successfully
- [ ] File size is correct (~5.5 KB)
- [ ] Page loads without errors
- [ ] No debug output visible
- [ ] Summary cards display correctly
- [ ] Storage tanks table shows data
- [ ] Page title is "CPO Inventory Report" (no DEBUG MODE)

## Troubleshooting

### Problem: Page shows blank/white screen
**Solution:**
1. Re-upload in BINARY mode (not ASCII)
2. Check file permissions: `chmod 644 inventory_cpo.php`
3. Check PHP error logs

### Problem: Database views not found
**Solution:**
1. Make sure you imported `all_cpo_views_fixed.sql`
2. Check views exist:
   ```sql
   SHOW TABLES LIKE 'vw_%';
   ```

### Problem: Storage tanks table missing
**Solution:**
1. Import storage tanks schema
2. Run seeder: `seed_storage_tanks.php`

## Related Files

### Already Uploaded (Working)
- ✅ `.htaccess` - Web server configuration
- ✅ `config/database.php` - Database connection
- ✅ `divisions_simple.php` - Working divisions page
- ✅ `inventory_cpo_debug.php` - Debug version (can be deleted after testing)

### Database Views (Already Imported)
- ✅ `vw_tank_stock_summary` - Tank stock summary
- ✅ `vw_stock_aging` - Stock aging analysis
- ✅ `vw_tank_utilization_alerts` - Tank utilization alerts

## Next Steps After Success

1. **Delete debug files from server:**
   ```bash
   rm inventory_cpo_debug.php
   rm inventory_cpo_clean.php
   rm create_inventory_cpo_clean.php
   ```

2. **Test other inventory pages:**
   - inventory_kernel.php
   - inventory_materials.php

3. **Fix blocks.php if needed:**
   - Use same method (create clean version)
   - Upload in BINARY mode

## Success Criteria

✅ Page loads at: https://inodesain.com/agro/inventory_cpo.php
✅ No debug output visible
✅ All data displays correctly
✅ No PHP errors
✅ Clean, professional appearance

## Support

If you encounter issues:
1. Check FileZilla transfer mode (must be BINARY)
2. Check file permissions (644)
3. Check PHP error logs on server
4. Compare file sizes (local vs server)
5. Try uploading again in BINARY mode

---
**Created:** 2026-06-22
**File:** inventory_cpo.php (177 lines)
**Status:** Ready for production deployment