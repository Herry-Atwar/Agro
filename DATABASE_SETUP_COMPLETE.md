# Complete Database Setup Guide for Agro Application

## Overview
The Agro application has multiple modules, each requiring specific database tables. This guide provides a complete setup process.

## Problem
You're encountering "Table not found" errors because the database schema files haven't been executed yet.

## Complete Solution: Execute ALL Schema Files

### Step 1: List of ALL Schema Files to Execute (in order)

Execute these SQL files in phpMyAdmin in this exact order:

#### Core/Foundation Tables (Execute First)
1. `database/core_schema.sql` - Companies, divisions, blocks (if exists)
2. `database/planting_years_schema.sql` - Planting year data (if exists)

#### Harvest Module
3. `database/harvesting_schema.sql` - Harvest plans, realizations, productivity, quality

#### Mill Operations Module
4. `database/mill_operations_schema.sql` - Mill master, processing batches, all mill processes
5. `database/mill_production_schema.sql` - Mill production records
6. `database/mill_quality_schema.sql` - Quality control and standards

#### Financial/Analytics Module
7. `database/block_costing_schema.sql` - Cost tracking
8. `database/sales_schema.sql` - Sales and customers
9. `database/activity_budget_system.sql` - Budget planning

#### Stock Management
10. `database/cpo_stock_schema.sql` - CPO stock management (if exists)
11. `database/ffb_delivery_schema.sql` - FFB delivery tracking (if exists)

### Step 2: Quick Setup Using Our Consolidated File

**Alternatively**, use our pre-made file that includes the most critical tables:

**File:** `create_all_analytics_tables.sql`

**Includes:**
- harvest_plans
- harvest_realizations  
- harvest_productivity
- harvest_quality_control
- block_costs
- sales
- customers
- budgets

### Step 3: Execute in phpMyAdmin

**For Each Schema File:**
1. Open phpMyAdmin: http://localhost/phpmyadmin
2. Select `plantation` database
3. Click "SQL" tab
4. Open the schema file in a text editor
5. Copy ALL content
6. Paste into SQL query box
7. Click "Go"
8. Verify success message

**Important Notes:**
- Some files may show "table already exists" warnings - this is OK
- Foreign key errors? Use files without FK constraints
- Execute files in the order listed above

### Step 4: Verify Tables Created

Run this query in phpMyAdmin to see all tables:

```sql
SHOW TABLES;
```

You should see tables like:
- harvest_plans
- harvest_realizations
- harvest_productivity
- mill_production
- mill_quality_control
- block_costs
- sales
- budgets
- customers
- etc.

### Step 5: Test Your Application

After executing all schema files, test these pages:
- http://localhost/agro/analytics.php
- http://localhost/agro/harvest_plans.php
- http://localhost/agro/harvest_realizations.php
- http://localhost/agro/harvest_productivity.php
- http://localhost/agro/mill_quality.php
- http://localhost/agro/mill_production.php

## Quick Fix for Specific Errors

### Error: "Table 'plantation.harvest_realizations' doesn't exist"
**Solution:** Execute `database/harvesting_schema.sql`

### Error: "Table 'plantation.mill_production' doesn't exist"
**Solution:** Execute `database/mill_production_schema.sql`

### Error: "Table 'plantation.block_costs' doesn't exist"
**Solution:** Execute `database/block_costing_schema.sql`

### Error: "Table 'plantation.sales' doesn't exist"
**Solution:** Execute `database/sales_schema.sql`

### Error: "Table 'plantation.budgets' doesn't exist"
**Solution:** Execute `database/activity_budget_system.sql`

## Automated Setup Script

Create this PHP file to auto-execute all schemas:

**File:** `setup_database.php`

```php
<?php
require_once 'config/database.php';

$schema_files = [
    'database/harvesting_schema.sql',
    'database/mill_operations_schema.sql',
    'database/mill_production_schema.sql',
    'database/mill_quality_schema.sql',
    'database/block_costing_schema.sql',
    'database/sales_schema.sql',
    'database/activity_budget_system.sql',
];

$db = getDB();

foreach ($schema_files as $file) {
    if (file_exists($file)) {
        echo "Executing: $file\n";
        $sql = file_get_contents($file);
        try {
            $db->exec($sql);
            echo "✓ Success\n\n";
        } catch (PDOException $e) {
            echo "✗ Error: " . $e->getMessage() . "\n\n";
        }
    } else {
        echo "✗ File not found: $file\n\n";
    }
}

echo "Database setup complete!";
?>
```

Then access: http://localhost/agro/setup_database.php

## Troubleshooting

### Foreign Key Constraint Errors
If you get FK errors, the tables are being created in wrong order or referenced tables don't exist.

**Solution:** Use our `create_all_analytics_tables.sql` which has NO foreign keys.

### "Column not found" Errors
The table exists but is missing columns.

**Solution:** Drop and recreate the table using the correct schema file.

### Permission Errors
**Solution:** Ensure MySQL user has CREATE, ALTER, DROP privileges.

## Best Practice

1. **Backup first:** Export your current database before making changes
2. **Test environment:** Set up on a test database first
3. **Execute in order:** Follow the sequence above
4. **Verify each step:** Check tables after each schema execution
5. **Keep schemas updated:** When adding new features, update schema files

## Summary

The Agro application is modular with many database tables. The key is to execute ALL relevant schema files from the `database/` directory. Our consolidated file `create_all_analytics_tables.sql` provides the core tables needed for analytics and harvest modules.

For a complete setup, execute all schema files in the order listed above.

---
**Created by Bob** - Complete Database Setup Guide