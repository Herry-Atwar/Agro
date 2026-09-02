# Nursery Management Module - Installation Guide

## Overview
The Nursery Management module tracks seedling production from germination to distribution to planting blocks. It includes three main components:
1. **Nursery Stock** - Track seedling inventory and growth stages
2. **Production Plan** - Plan and monitor seedling production targets
3. **Distribution** - Manage seedling distribution to planting blocks

## Installation Steps

### 1. Create Database Tables

Run the SQL script to create the nursery tables:

```bash
# Navigate to your MySQL/MariaDB
mysql -u root -p plantation_master
```

Then execute:

```sql
source C:/xampp/htdocs/plantation_master/database/nursery_schema.sql
```

Or manually run the SQL from `plantation_master/database/nursery_schema.sql`

### 2. Verify Table Creation

Check that all tables were created:

```sql
SHOW TABLES LIKE 'nursery%';
```

You should see:
- `nursery_stock`
- `nursery_production_plan`
- `nursery_distribution`
- `nursery_maintenance`

### 3. Verify Sample Data

Check if sample data was inserted:

```sql
SELECT COUNT(*) FROM nursery_stock;
SELECT COUNT(*) FROM nursery_production_plan;
```

### 4. Access the Module

Open your browser and navigate to:

1. **Nursery Stock Management**
   ```
   http://localhost/plantation_master/nursery_stock.php
   ```

2. **Production Plan**
   ```
   http://localhost/plantation_master/nursery_production_plan.php
   ```

3. **Distribution**
   ```
   http://localhost/plantation_master/nursery_distribution.php
   ```

## Database Schema

### nursery_stock Table
Tracks seedling batches through growth stages:
- **stock_id** - Primary key
- **business_unit_id** - Links to nursery (business_units)
- **variety_id** - Plant variety
- **batch_number** - Unique batch identifier
- **seed_source** - Origin of seeds (e.g., PPKS)
- **germination_date** - When germination started
- **quantity_seeds** - Number of seeds
- **quantity_sprouts** - Number of sprouts
- **quantity_polybag** - Number in polybags
- **quantity_ready** - Number ready for planting
- **status** - Germination, Sprout, Polybag, Ready, Distributed

### nursery_production_plan Table
Plans seedling production by period:
- **plan_id** - Primary key
- **business_unit_id** - Nursery
- **variety_id** - Plant variety
- **plan_year** - Year of production
- **plan_month** - Month of production
- **target_quantity** - Target number of seedlings
- **actual_quantity** - Actual production
- **germination_date** - Planned germination date
- **expected_ready_date** - Expected ready date
- **status** - Planned, In Progress, Completed, Cancelled

### nursery_distribution Table
Tracks seedling distribution to blocks:
- **distribution_id** - Primary key
- **stock_id** - Links to nursery_stock
- **block_id** - Destination block
- **distribution_date** - Distribution date
- **quantity_distributed** - Number of seedlings
- **planting_date** - Actual planting date
- **receiver_name** - Person receiving seedlings
- **vehicle_number** - Transport vehicle
- **status** - Planned, In Transit, Delivered, Planted

### nursery_maintenance Table
Records maintenance activities:
- **maintenance_id** - Primary key
- **stock_id** - Links to nursery_stock
- **activity_date** - Date of activity
- **activity_type** - Watering, Fertilizing, Pest Control, etc.
- **description** - Activity details
- **quantity_affected** - Number of seedlings affected
- **materials_used** - Materials/chemicals used
- **labor_hours** - Labor time
- **cost** - Activity cost

## Features

### Nursery Stock Management
- ✅ Track seedling batches from germination to ready state
- ✅ Monitor quantities at each growth stage
- ✅ Filter by nursery, variety, and status
- ✅ Real-time inventory dashboard
- ✅ Batch number tracking

### Production Planning
- ✅ Plan seedling production by month and year
- ✅ Set target quantities per variety
- ✅ Track actual vs. target achievement
- ✅ Monitor production status
- ✅ Calculate achievement rates

### Distribution Management
- ✅ Record seedling distribution to blocks
- ✅ Track distribution status (Planned → In Transit → Delivered → Planted)
- ✅ Automatic stock quantity updates
- ✅ Receiver and vehicle tracking
- ✅ Distribution date filtering

