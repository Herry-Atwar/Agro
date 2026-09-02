# erpAgro - Agrobusiness Solution - Installation Guide

## System Requirements

- **XAMPP** (Apache + MariaDB/MySQL + PHP)
  - PHP 7.4 or higher
  - MariaDB 10.4 or higher / MySQL 5.7 or higher
- **Web Browser** (Chrome, Firefox, Edge, Safari)
- **Minimum 2GB RAM**
- **500MB Free Disk Space**

## Installation Steps

### 1. Install XAMPP

1. Download XAMPP from [https://www.apachefriends.org](https://www.apachefriends.org)
2. Install XAMPP to default location (C:\xampp on Windows)
3. Start **Apache** and **MySQL** services from XAMPP Control Panel

### 2. Copy Project Files

1. Copy the `plantation_master` folder to XAMPP's `htdocs` directory:
   ```
   C:\xampp\htdocs\plantation_master\
   ```

2. Your folder structure should look like:
   ```
   C:\xampp\htdocs\plantation_master\
   ├── config/
   │   └── database.php
   ├── database/
   │   └── schema.sql
   ├── includes/
   │   ├── header.php
   │   ├── footer.php
   │   └── functions.php
   ├── index.php
   ├── companies.php
   ├── business_units.php
   ├── divisions.php
   ├── planting_years.php
   ├── blocks.php
   └── INSTALLATION.md
   ```

### 3. Import Schema to Existing Database

#### Option A: Using phpMyAdmin (Recommended for Beginners)

1. Open your web browser and go to: `http://localhost/phpmyadmin`
2. Click on the existing **`plantation`** database in the left sidebar
3. Click on **"Import"** tab
4. Click **"Choose File"** and select: `C:\xampp\htdocs\plantation_master\database\schema.sql`
5. Click **"Go"** at the bottom
6. Wait for success message: "Import has been successfully finished"

**Note**: The schema will add new tables to your existing `plantation` database without affecting existing data.

#### Option B: Using MySQL Command Line

1. Open Command Prompt (Windows) or Terminal (Mac/Linux)
2. Navigate to XAMPP's MySQL bin directory:
   ```bash
   cd C:\xampp\mysql\bin
   ```
3. Login to MySQL:
   ```bash
   mysql -u root -p
   ```
   (Press Enter when asked for password - default is empty)
4. Run the SQL file:
   ```sql
   source C:/xampp/htdocs/plantation_master/database/schema.sql
   ```
5. Verify tables were created:
   ```sql
   USE plantation;
   SHOW TABLES;
   ```
6. Exit MySQL:
   ```sql
   exit;
   ```

### 4. Configure Database Connection

1. Open `config/database.php` in a text editor
2. Verify the database configuration (default XAMPP settings):
   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');  // Empty password for XAMPP
   define('DB_NAME', 'plantation_master');
   ```
3. If you changed MySQL password, update `DB_PASS` accordingly

### 5. Access the Application

1. Open your web browser
2. Go to: `http://localhost/plantation_master/`
3. You should see the Dashboard with sample data

## Default Sample Data

The system comes with pre-loaded sample data:

### Companies
- PT Perkebunan Nusantara (PTPN)
- Astra Agro Lestari (ASTRA)

### Business Units
- Sungai Raya Estate (Estate)
- Bukit Hijau Estate (Estate)
- Riau Palm Oil Mill (Mill)
- Tanjung Mas Estate (Estate)

### Divisions
- Multiple Afdeling (A, B, C) per estate
- Processing Unit for mill

### Planting Years
- Years from 2013 to 2020

### Blocks
- Multiple blocks per planting year
- Mix of TBM (Immature) and TM (Mature) status

## Verification Steps

### 1. Check Database Connection

Visit: `http://localhost/plantation_master/`

- If you see the dashboard, database connection is successful
- If you see an error, check:
  - MySQL service is running in XAMPP
  - Database name is correct
  - Database credentials are correct

### 2. Test CRUD Operations

1. **Companies**: Go to Companies menu
   - Try adding a new company
   - Edit existing company
   - View business units

2. **Business Units**: Go to Business Units menu
   - Add a new business unit
   - Select company from dropdown
   - Choose unit type (Estate/Mill/Nursery)

3. **Divisions**: Go to Divisions menu
   - Add division under a business unit
   - View hierarchy

4. **Planting Years**: Go to Planting Years menu
   - Add planting year for a division
   - View blocks grouped by year

5. **Blocks**: Go to Blocks menu
   - Add new block
   - View complete hierarchy

## Troubleshooting

### Problem: "Database Connection Failed"

**Solution:**
1. Check if MySQL is running in XAMPP Control Panel
2. Verify database name exists in phpMyAdmin
3. Check database credentials in `config/database.php`

### Problem: "Table doesn't exist"

**Solution:**
1. Re-import the `schema.sql` file
2. Make sure you selected the correct database before importing

### Problem: "Page not found" or "404 Error"

**Solution:**
1. Verify folder is in correct location: `C:\xampp\htdocs\plantation_master\`
2. Check Apache is running in XAMPP
3. Use correct URL: `http://localhost/plantation_master/`

### Problem: "Permission denied" errors

**Solution:**
1. On Windows: Run XAMPP as Administrator
2. On Linux/Mac: Check folder permissions
   ```bash
   chmod -R 755 /opt/lampp/htdocs/plantation_master/
   ```

### Problem: PHP errors displayed

**Solution:**
1. Check PHP version (must be 7.4+)
2. Enable required PHP extensions in `php.ini`:
   - `extension=pdo_mysql`
   - `extension=mbstring`

## System Features

### 5-Level Hierarchy
```
Company
  └── Business Unit (Estate/Mill/Nursery)
      └── Division (Afdeling/Processing Unit)
          └── Planting Year (2020, 2021, etc.)
              └── Block (Individual planting blocks)
```

### Key Features
- ✅ Complete CRUD operations for all levels
- ✅ Hierarchical data management
- ✅ Automatic area calculations
- ✅ Plant age tracking
- ✅ Status management (TBM/TM/TR)
- ✅ Search and filter capabilities
- ✅ Export to CSV
- ✅ Responsive design (mobile-friendly)
- ✅ Dashboard with statistics

## Next Steps

After successful installation:

1. **Customize Sample Data**
   - Update company information
   - Add your actual estates and mills
   - Configure divisions and blocks

2. **Add Plant Varieties**
   - Go to Plant Varieties menu
   - Add your oil palm varieties/clones

3. **Configure Users** (Future Enhancement)
   - Set up user accounts
   - Assign roles and permissions

4. **Generate Reports**
   - Use the Reports menu
   - Export data for analysis

## Support

For issues or questions:
- Check this installation guide
- Review the troubleshooting section
- Check XAMPP documentation: https://www.apachefriends.org/docs/

## System Information

- **Version**: 1.0.0
- **Database**: MariaDB/MySQL
- **Framework**: Pure PHP (No framework)
- **Frontend**: Bootstrap 5
- **Icons**: Bootstrap Icons

## Security Notes

⚠️ **Important for Production Use:**

1. Change default MySQL password
2. Update `DB_PASS` in `config/database.php`
3. Implement user authentication
4. Use HTTPS for production
5. Regular database backups
6. Implement input validation
7. Add CSRF protection

## Backup Recommendations

### Database Backup

**Using phpMyAdmin:**
1. Go to `http://localhost/phpmyadmin`
2. Select `plantation_master` database
3. Click **"Export"** tab
4. Choose **"Quick"** export method
5. Click **"Go"**
6. Save the SQL file

**Using Command Line:**
```bash
cd C:\xampp\mysql\bin
mysqldump -u root plantation_master > backup.sql
```

### Restore Database

**Using phpMyAdmin:**
1. Drop existing database (if needed)
2. Create new database
3. Import the backup SQL file

**Using Command Line:**
```bash
mysql -u root plantation_master < backup.sql
```

## Performance Tips

1. **For Large Datasets:**
   - Enable MySQL query cache
   - Add indexes to frequently searched columns
   - Implement pagination (already included)

2. **For Multiple Users:**
   - Increase PHP memory limit in `php.ini`
   - Optimize MySQL configuration
   - Consider using connection pooling

## Changelog

### Version 1.0.0 (Initial Release)
- Complete 5-level hierarchy implementation
- CRUD operations for all entities
- Dashboard with statistics
- Sample data included
- Responsive design
- Export functionality

---

**Installation Complete!** 🎉

You can now start using the erpAgro - Agrobusiness Solution.

Access the system at: `http://localhost/plantation_master/`