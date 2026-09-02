# 🔧 Fix CPO Views - Remove DEFINER Error

## Problem
Error: `#1227 - Access denied; you need (at least one of) the SET USER privilege(s)`

This happens because the SQL exported from phpMyAdmin includes `DEFINER=root@localhost` which shared hosting doesn't allow.

---

## Solution: Use Fixed SQL Files

### Step 1: Import Tables First

Make sure these tables exist (import in this order):

1. **storage_tanks** table
2. **cpo_stock_transactions** table

### Step 2: Import Fixed View

Use the fixed SQL file: `vw_tank_stock_summary_fixed.sql`

**Via phpMyAdmin:**
1. Login to phpMyAdmin on your hosting
2. Select your database (e.g., `inodesain_plantation`)
3. Click **Import** tab
4. Choose file: `vw_tank_stock_summary_fixed.sql`
5. Click **Go**
6. Should succeed without errors ✅

---

## Alternative: Create View Manually

If you prefer to create the view manually:

### Step 1: Drop Existing View (if any)
```sql
DROP VIEW IF EXISTS vw_tank_stock_summary;
```

### Step 2: Create View Without DEFINER
```sql
CREATE VIEW vw_tank_stock_summary AS 
SELECT 
    t.tank_id, 
    t.tank_code, 
    t.tank_name, 
    t.tank_type, 
    t.capacity_kg, 
    t.location, 
    t.status, 
    COALESCE(SUM(
        CASE 
            WHEN st.transaction_type = 'in' THEN st.quantity_kg 
            WHEN st.transaction_type = 'out' THEN -st.quantity_kg 
            WHEN st.transaction_type = 'adjustment' THEN st.quantity_kg 
            WHEN st.transaction_type = 'transfer' THEN st.quantity_kg 
            ELSE 0 
        END
    ), 0) AS current_stock_kg, 
    ROUND(
        COALESCE(SUM(
            CASE 
                WHEN st.transaction_type = 'in' THEN st.quantity_kg 
                WHEN st.transaction_type = 'out' THEN -st.quantity_kg 
                WHEN st.transaction_type = 'adjustment' THEN st.quantity_kg 
                WHEN st.transaction_type = 'transfer' THEN st.quantity_kg 
                ELSE 0 
            END
        ), 0) / t.capacity_kg * 100, 
        2
    ) AS utilization_percentage, 
    COUNT(st.transaction_id) AS total_transactions, 
    MAX(st.transaction_date) AS last_transaction_date 
FROM 
    storage_tanks t 
    LEFT JOIN cpo_stock_transactions st ON t.tank_id = st.storage_tank_id 
GROUP BY 
    t.tank_id, 
    t.tank_code, 
    t.tank_name, 
    t.tank_type, 
    t.capacity_kg, 
    t.location, 
    t.status;
```

---

## Fix Other CPO Views

You'll need to fix these views too (same DEFINER issue):

### 1. vw_stock_aging
```sql
DROP VIEW IF EXISTS vw_stock_aging;

CREATE VIEW vw_stock_aging AS
SELECT 
    t.tank_id,
    t.tank_code,
    t.tank_name,
    st.transaction_id,
    st.transaction_date,
    st.quantity_kg,
    DATEDIFF(CURDATE(), st.transaction_date) AS days_in_storage,
    CASE 
        WHEN DATEDIFF(CURDATE(), st.transaction_date) <= 7 THEN 'Fresh (0-7 days)'
        WHEN DATEDIFF(CURDATE(), st.transaction_date) <= 14 THEN 'Good (8-14 days)'
        WHEN DATEDIFF(CURDATE(), st.transaction_date) <= 30 THEN 'Aging (15-30 days)'
        ELSE 'Old (>30 days)'
    END AS age_category
FROM 
    storage_tanks t
    INNER JOIN cpo_stock_transactions st ON t.tank_id = st.storage_tank_id
WHERE 
    st.transaction_type = 'in'
ORDER BY 
    st.transaction_date;
```

### 2. vw_tank_utilization_alerts
```sql
DROP VIEW IF EXISTS vw_tank_utilization_alerts;

CREATE VIEW vw_tank_utilization_alerts AS
SELECT 
    tank_id,
    tank_code,
    tank_name,
    capacity_kg,
    current_stock_kg,
    utilization_percentage,
    CASE 
        WHEN utilization_percentage >= 95 THEN 'CRITICAL - Nearly Full'
        WHEN utilization_percentage >= 90 THEN 'HIGH - Almost Full'
        WHEN utilization_percentage >= 75 THEN 'MEDIUM - Good Level'
        WHEN utilization_percentage >= 25 THEN 'NORMAL'
        WHEN utilization_percentage >= 10 THEN 'LOW - Need Refill'
        ELSE 'CRITICAL - Very Low'
    END AS alert_level,
    CASE 
        WHEN utilization_percentage >= 95 THEN 'Urgent: Tank almost full, plan dispatch'
        WHEN utilization_percentage >= 90 THEN 'Warning: High utilization'
        WHEN utilization_percentage <= 10 THEN 'Urgent: Very low stock'
        WHEN utilization_percentage <= 25 THEN 'Warning: Low stock level'
        ELSE 'Normal operation'
    END AS alert_message
FROM 
    vw_tank_stock_summary
WHERE 
    status = 'active';
```

---

## Quick Fix Script

Create a PHP file to fix all views at once:

**File: `fix_cpo_views_production.php`**

```php
<?php
require_once 'config/database.php';

$db = getDB();

echo "<h1>Fixing CPO Views for Production</h1>";

// Array of views to create
$views = [
    'vw_tank_stock_summary' => "CREATE VIEW vw_tank_stock_summary AS ...",
    'vw_stock_aging' => "CREATE VIEW vw_stock_aging AS ...",
    'vw_tank_utilization_alerts' => "CREATE VIEW vw_tank_utilization_alerts AS ..."
];

foreach ($views as $view_name => $sql) {
    try {
        // Drop if exists
        $db->exec("DROP VIEW IF EXISTS $view_name");
        echo "✓ Dropped $view_name<br>";
        
        // Create view
        $db->exec($sql);
        echo "✓ Created $view_name<br>";
    } catch (PDOException $e) {
        echo "✗ Error with $view_name: " . $e->getMessage() . "<br>";
    }
}

echo "<br>Done!";
?>
```

---

## Verification

After creating the views, verify they work:

```sql
-- Test vw_tank_stock_summary
SELECT * FROM vw_tank_stock_summary LIMIT 5;

-- Test vw_stock_aging
SELECT * FROM vw_stock_aging LIMIT 5;

-- Test vw_tank_utilization_alerts
SELECT * FROM vw_tank_utilization_alerts LIMIT 5;
```

---

## Common Issues

### Issue: "Table 'storage_tanks' doesn't exist"
**Solution:** Import storage_tanks table first

### Issue: "Table 'cpo_stock_transactions' doesn't exist"
**Solution:** Import cpo_stock_transactions table first

### Issue: "View already exists"
**Solution:** Drop the view first:
```sql
DROP VIEW IF EXISTS vw_tank_stock_summary;
```

---

## Summary

**The Problem:**
- phpMyAdmin exports include `DEFINER=root@localhost`
- Shared hosting doesn't allow this
- Results in error #1227

**The Solution:**
- Remove `DEFINER` clause from CREATE VIEW statements
- Use simple `CREATE VIEW` without security definer
- Import fixed SQL files

**Files to Use:**
- ✅ `vw_tank_stock_summary_fixed.sql` (already created)
- Create similar fixed versions for other views

**After fixing views, inventory_cpo.php should work!** ✅