## Business Flow

```
1. PRODUCTION PLANNING
   └─> Create production plan for period
   └─> Set target quantities by variety

2. STOCK MANAGEMENT
   └─> Register new batch (Germination)
   └─> Update quantities as seedlings grow
       ├─> Seeds → Sprouts
       ├─> Sprouts → Polybag
       └─> Polybag → Ready

3. DISTRIBUTION
   └─> Select ready stock batch
   └─> Assign to destination block
   └─> Track distribution status
   └─> Record planting completion
   └─> Stock automatically updated

4. MAINTENANCE (Future)
   └─> Record watering activities
   └─> Track fertilization
   └─> Log pest control
   └─> Monitor costs
```

## Prerequisites

Before using the Nursery module, ensure you have:

1. ✅ **Companies** - At least one company created
2. ✅ **Business Units** - Nursery units created (unit_type = 'Nursery')
3. ✅ **Plant Varieties** - Active plant varieties
4. ✅ **Blocks** - Destination blocks for distribution

## Usage Examples

### Example 1: Register New Seedling Batch

1. Go to Nursery Stock Management
2. Click "Add New Stock"
3. Fill in:
   - Nursery: Select nursery location
   - Variety: Select plant variety
   - Batch Number: e.g., "BATCH-2024-001"
   - Germination Date: Start date
   - Seed Source: e.g., "PPKS Marihat"
   - Quantity Seeds: e.g., 10000
   - Status: Germination
4. Click Save

### Example 2: Update Growth Progress

1. Find the batch in the list
2. Click Edit
3. Update quantities:
   - Quantity Sprouts: 8500 (85% germination)
   - Status: Sprout
4. Click Update

### Example 3: Distribute Seedlings

1. Go to Nursery Distribution
2. Click "Add Distribution"
3. Fill in:
   - Stock Batch: Select batch with Ready status
   - Destination Block: Select planting block
   - Distribution Date: Today
   - Quantity: e.g., 5000
   - Receiver Name: Field supervisor
   - Vehicle Number: Transport vehicle
   - Status: Planned
4. Click Save
5. Stock quantity_ready automatically reduced

### Example 4: Create Production Plan

1. Go to Nursery Production Plan
2. Click "Add Production Plan"
3. Fill in:
   - Nursery: Select nursery
   - Variety: Select variety
   - Plan Year: 2024
   - Plan Month: June
   - Target Quantity: 15000
   - Germination Date: 2024-06-01
   - Expected Ready Date: 2025-06-01 (12 months)
   - Status: Planned
4. Click Save

## Troubleshooting

### Issue: "Column not found" error
**Solution**: Run the nursery_schema.sql script to create tables

### Issue: No nurseries in dropdown
**Solution**: Create Business Units with unit_type = 'Nursery'

### Issue: No varieties available
**Solution**: Create plant varieties in Plant Varieties module

### Issue: Cannot distribute seedlings
**Solution**: Ensure stock status is 'Ready' and quantity_ready > 0

### Issue: Foreign key constraint error
**Solution**: Ensure referenced records exist (nurseries, varieties, blocks)

## Next Steps

After installing the Nursery module, you can:

1. ✅ Create production plans for the year
2. ✅ Register seedling batches as they arrive
3. ✅ Update growth progress regularly
4. ✅ Distribute seedlings to planting blocks
5. ✅ Track distribution status
6. ⏳ Implement maintenance tracking (future enhancement)
7. ⏳ Add cost tracking per batch (future enhancement)
8. ⏳ Generate nursery reports (future enhancement)

## Support

For issues or questions:
- Check the main README.md
- Review QUICK_START_GUIDE.md
- Verify database connections in config/database.php

## Module Status

✅ **Completed Features:**
- Nursery Stock Management
- Production Planning
- Distribution Tracking
- Real-time dashboards
- Search and filtering
- Status tracking

⏳ **Future Enhancements:**
- Maintenance activity tracking
- Cost analysis per batch
- Nursery performance reports
- Quality control tracking
- Seedling mortality tracking
- Automated alerts for distribution schedules