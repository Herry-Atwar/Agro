# MILL OPERATIONS MODULE - INSTALLATION GUIDE
## SAP GROW Plantation Management System

## Overview
This guide provides step-by-step instructions for installing and implementing the Mill Operations Module.

## Prerequisites
- MySQL/MariaDB database server
- PHP 7.4 or higher
- Web server (Apache/Nginx)
- Existing Plantation Master Data system
- Harvesting Module (recommended for integration)

## Installation Steps

### Step 1: Database Installation

1. **Connect to your database:**
```bash
mysql -u root -p plantation_db
```

2. **Execute the mill operations schema:**
```bash
mysql -u root -p plantation_db < plantation_master/database/mill_operations_schema.sql
```

3. **Verify table creation:**
```sql
SHOW TABLES LIKE 'mill_%';
SHOW TABLES LIKE '%_production';
SHOW TABLES LIKE 'cpo_%';
SHOW TABLES LIKE 'kernel_%';
SHOW TABLES LIKE 'storage_%';
SHOW TABLES LIKE 'dispatch_%';
```

Expected tables:
- mill_master
- mill_processing_batch
- mill_sterilization
- mill_stripping
- mill_pressing
- mill_clarification
- mill_purification
- mill_kernel_recovery
- mill_daily_performance
- mill_downtime
- ffb_reception
- ffb_inspection
- ffb_grading
- cpo_production
- cpo_quality_test
- kernel_production
- kernel_quality_test
- storage_tanks
- storage_transactions
- dispatch_orders

### Step 2: Insert Master Data

1. **Create Mill Master Records:**
```sql
INSERT INTO mill_master (mill_code, mill_name, location, capacity_tph, status, company_id, division_id, created_by)
VALUES 
('MILL-001', 'Central Palm Oil Mill', 'Estate A, Block 1', 60.00, 'active', 1, 1, 'admin'),
('MILL-002', 'North Processing Plant', 'Estate B, Block 5', 45.00, 'active', 1, 2, 'admin');
```

2. **Create Storage Tanks:**
```sql
INSERT INTO storage_tanks (tank_no, tank_name, mill_id, product_type, capacity_kg, current_stock_kg, status)
VALUES 
('TANK-CPO-01', 'CPO Storage Tank 1', 1, 'CPO', 500000.00, 0, 'active'),
('TANK-CPO-02', 'CPO Storage Tank 2', 1, 'CPO', 500000.00, 0, 'active'),
('TANK-CPO-03', 'CPO Storage Tank 3', 2, 'CPO', 300000.00, 0, 'active');
```

### Step 3: PHP Module Implementation

The following PHP modules need to be created in the `plantation_master/` directory:

#### A. mill_processing.php
**Purpose:** Processing batch management
**Features:**
- Create/edit/delete processing batches
- Track batch status through all stages
- Monitor FFB input and processing progress
- Assign operators and supervisors

**Key Components:**
```php
// Auto-generate batch number: BATCH-YYYYMM-0001
// Status flow: pending → sterilizing → stripping → digesting → pressing → clarifying → completed
// Link to FFB reception records
// Calculate processing duration
```

#### B. mill_production.php
**Purpose:** Production output recording
**Features:**
- Record CPO production quantities
- Record Kernel production quantities
- Calculate OER (Oil Extraction Rate)
- Calculate KER (Kernel Extraction Rate)
- Assign to storage tanks

**Key Calculations:**
```php
OER % = (CPO Production / FFB Input) × 100  // Target: 20-24%
KER % = (Kernel Production / FFB Input) × 100  // Target: 4-6%
```

#### C. mill_quality.php
**Purpose:** Quality control and testing
**Features:**
- CPO quality testing (FFA, Moisture, DOBI, etc.)
- Kernel quality testing
- Pass/fail decisions
- Quality certification
- Reprocessing workflow

**Quality Parameters:**
```php
CPO:
- FFA %: Target < 5%
- Moisture %: Target < 0.15%
- Dirt %: Target < 0.02%
- DOBI: Target > 2.3

Kernel:
- Moisture %: Target < 7%
- Oil Content %: Target 48-52%
- Broken %: Target < 15%
```

