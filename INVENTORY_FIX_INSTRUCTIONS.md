# Inventory Systems Fix Instructions

## Problem
All three inventory pages are missing database tables and views:
- `inventory_cpo.php` - Missing CPO storage tables
- `inventory_kernel.php` - Missing kernel storage tables
- `inventory_materials.php` - Missing materials tables

## Solution

### STEP 1: Run the Fix Script

Open your browser and navigate to:
```
http://localhost/agro/quick_fix_materials.php
```

This will create:
- ✓ `materials` table (with reorder_level column)
- ✓ `material_stock_transactions` table
- ✓ `material_transactions` view (alias)
- ✓ `vw_material_stock_summary` view
- ✓ Sample data (3 materials, 3 transactions)

### STEP 2: Verify Success

After running the script, you should see:
```
✓ Dropped old tables
✓ Created materials table
✓ Created material_stock_transactions table
✓ Inserted 3 materials
✓ Inserted 3 transactions
✓ Created vw_material_stock_summary
✓ Created material_transactions (alias)
✓✓✓ SUCCESS! 3 materials in database
You can now access inventory_materials.php
```

### STEP 3: Test the Page

Navigate to:
```
http://localhost/agro/inventory_materials.php
```

The page should now load without errors.

## For Other Inventory Systems

If you also need to fix CPO and Kernel systems:

### CPO System:
```
http://localhost/agro/fix_cpo_tables.php
```

### Kernel System:
```
http://localhost/agro/fix_kernel_tables.php
```

### All Systems at Once:
```
http://localhost/agro/fix_all_inventory_systems.php
```

## What Gets Created

### Materials System:
- **materials** table with columns:
  - material_id, material_code, material_name
  - category, unit
  - min_stock, max_stock, reorder_level
  - unit_price, default_warehouse_id
  - status, created_at, created_by

- **material_stock_transactions** table
- **material_transactions** view (alias for compatibility)
- **vw_material_stock_summary** view

### CPO System:
- storage_tanks, cpo_stock_transactions
- vw_tank_stock_summary, vw_tank_utilization_alerts, vw_stock_aging

### Kernel System:
- kernel_storage, kernel_stock_transactions
- vw_kernel_stock_summary, vw_kernel_storage_alerts, vw_kernel_stock_aging

## Troubleshooting

If you still get errors after running the script:

1. **Clear browser cache** and refresh
2. **Check database** - Run in phpMyAdmin:
   ```sql
   SHOW TABLES LIKE 'material%';
   ```
3. **Verify the script ran** - Check for success message
4. **Re-run the script** if needed (it's safe to run multiple times)

## Notes

- All scripts are safe to run multiple times
- They drop existing tables before creating new ones
- Sample data is included for immediate testing
- No manual SQL execution needed - just run the PHP scripts