# Fix for Missing harvest_realizations Table Error

## Problem
```
Fatal error: Uncaught PDOException: SQLSTATE[42S02]: Base table or view not found: 
1146 Table 'plantation.harvest_realizations' doesn't exist in 
C:\xampp\htdocs\agro\analytics.php:38
```

## Root Cause
The `harvest_realizations` table has not been created in the `plantation` database. The analytics.php file requires this table to generate production analytics reports.

## Solution Options

### Option 1: Using phpMyAdmin (RECOMMENDED - Easiest)

1. Open your browser and go to: `http://localhost/phpmyadmin`
2. Click on the `plantation` database in the left sidebar
3. Click on the "SQL" tab at the top
4. Open the file: `C:\xampp\htdocs\agro\create_harvest_tables_safe.sql`
5. Copy ALL the content from that file
6. Paste it into the SQL query box in phpMyAdmin
7. Click "Go" button to execute
8. You should see "SUCCESS: All harvest tables created successfully!" message

**Note:** This version creates tables WITHOUT foreign key constraints to avoid dependency issues.

### Option 2: Using MySQL Command Line

1. Open Command Prompt (cmd)
2. Navigate to MySQL bin directory:
   ```
   cd C:\xampp\mysql\bin
   ```
3. Login to MySQL:
   ```
   mysql -u root -p
   ```
   (Press Enter if no password is set)
4. Execute the SQL file:
   ```
   USE plantation;
   SOURCE C:/xampp/htdocs/agro/create_harvest_tables_safe.sql;
   ```

### Option 3: Using the PHP Fix Script

1. Open your browser
2. Navigate to: `http://localhost/agro/fix_harvest_table.php`
3. The script will automatically create all required tables
4. You'll see a success message with table details

### Option 4: Manual Table Creation via phpMyAdmin

If you prefer to create just the essential table manually:

1. Go to phpMyAdmin → plantation database
2. Click "SQL" tab
3. Paste and execute this minimal SQL:

```sql
CREATE TABLE IF NOT EXISTS harvest_realizations (
    harvest_id INT AUTO_INCREMENT PRIMARY KEY,
    harvest_number VARCHAR(50) NOT NULL UNIQUE,
    harvest_plan_id INT,
    block_id INT NOT NULL,
    harvest_date DATE NOT NULL,
    actual_quantity_kg DECIMAL(12,2) NOT NULL,
    actual_bunches INT NOT NULL,
    loose_fruits_kg DECIMAL(10,2) DEFAULT 0,
    average_bunch_weight DECIMAL(10,2),
    harvesting_round ENUM('Round 1', 'Round 2', 'Round 3', 'Round 4') DEFAULT 'Round 1',
    harvester_count INT,
    supervisor VARCHAR(100),
    quality_grade ENUM('Premium', 'Grade A', 'Grade B', 'Grade C', 'Reject') DEFAULT 'Grade A',
    ripeness_level ENUM('Under Ripe', 'Ripe', 'Over Ripe') DEFAULT 'Ripe',
    status ENUM('Harvested', 'In Transit', 'Delivered', 'Rejected') DEFAULT 'Harvested',
    created_by VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (block_id) REFERENCES blocks(block_id),
    INDEX idx_harvest_date (harvest_date),
    INDEX idx_block (block_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Verification

After executing any of the above solutions, verify the table exists:

1. In phpMyAdmin, select the `plantation` database
2. Look for `harvest_realizations` in the table list
3. Or run this SQL query:
   ```sql
   SHOW TABLES LIKE 'harvest_realizations';
   ```

## Testing

Once the table is created, test the analytics page:

1. Open your browser
2. Navigate to: `http://localhost/agro/analytics.php`
3. The page should load without errors

## Related Tables Created

The complete solution creates these related tables:
- `harvest_plans` - Planning for harvest activities
- `harvest_realizations` - Actual harvest records (THE MAIN TABLE)
- `harvest_productivity` - Individual harvester performance
- `harvest_quality_control` - Quality inspection records

## Files Involved

- **Schema Definition**: `C:\xampp\htdocs\agro\database\harvesting_schema.sql`
- **Quick Fix SQL**: `C:\xampp\htdocs\agro\create_harvest_tables.sql`
- **PHP Fix Script**: `C:\xampp\htdocs\agro\fix_harvest_table.php`
- **Error Location**: `C:\xampp\htdocs\agro\analytics.php` (line 38)

## Prevention

To avoid this issue in the future:

1. Always run all schema files when setting up the database
2. Check the `database/` folder for all `.sql` files
3. Execute them in order:
   - Core schema files first
   - Module-specific schemas (like harvesting_schema.sql)
   - Sample data files last

## Need Help?

If you still encounter issues:

1. Check if XAMPP MySQL service is running
2. Verify database name is `plantation` in config/database.php
3. Ensure the `blocks` table exists (required foreign key)
4. Check MySQL error logs at: `C:\xampp\mysql\data\`

## Quick Checklist

- [ ] XAMPP MySQL is running
- [ ] Database `plantation` exists
- [ ] Table `blocks` exists (dependency)
- [ ] Executed create_harvest_tables.sql
- [ ] Verified harvest_realizations table exists
- [ ] Tested analytics.php page
- [ ] No errors displayed

---
**Created by Bob** - Agro Application Database Fix