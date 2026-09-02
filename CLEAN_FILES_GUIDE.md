# 🔧 Clean Files Creation Guide

## Problem
Some PHP files get corrupted during upload, causing blank pages or "page doesn't exist" errors.

## Solution
Create clean versions of all files locally, then upload them.

---

## Method 1: Using the Batch Script (Windows - Easiest)

### Step 1: Run the Batch Script
1. Navigate to: `c:\xampp\htdocs\agro\`
2. Double-click: `create_clean_files.bat`
3. Press any key when prompted
4. Wait for completion

### Step 2: Files Created
The script creates clean versions:
- `blocks_clean.php` (from blocks.php)
- `inventory_cpo_clean.php` (from inventory_cpo.php)
- `inventory_kernel_clean.php` (from inventory_kernel.php)
- `inventory_materials_clean.php` (from inventory_materials.php)

### Step 3: Upload Clean Files
1. Open FileZilla (or your FTP client)
2. Set transfer mode to **Binary**
3. Upload all `*_clean.php` files to `/public_html/agro/`

### Step 4: Test Clean Files
Visit these URLs to test:
- `https://inodesain.com/agro/blocks_clean.php`
- `https://inodesain.com/agro/inventory_cpo_clean.php`
- `https://inodesain.com/agro/inventory_kernel_clean.php`
- `https://inodesain.com/agro/inventory_materials_clean.php`

### Step 5: Replace Original Files
If clean files work, on the server:
1. Delete the old corrupted files:
   - `blocks.php`
   - `inventory_cpo.php`
   - `inventory_kernel.php`
   - `inventory_materials.php`

2. Rename clean files:
   - `blocks_clean.php` → `blocks.php`
   - `inventory_cpo_clean.php` → `inventory_cpo.php`
   - `inventory_kernel_clean.php` → `inventory_kernel.php`
   - `inventory_materials_clean.php` → `inventory_materials.php`

---

## Method 2: Manual Copy (Alternative)

### For Each File:

1. **Open Command Prompt** in `c:\xampp\htdocs\agro\`
2. **Run these commands:**

```cmd
copy /b blocks.php blocks_clean.php
copy /b inventory_cpo.php inventory_cpo_clean.php
copy /b inventory_kernel.php inventory_kernel_clean.php
copy /b inventory_materials.php inventory_materials_clean.php
```

3. **Upload the *_clean.php files**
4. **Test and rename** as described above

---

## Method 3: Create ZIP (Best for Multiple Files)

### Step 1: Select Files to ZIP
In `c:\xampp\htdocs\agro\`, select these files:
- `blocks.php`
- `inventory_cpo.php`
- `inventory_kernel.php`
- `inventory_materials.php`

### Step 2: Create ZIP
1. Right-click selected files
2. **Send to > Compressed (zipped) folder**
3. Name it: `clean_files.zip`

### Step 3: Upload ZIP
1. Login to cPanel
2. File Manager → `/public_html/agro/`
3. Upload `clean_files.zip`
4. Right-click ZIP → **Extract**
5. Choose: Extract to `/public_html/agro/`
6. Click **Extract Files**

### Step 4: Verify
All files should now work correctly!

---

## Method 4: Using PowerShell (Advanced)

```powershell
# Navigate to directory
cd c:\xampp\htdocs\agro

# Create clean copies
$files = @('blocks.php', 'inventory_cpo.php', 'inventory_kernel.php', 'inventory_materials.php')
foreach ($file in $files) {
    $cleanName = $file -replace '\.php$', '_clean.php'
    Copy-Item $file $cleanName -Force
    Write-Host "Created: $cleanName"
}
```

---

## Why This Works

### The Problem:
- FTP in ASCII mode corrupts files
- Line endings get converted
- Special characters get mangled
- BOM (Byte Order Mark) gets added

### The Solution:
- `/b` flag in `copy` command = Binary copy
- Preserves file exactly as-is
- No encoding changes
- No BOM addition
- Clean, working file

---

## Verification Checklist

After uploading clean files:

- [ ] `blocks_clean.php` loads without errors
- [ ] `inventory_cpo_clean.php` loads without errors
- [ ] `inventory_kernel_clean.php` loads without errors
- [ ] `inventory_materials_clean.php` loads without errors
- [ ] All show proper content (not blank)
- [ ] No "page doesn't exist" errors

If all checked:
- [ ] Renamed clean files to original names
- [ ] Tested original URLs work
- [ ] Deleted `*_clean.php` files
- [ ] Application fully functional

---

## Quick Reference

### Files to Create:
```
blocks.php → blocks_clean.php
inventory_cpo.php → inventory_cpo_clean.php
inventory_kernel.php → inventory_kernel_clean.php
inventory_materials.php → inventory_materials_clean.php
```

### Upload Location:
```
/public_html/agro/
```

### Test URLs:
```
https://inodesain.com/agro/blocks_clean.php
https://inodesain.com/agro/inventory_cpo_clean.php
https://inodesain.com/agro/inventory_kernel_clean.php
https://inodesain.com/agro/inventory_materials_clean.php
```

### Final URLs (after rename):
```
https://inodesain.com/agro/blocks.php
https://inodesain.com/agro/inventory_cpo.php
https://inodesain.com/agro/inventory_kernel.php
https://inodesain.com/agro/inventory_materials.php
```

---

## Troubleshooting

### If clean files still don't work:
1. Check file was uploaded completely (size matches)
2. Verify file permissions (644)
3. Check PHP error log in cPanel
4. Try uploading via ZIP instead

### If ZIP extraction fails:
1. Make sure it's ZIP format (not RAR)
2. Extract locally, then upload files individually
3. Use Binary mode in FTP

### If files work but show errors:
1. Check database connection
2. Verify required tables exist
3. Run `fix_all_files.php` to diagnose

---

## Success!

Once all files work:
✅ All pages load correctly
✅ No blank pages
✅ No corruption errors
✅ Application fully functional

**Your deployment is complete!** 🎉