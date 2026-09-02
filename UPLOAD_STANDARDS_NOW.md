# 🚀 Upload Required — Standards & Q&A Update
**Target:** https://inodesain.com/agro/

Three files were modified/created locally and must be uploaded to the cloud.
Upload all three in one go — they depend on each other.

---

## Files to Upload → `/public_html/agro/`

| # | Local path | Remote path | Status |
|---|---|---|---|
| 1 | `agro/standards.php` | `/public_html/agro/standards.php` | 🆕 **NEW FILE** |
| 2 | `agro/qna.php` | `/public_html/agro/qna.php` | ✏️ Updated |
| 3 | `agro/includes/header.php` | `/public_html/agro/includes/header.php` | ✏️ Updated |

---

## How to Upload (FileZilla)

1. Open FileZilla and connect to `inodesain.com` (port 21 or 22)
2. Local left panel: navigate to `c:\xampp3\htdocs\agro\`
3. Remote right panel: navigate to `/public_html/agro/`

**Upload file 1 — new page:**
- Drag `standards.php` from left to right into `/public_html/agro/`

**Upload file 2 — updated Q&A:**
- Drag `qna.php` from left to right (overwrite existing)

**Upload file 3 — updated nav:**
- In remote panel, open `includes/` subfolder
- Drag `includes/header.php` from left to right (overwrite existing)

> **Transfer mode: BINARY** (Transfer → Transfer Type → Binary)

---

## How to Upload (cPanel File Manager)

1. Login to cPanel: https://inodesain.com:2083
2. Open **File Manager** → navigate to `/public_html/agro/`
3. Click **Upload** → select `standards.php` → upload
4. Click **Upload** → select `qna.php` → upload (overwrite)
5. Navigate to `/public_html/agro/includes/`
6. Click **Upload** → select `header.php` → upload (overwrite)

---

## What Changed

### `standards.php` (NEW)
- Standalone Standards Reference page
- Shows all 20+ GAPKI/PPKS/SNI/Permentan benchmarks
- Filterable by category: Perkebunan, Pabrik, Infrastruktur, Keberlanjutan, Keuangan
- URL: https://inodesain.com/agro/standards.php

### `qna.php` (updated)
- Added `standards_list` render case — typing *"Lihat semua standar GAPKI"* or *"Daftar standar"* in Q&A now shows the full library as a table
- Added two new example pills: **"Lihat semua standar GAPKI"** and **"Daftar standar"**

### `includes/header.php` (updated)
- Added **Standar** nav link (with 🏆 icon) in both the top navbar and sidebar, directly below Q&A

---

## Verify After Upload

| URL | Expected result |
|---|---|
| https://inodesain.com/agro/standards.php | Page loads — category cards + tables |
| https://inodesain.com/agro/qna.php | "Lihat semua standar GAPKI" pill visible |
| Any page | "Standar" link visible in top navbar and sidebar |