#### D. mill_reception.php
**Purpose:** FFB reception and grading
**Features:**
- Weighbridge recording
- Visual inspection
- Quality grading
- Acceptance/rejection decisions
- Price deduction calculations

**Grading System:**
```php
Grades: Premium, Grade A, Grade B, Grade C, Reject
Criteria: Ripeness, Quality, Cleanliness
Scores: 0-100 for each criterion
```

#### E. mill_storage.php
**Purpose:** Storage and dispatch management
**Features:**
- Tank inventory tracking
- Storage transactions
- Dispatch order management
- Loading and delivery tracking

#### F. mill_performance.php
**Purpose:** Performance monitoring
**Features:**
- Daily performance metrics
- Mill utilization tracking
- Downtime analysis
- Resource consumption monitoring
- KPI dashboards

**Key KPIs:**
```php
- Mill Utilization %: Target > 85%
- OER %: Target 20-24%
- KER %: Target 4-6%
- Quality Compliance %: Target > 95%
- Downtime Hours: Target < 2 hours/day
```

### Step 4: Update Navigation Menu

The navigation menu in `includes/header.php` already includes Mill Operations section (lines 333-353):

```php
<!-- Mill Operations -->
<li class="nav-item mt-2">
    <h6 class="sidebar-heading px-3 text-muted">
        <span>MILL OPERATIONS</span>
    </h6>
</li>
<li class="nav-item">
    <a class="nav-link" href="mill_processing.php">
        <i class="bi bi-gear-wide-connected"></i> Processing
    </a>
</li>
<li class="nav-item">
    <a class="nav-link" href="mill_production.php">
        <i class="bi bi-bar-chart"></i> Production
    </a>
</li>
<li class="nav-item">
    <a class="nav-link" href="mill_quality.php">
        <i class="bi bi-award"></i> Quality Control
    </a>
</li>
```

**Add additional menu items:**
```php
<li class="nav-item">
    <a class="nav-link" href="mill_reception.php">
        <i class="bi bi-clipboard-check"></i> FFB Reception
    </a>
</li>
<li class="nav-item">
    <a class="nav-link" href="mill_storage.php">
        <i class="bi bi-box-seam"></i> Storage & Dispatch
    </a>
</li>
<li class="nav-item">
    <a class="nav-link" href="mill_performance.php">
        <i class="bi bi-speedometer2"></i> Performance
    </a>
</li>
```

### Step 5: Testing Workflow

#### Test 1: FFB Reception
1. Navigate to FFB Reception
2. Create new reception record
3. Enter weighbridge data (gross, tare)
4. Perform visual inspection
5. Grade the FFB (A, B, C, or Reject)
6. Accept or reject the batch

#### Test 2: Processing Batch
1. Navigate to Mill Processing
2. Create new processing batch
3. Link to accepted FFB reception records
4. Start processing (update status)
5. Record each processing stage:
   - Sterilization (temperature, pressure, duration)
   - Stripping (loose fruit output)
   - Pressing (oil extraction)
   - Clarification
   - Purification
   - Kernel recovery
6. Complete the batch

#### Test 3: Production Recording
1. Navigate to Mill Production
2. Record CPO production from completed batch
3. Record Kernel production
4. System calculates OER and KER
5. Assign to storage tanks
6. Verify calculations

#### Test 4: Quality Testing
1. Navigate to Quality Control
2. Create quality test for CPO production
3. Enter test parameters (FFA, Moisture, DOBI, etc.)
4. System determines pass/fail
5. Approve or mark for reprocessing
6. Generate quality certificate

#### Test 5: Storage & Dispatch
1. Navigate to Storage & Dispatch
2. View tank inventory
3. Create dispatch order
4. Record loading details
5. Update dispatch status
6. Verify stock deduction

#### Test 6: Performance Monitoring
1. Navigate to Performance
2. View daily performance metrics
3. Check OER and KER trends
4. Review downtime records
5. Analyze resource consumption
6. Generate performance reports

### Step 6: Integration Testing

#### With Harvesting Module:
1. Create harvest realization
2. Create FFB delivery
3. Link delivery to mill reception
4. Process through mill
5. Verify data flow

