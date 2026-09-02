# Activity Budget System - Integration Guide

## Overview
This guide explains how to integrate the new Activity Budget System with your existing agro plantation management system.

## Files Created

### 1. Main Application Files
- **activity_budget_plans.php** - Budget planning interface
- **activity_budget_monthly.php** - Monthly tracking and actual cost entry
- **activity_budget_reports.php** - Comprehensive reporting
- **activity_norms_manage.php** - Manage productivity norms

### 2. Database Files (in database/ folder)
- **activity_norms_schema_fixed.sql** - Activity norms table structure
- **activity_budget_system.sql** - Main budget system (tables, procedures, views)
- **activity_budget_capital_extension.sql** - Capital budget support
- **activity_budget_sample_data.sql** - Test data and scenarios
- **README_BUDGET_SYSTEM.md** - Complete system documentation

## Integration Steps

### Step 1: Database Installation

Execute SQL files in this order:

```bash
# 1. Create activity_norms table (if not exists)
mysql -u root agro < database/activity_norms_schema_fixed.sql

# 2. Create budget system tables, procedures, and views
mysql -u root agro < database/activity_budget_system.sql

# 3. Add capital budget support
mysql -u root agro < database/activity_budget_capital_extension.sql

# 4. (Optional) Load sample data for testing
mysql -u root agro < database/activity_budget_sample_data.sql
```

**Alternative:** Use phpMyAdmin Import feature (not SQL tab) to avoid parser issues.

### Step 2: Add Navigation Menu

Update your existing **includes/header.php** to add Activity Budget menu items.

Find the navigation section and add:

```php
<!-- Add this in your navigation menu -->
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" id="budgetDropdown" role="button" 
       data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-calculator"></i> Activity Budget
    </a>
    <ul class="dropdown-menu" aria-labelledby="budgetDropdown">
        <li><a class="dropdown-item" href="activity_budget_plans.php">
            <i class="bi bi-clipboard-check"></i> Budget Plans
        </a></li>
        <li><a class="dropdown-item" href="activity_budget_monthly.php">
            <i class="bi bi-calendar-month"></i> Monthly Tracking
        </a></li>
        <li><a class="dropdown-item" href="activity_budget_reports.php">
            <i class="bi bi-graph-up"></i> Reports
        </a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="activity_norms_manage.php">
            <i class="bi bi-speedometer2"></i> Manage Norms
        </a></li>
    </ul>
</li>
```

### Step 3: Update Existing Budget Page (Optional)

If you want to link from your existing **budget.php** to the new activity budget system:

```php
<!-- Add this card/link in budget.php -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">Activity-Based Budget System</h5>
    </div>
    <div class="card-body">
        <p>Manage budgets based on activity norms and productivity standards.</p>
        <a href="activity_budget_plans.php" class="btn btn-primary">
            <i class="bi bi-arrow-right-circle"></i> Go to Activity Budget
        </a>
    </div>
</div>
```

### Step 4: Verify Database Connection

The new files use your existing database connection:

```php
require_once 'config/database.php';
$db = getDB();
```

Ensure your **config/database.php** has the `getDB()` helper function that returns a PDO instance.

### Step 5: Test the System

1. **Access Activity Norms Management**
   - Go to: `http://localhost/agro/activity_norms_manage.php`
   - Create productivity norms for your activities
   - Example: Harvesting on flat terrain = 0.5 man-days/ha

2. **Create Budget Plans**
   - Go to: `http://localhost/agro/activity_budget_plans.php`
   - Click "Create Budget Plan"
   - Select year, block, activity, and frequency
   - System automatically calculates budget based on norms

3. **Track Monthly Actuals**
   - Go to: `http://localhost/agro/activity_budget_monthly.php`
   - Select a budget plan
   - Enter actual man-days and costs
   - View budget vs actual variance

4. **Generate Reports**
   - Go to: `http://localhost/agro/activity_budget_reports.php`
   - Choose report type (Summary, Monthly, Variance, Classification)
   - Filter by year, block, activity group
   - Print or export reports

## Key Features

### 1. Automatic Budget Calculation
- Based on productivity norms (man-days per hectare)
- Considers terrain type and palm age
- Formula: `Budget = Area × Man-days/Ha × Daily Wage × Frequency`

### 2. Frequency-Based Planning
- Daily, Weekly, Bi-weekly
- Monthly, Bi-monthly, Quarterly
- Semi-annual, Annual
- Custom (specify exact times per year)

