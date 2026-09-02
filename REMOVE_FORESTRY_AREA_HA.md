# Remove forestry_area_ha References

The column `forestry_area_ha` doesn't exist in the current database schema. This document lists all references that need to be removed or modified.

## Files Affected (4 files, 18 references)

### 1. blocks.php (8 references)
- Lines 23-24: UPDATE divisions SET forestry_area_ha
- Lines 66-67: UPDATE business_units SET forestry_area_ha  
- Line 108: UPDATE business_units SET forestry_area_ha
- Line 128: UPDATE companies SET forestry_area_ha
- Lines 296-297: UPDATE divisions SET forestry_area_ha
- Lines 540-541: UPDATE divisions SET forestry_area_ha
- Lines 580-581: UPDATE divisions SET forestry_area_ha
- Lines 698-699: UPDATE divisions SET forestry_area_ha

### 2. companies.php (5 references)
- Line 127: SELECT COALESCE(c.forestry_area_ha, 0)
- Line 129: COALESCE(c.forestry_area_ha, 0) as forestry_area_ha
- Line 177: ($company['forestry_area_ha'] ?? 0)
- Line 182: array_column($companies, 'forestry_area_ha')
- Line 284: ($company['forestry_area_ha'] ?? 0)

### 3. business_units.php (4 references)
- Line 137: COALESCE(bu.forestry_area_ha, 0)
- Line 139: COALESCE(bu.forestry_area_ha, 0) as forestry_area_ha
- Line 207: ($unit['forestry_area_ha'] ?? 0)
- Line 211: array_column($top_level_units, 'forestry_area_ha')
- Line 344: ($unit['forestry_area_ha'] ?? 0)

### 4. divisions.php (3 references)
- Line 126: COALESCE(d.forestry_area_ha, 0)
- Line 131: COALESCE(d.forestry_area_ha, 0) as forestry_area_ha
- Line 132: COALESCE(d.total_area_ha, 0) as plantation_area_ha

## Solution Options

### Option 1: Remove All References (Recommended)
Since forestry_area_ha doesn't exist, remove all calculations and displays.

**Changes needed:**
- Remove forestry_area_ha from all SELECT statements
- Remove forestry_area_ha from all UPDATE statements
- Remove forestry_area_ha from all array operations
- Update combined_total_area_ha to only use total_area_ha

### Option 2: Add Column to Database
If you need forestry area tracking, add the column:

```sql
ALTER TABLE companies ADD COLUMN forestry_area_ha DECIMAL(10,2) DEFAULT 0 AFTER total_area_ha;
ALTER TABLE business_units ADD COLUMN forestry_area_ha DECIMAL(10,2) DEFAULT 0 AFTER total_area_ha;
ALTER TABLE divisions ADD COLUMN forestry_area_ha DECIMAL(10,2) DEFAULT 0 AFTER total_area_ha;
```

## Recommended Action

**Remove all forestry_area_ha references** since:
1. The column doesn't exist in current schema
2. Agro-Laravel doesn't use this column
3. Forestry operations can use the existing `total_area_ha` column
4. The `operation_type` field already distinguishes Plantation vs Forestry

## Quick Fix Script

I'll create a script to automatically remove all references.