#### With Inventory Module:
1. Record production
2. Verify CPO stock increase
3. Verify Kernel stock increase
4. Create dispatch order
5. Verify stock decrease

### Step 7: User Training

**Training Topics:**
1. FFB Reception and Grading
2. Processing Batch Management
3. Production Recording
4. Quality Control Procedures
5. Storage and Dispatch Operations
6. Performance Monitoring
7. Report Generation

**Training Materials:**
- User manual (to be created)
- Video tutorials (to be created)
- Quick reference guides
- Standard Operating Procedures (SOPs)

### Step 8: Go-Live Checklist

- [ ] Database tables created and verified
- [ ] Master data populated (mills, tanks)
- [ ] PHP modules deployed
- [ ] Navigation menu updated
- [ ] All workflows tested
- [ ] Integration points verified
- [ ] User training completed
- [ ] Backup procedures in place
- [ ] Support team ready
- [ ] Go-live date scheduled

## Business Process Summary

### 1. FFB Reception Process
```
FFB Arrival → Weighing → Inspection → Grading → Accept/Reject → Unloading
```

### 2. Mill Processing Process
```
Sterilization → Stripping → Digesting → Pressing → Clarification → 
Purification → Drying → Kernel Recovery → Production Output
```

### 3. Quality Control Process
```
Sample Collection → Laboratory Testing → Analysis → Pass/Fail Decision → 
Quality Approval → Storage Assignment → Dispatch Preparation
```

### 4. Storage & Dispatch Process
```
Production → Tank Storage → Dispatch Order → Loading → Delivery → 
Stock Update
```

## Key Performance Indicators (KPIs)

### Production KPIs:
- **OER (Oil Extraction Rate):** 20-24%
- **KER (Kernel Extraction Rate):** 4-6%
- **Daily Processing Capacity:** Based on mill capacity (TPH)
- **Batch Completion Time:** Target 4-6 hours

### Quality KPIs:
- **CPO FFA:** < 5%
- **CPO Moisture:** < 0.15%
- **CPO DOBI:** > 2.3
- **Quality Compliance Rate:** > 95%

### Efficiency KPIs:
- **Mill Utilization:** > 85%
- **Downtime:** < 2 hours/day
- **Processing Efficiency:** > 90%
- **Resource Efficiency:** Optimized steam, power, water usage

## Troubleshooting

### Common Issues:

**Issue 1: Batch number not generating**
- Check database sequence
- Verify date format
- Review auto-increment logic

**Issue 2: OER/KER calculations incorrect**
- Verify FFB input quantity
- Check production quantities
- Review calculation formula

**Issue 3: Quality test failing**
- Review test parameters
- Check threshold values
- Verify data entry

**Issue 4: Storage tank overflow**
- Check tank capacity
- Review current stock
- Verify transaction calculations

## Support and Maintenance

### Regular Maintenance:
- Daily: Backup database
- Weekly: Review performance metrics
- Monthly: Analyze trends and efficiency
- Quarterly: System optimization

### Support Contacts:
- Technical Support: [Contact Info]
- Database Admin: [Contact Info]
- System Administrator: [Contact Info]

## Additional Resources

- **BPMN Diagram:** `bpmn/palm_oil_mill_processing.bpmn`
- **Database Schema:** `plantation_master/database/mill_operations_schema.sql`
- **Module Documentation:** `plantation_master/MILL_OPERATIONS_MODULE.md`
- **Project Plan:** `SAP_GROW_PLANTATION_PROJECT_PLAN.md`

## Version History

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 1.0 | 2026-06-02 | Initial release | Bob |

---

## Next Steps

After successful installation:
1. ✅ Database schema installed
2. ✅ Documentation completed
3. ⏳ PHP modules to be implemented
4. ⏳ User training to be conducted
5. ⏳ Go-live preparation
6. ⏳ Production deployment

---

*For questions or support, please refer to the project documentation or contact the development team.*

**Module Status:** READY FOR IMPLEMENTATION
**Installation Guide Version:** 1.0
**Last Updated:** 2026-06-02