### 3. Monthly Distribution
- Automatically breaks down annual budget into 12 months
- Distributes based on activity frequency
- Example: Quarterly activity → Budget in months 1, 4, 7, 10

### 4. Budget vs Actual Tracking
- Enter actual man-days and costs monthly
- Automatic variance calculation
- Highlights over/under budget items (>10% variance)

### 5. Budget Classification
- **Operational**: Regular maintenance activities
- **Capital**: Infrastructure and equipment
- **TBM**: Immature plant costs (capitalized)

### 6. Comprehensive Reporting
- Budget summary by block and activity
- Monthly budget vs actual analysis
- Variance analysis (over/under budget)
- Classification breakdown

## Database Schema Overview

### Main Tables

1. **activity_norms**
   - Productivity standards (man-days per unit)
   - Varies by terrain type and palm age
   - Includes daily wage rates

2. **activity_budget_plans**
   - Annual budget plans
   - Links block, activity, and norm
   - Stores frequency and total calculations

3. **activity_budget_monthly**
   - Monthly budget distribution
   - Tracks actual costs
   - Calculates variances

### Stored Procedures

1. **sp_generate_activity_budget_plan**
   - Creates budget plan with automatic calculations
   - Selects appropriate norm based on block characteristics

2. **sp_generate_monthly_distribution**
   - Distributes annual budget across 12 months
   - Based on activity frequency

3. **sp_generate_budget_for_division**
   - Bulk budget generation for entire division
   - Creates plans for all blocks and activities

### Views

1. **v_activity_budget_summary**
   - Annual budget summary
   - Groups by block and activity

2. **v_monthly_budget_variance**
   - Monthly budget vs actual
   - Variance calculations

3. **v_budget_by_classification**
   - Budget breakdown by type
   - Operational, Capital, TBM

4. **v_activity_cost_analysis**
   - Detailed cost analysis
   - Man-days and cost per hectare

## Troubleshooting

### Issue: "Table already exists" error
**Solution:** The activity_norms table may already exist. Use `activity_norms_schema_fixed.sql` which skips if exists.

### Issue: Stored procedure errors
**Solution:** Drop existing procedures first:
```sql
DROP PROCEDURE IF EXISTS sp_generate_activity_budget_plan;
DROP PROCEDURE IF EXISTS sp_generate_monthly_distribution;
DROP PROCEDURE IF EXISTS sp_generate_budget_for_division;
```

### Issue: View creation fails
**Solution:** Ensure all tables exist first. Views depend on:
- activity_budget_plans
- activity_budget_monthly
- blocks
- activities
- activity_groups
- activity_norms

### Issue: Navigation menu not showing
**Solution:** Check Bootstrap 5 is loaded and dropdown JavaScript is working.

### Issue: No norms available when creating plans
**Solution:** Create activity norms first using the Manage Norms page.

## Data Flow

```
1. Create Activity Norms
   ↓
2. Create Budget Plans (auto-calculates using norms)
   ↓
3. Generate Monthly Distribution (auto-distributes)
   ↓
4. Enter Actual Costs Monthly
   ↓
5. View Reports & Variance Analysis
```

## Best Practices

1. **Create Norms First**
   - Set up productivity standards before creating plans
   - Update norms when wages or productivity changes

2. **Use Appropriate Frequencies**
   - Harvesting: Every 10 days (36 times/year)
   - Weeding: Bi-monthly (6 times/year)
   - Fertilizing: Quarterly (4 times/year)

3. **Regular Actual Entry**
   - Enter actual costs monthly
   - Add notes for significant variances
   - Review variance reports regularly

4. **Budget Classification**
   - Use "operational" for routine activities
   - Use "capital" for infrastructure
   - Use "tbm" for immature plant costs

5. **Review and Adjust**
   - Monitor variance reports
   - Adjust norms if consistently over/under budget
   - Update plans for next year based on actuals

## Support

For questions or issues:
1. Check README_BUDGET_SYSTEM.md for detailed documentation
2. Review sample data scenarios in activity_budget_sample_data.sql
3. Verify database structure matches schema files

## Next Steps

After integration:
1. Train users on the new system
2. Migrate historical budget data (if needed)
3. Set up regular reporting schedule
4. Establish norm review process
5. Integrate with payroll system (future enhancement)

---

**Version:** 1.0  
**Last Updated:** June 2026  
**System:** Agro Plantation Management - Activity Budget Module