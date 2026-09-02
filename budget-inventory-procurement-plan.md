# Budget ↔ Inventory & Procurement Integration Plan

## Top-Level Overview

Extend the existing Agro ERP with a full procurement pipeline that is rooted in
the monthly activity budget and closes the loop back to budget actuals when
materials arrive and are consumed.

**Confirmed Design Decisions:**
- No approval workflow — status tracking only
- Budget warns but never blocks plan creation
- Work Orders module replaced by Daily Activity Plan
- GRN auto-creates stock-in AND updates budget actuals in one step
- Warehouse officer splits requirements into issue-from-stock vs purchase
- One Daily Activity Plan can cover multiple blocks within a division
- Material Requirements, PR, and PO all grouped under FINANCIAL > PROCUREMENT
- `activity_norm_materials` populated with sample data for immediate testing

**Flow:**
```
Activity Budget Monthly (planned cost/month)
    ↓ budget warning on save
Daily Activity Plan  [NEW]  — replaces Work Orders
    ↓ auto-generate via Activity Norms
Material & Service Requirements  [NEW]
    ↓ warehouse officer splits: issue from stock vs raise PR
Material Issue (stock out)       Purchase Requisition  [NEW]
    ↓                                  ↓ (no approval, direct)
material_transactions OUT         Purchase Order  [NEW]
    ↓                                  ↓ GRN / receive
    └─────────────────────────────────►┘
                    ↓
         activity_budget_monthly.actual_cost  (auto-update)
```

**Scope:**
- 5 new PHP modules + their database schemas + sidebar menu entries
- Work Orders module is superseded by Daily Activity Plan (kept in DB for history,
  hidden from menu)
- No approval workflow — status-only tracking
- All pages follow the `includes/header.php` pattern (collapsible sidebar,
  auth-gated, `$pdo` connection)

**Not in scope:**
- Supplier master management (supplier stored as free-text on PO, same pattern
  as current `materials.supplier`)
- Multi-currency purchasing
- GRN partial receipts (one GRN closes one PO line fully)

---

## Sub-Tasks

---

### Sub-Task 1 — Database Schema: Daily Activity Plan & Material Requirements

**Intent**
Create the tables that back the two new planning modules. Must link cleanly to
existing tables: `activities`, `activity_budget_monthly`, `activity_norms`,
`blocks`, `divisions`, `workers` (as supervisor).

**Expected Outcomes**
- `daily_activity_plans` table exists with correct FK references
- `daily_activity_plan_items` table exists (one row per activity within a day plan)
- `material_requirements` table exists (generated from plan items via norms)
- All tables idempotent (`CREATE TABLE IF NOT EXISTS`)
- Schema file at `agro/database/daily_activity_plan_schema.sql`

**Todo List**
1. Create `daily_activity_plans` table:
   - `plan_id` BIGINT PK AUTO_INCREMENT
   - `plan_number` VARCHAR(50) UNIQUE — format `DAP-YYYYMMDD-NNNN`
   - `plan_date` DATE NOT NULL
   - `division_id` BIGINT UNSIGNED NOT NULL → FK `divisions.division_id`
   - `block_id` INT NULL → FK `blocks.block_id` (optional, plan can cover whole division)
   - `supervisor` VARCHAR(100) NOT NULL
   - `status` ENUM('draft','submitted','completed','cancelled') DEFAULT 'draft'
   - `notes` TEXT
   - `budget_year` YEAR, `budget_month` TINYINT — denormalised for fast budget lookup
   - audit columns: `created_at`, `updated_at`, `created_by`, `updated_by`

2. Create `daily_activity_plan_items` table:
   - `item_id` BIGINT PK AUTO_INCREMENT
   - `plan_id` BIGINT NOT NULL → FK `daily_activity_plans`
   - `activity_id` BIGINT UNSIGNED NOT NULL → FK `activities.id`
   - `norm_id` BIGINT UNSIGNED NULL → FK `activity_norms.id` (the norm used)
   - `planned_area` DECIMAL(10,2) — hectares to cover
   - `planned_quantity` DECIMAL(10,2) — volume/units (e.g. tons for harvesting)
   - `planned_workers` INT — number of workers
   - `planned_man_days` DECIMAL(10,4) GENERATED from norm * area/qty
   - `estimated_cost` DECIMAL(15,2)
   - `budget_plan_id` BIGINT UNSIGNED NULL → FK `activity_budget_plans.plan_id`
   - `budget_month_id` BIGINT UNSIGNED NULL → FK `activity_budget_monthly.monthly_id`
   - `budget_remaining` DECIMAL(15,2) NULL — snapshot of remaining budget at save time (for display/warning)
   - `status` ENUM('planned','in_progress','completed','cancelled') DEFAULT 'planned'
   - `actual_man_days` DECIMAL(10,4), `actual_cost` DECIMAL(15,2)
   - audit columns

3. Create `material_requirements` table:
   - `req_id` BIGINT PK AUTO_INCREMENT
   - `req_number` VARCHAR(50) UNIQUE — format `MR-YYYYMMDD-NNNN`
   - `plan_item_id` BIGINT NOT NULL → FK `daily_activity_plan_items.item_id`
   - `plan_id` BIGINT NOT NULL → FK `daily_activity_plans.plan_id` (denorm for joins)
   - `material_id` INT NOT NULL → FK `materials.material_id`
   - `required_qty` DECIMAL(12,2) NOT NULL — calculated from norm
   - `current_stock` DECIMAL(12,2) NULL — snapshot at generation time
   - `issue_qty` DECIMAL(12,2) DEFAULT 0 — portion to issue from stock
   - `purchase_qty` DECIMAL(12,2) DEFAULT 0 — portion to procure
   - `warehouse_id` INT NULL → FK `material_warehouses.warehouse_id`
   - `status` ENUM('pending','partially_fulfilled','fulfilled','cancelled') DEFAULT 'pending'
   - `pr_id` BIGINT NULL → FK `purchase_requisitions.pr_id` (added in sub-task 3)
   - audit columns

4. Create view `vw_daily_plan_budget_check` joining `daily_activity_plan_items`
   → `activity_budget_monthly` showing planned vs remaining for the month.

5. Write schema file to `agro/database/daily_activity_plan_schema.sql`

**Relevant Context**
- `agro/database/activity_budget_system.sql` — pattern for `activity_budget_plans`
  and `activity_budget_monthly` table structure
- `agro/database/activity_norms_schema.sql` — `activity_norms.id`,
  `man_days_per_unit`, `unit_of_measure`
- `agro/database/materials_stock_schema.sql` — `materials.material_id`,
  `material_transactions`
- `divisions.division_id` is BIGINT UNSIGNED (check FK type carefully —
  `companies.company_id` is also BIGINT UNSIGNED; `blocks.block_id` is INT)

**Status:** `[x] done`

---

### Sub-Task 2 — Database Schema: Purchase Requisition & Purchase Order

**Intent**
Create the procurement tables. PR groups material requirements; PO is raised
from a PR. GRN receipt on PO auto-creates `material_transactions IN` and
updates `activity_budget_monthly.actual_cost`.

**Expected Outcomes**
- `purchase_requisitions` table exists
- `pr_items` table exists
- `purchase_orders` table exists
- `po_items` table exists
- GRN trigger or PHP logic defined for auto stock-in + budget actual update
- Schema file at `agro/database/procurement_schema.sql`

**Todo List**
1. Create `purchase_requisitions` table:
   - `pr_id` BIGINT PK AUTO_INCREMENT
   - `pr_number` VARCHAR(50) UNIQUE — format `PR-YYYYMM-NNNN`
   - `pr_date` DATE NOT NULL
   - `division_id` BIGINT UNSIGNED NOT NULL
   - `requested_by` VARCHAR(100)
   - `status` ENUM('draft','submitted','ordered','partially_received',
     'received','cancelled') DEFAULT 'draft'
   - `notes` TEXT
   - audit columns

2. Create `pr_items` table:
   - `pr_item_id` BIGINT PK AUTO_INCREMENT
   - `pr_id` BIGINT NOT NULL → FK `purchase_requisitions`
   - `material_req_id` BIGINT NULL → FK `material_requirements.req_id`
   - `material_id` INT NOT NULL → FK `materials`
   - `required_qty` DECIMAL(12,2), `approved_qty` DECIMAL(12,2)
   - `unit` VARCHAR(20), `estimated_unit_price` DECIMAL(12,2)
   - `estimated_total` DECIMAL(18,2) GENERATED
   - `status` ENUM('pending','ordered','received','cancelled') DEFAULT 'pending'

3. Create `purchase_orders` table:
   - `po_id` BIGINT PK AUTO_INCREMENT
   - `po_number` VARCHAR(50) UNIQUE — format `PO-YYYYMM-NNNN`
   - `po_date` DATE NOT NULL
   - `pr_id` BIGINT NOT NULL → FK `purchase_requisitions`
   - `supplier_name` VARCHAR(200) NOT NULL
   - `supplier_contact` VARCHAR(200)
   - `expected_delivery_date` DATE
   - `status` ENUM('draft','sent','partially_received','received','cancelled')
     DEFAULT 'draft'
   - `notes` TEXT
   - audit columns

4. Create `po_items` table:
   - `po_item_id` BIGINT PK AUTO_INCREMENT
   - `po_id` BIGINT NOT NULL → FK `purchase_orders`
   - `pr_item_id` BIGINT NOT NULL → FK `pr_items`
   - `material_id` INT NOT NULL → FK `materials`
   - `ordered_qty` DECIMAL(12,2), `received_qty` DECIMAL(12,2) DEFAULT 0
   - `unit_price` DECIMAL(12,2) NOT NULL
   - `total_price` DECIMAL(18,2) GENERATED
   - `warehouse_id` INT NOT NULL → FK `material_warehouses`
   - `received_date` DATE NULL
   - `status` ENUM('pending','partial','received','cancelled') DEFAULT 'pending'

5. Define GRN logic (implemented in PHP, not a DB trigger):
   - On marking a `po_item` as received: INSERT into `material_transactions`
     (type='in', material_id, warehouse_id, quantity=received_qty,
     unit_price, reference_no=po_number)
   - Trace back via `pr_item_id → material_req_id → plan_item_id →
     budget_month_id` and UPDATE `activity_budget_monthly.actual_cost +=
     total_price`

6. Write schema file to `agro/database/procurement_schema.sql`

**Relevant Context**
- `agro/database/materials_stock_schema.sql` — `material_transactions` INSERT
  pattern; `material_warehouses.warehouse_id` (INT)
- `agro/database/delivery_receiving_schema.sql` — pattern for status ENUMs and
  auto-number generation (PREFIX-YYYYMM-NNNN via MAX+1 subquery)
- `activity_budget_monthly.actual_cost` is a plain DECIMAL column (not generated)
  — safe to UPDATE directly

**Status:** `[x] done`

---

### Sub-Task 3 — Module: Daily Activity Plan (`daily_activity_plan.php`)

**Intent**
Build the main planning screen where supervisors create day plans per
division/block, select activities, specify area/quantity, and see a live budget
warning. On save, material requirements are auto-generated from activity norms.

**Expected Outcomes**
- `agro/daily_activity_plan.php` functional with full CRUD
- On save: `material_requirements` rows auto-generated for each plan item
- Budget remaining shown inline per activity (warn if negative, never block)
- Sidebar entry added under FIELD OPERATIONS section in `includes/header.php`
- Plan header covers a division; each plan item specifies its own block

**Todo List**
1. POST handlers (before header):
   - `create_plan` — insert `daily_activity_plans` + items; for each item, look
     up matching `activity_norms` (terrain + palm_age filter same as stored proc
     `sp_generate_activity_budget_plan`) and INSERT `material_requirements`
   - `update_plan` — update header + items (delete+reinsert items pattern)
   - `delete_plan` — soft delete (status='cancelled')
   - `complete_plan` — set status='completed', record actual_man_days/actual_cost

2. Reference data queries: companies, divisions, blocks, activities
   (with their norm count), supervisors from workers table

3. Page display:
   - Filter bar: year, month, division, status
   - Card list of plans with status badges
   - Add/Edit modal: plan date, division (header level); activity rows
     each with: block (cascade from division), activity, workers, area/qty
     (add/remove rows dynamically with JS)
   - Inline budget widget per activity row: show `planned_cost`,
     `actual_cost`, `remaining` from `activity_budget_monthly`
   - If remaining < estimated_cost: show orange warning badge "Over budget"

4. Auto-generate material requirements:
   - For each plan_item, query `activity_norms` to find materials needed
     (NOTE: current `activity_norms` table tracks man_days only — see
     Context note below about norm-material link table needed)
   - INSERT `material_requirements` rows with `current_stock` snapshot from
     `vw_material_stock_summary`

5. Add sidebar link in `includes/header.php` under FIELD OPERATIONS:
   `daily_activity_plan.php` — icon `bi-calendar2-check`
   Remove Work Orders link (or comment it out)

**Relevant Context**
- `agro/work_orders.php` — existing page to understand current field activity
  UI pattern; replaces this module
- `agro/activity_budget_monthly.php` — how budget month data is displayed
- `agro/includes/header.php` lines 549-577 — FIELD OPERATIONS section
- **Important:** Current `activity_norms` table only has `man_days_per_unit` —
  no material quantities. Sub-Task 3 will need an
  `activity_norm_materials` junction table (activity_norm_id → material_id →
  qty_per_unit) added to the schema in Sub-Task 1.
  Add this to Sub-Task 1 before implementing Sub-Task 3.

**Status:** `[x] done`

---

### Sub-Task 4 — Module: Material Requirements (`material_requirements.php`)

**Intent**
Warehouse officer screen: view all auto-generated material requirements, see
current stock per item, and split each requirement into "issue from stock"
vs "raise PR" quantities. Issuing from stock creates a `material_transactions`
OUT record immediately.

**Expected Outcomes**
- `agro/material_requirements.php` functional
- Issue-from-stock action creates `material_transactions` OUT entry linked to
  plan via `block_id` and `reference_no = plan_number`
- PR portion saved back to `material_requirements.purchase_qty`
- Sidebar entry added under FINANCIAL > PROCUREMENT in `includes/header.php`

**Todo List**
1. POST handlers:
   - `split_requirement` — update `issue_qty` + `purchase_qty` on a requirement;
     if issue_qty > 0: INSERT `material_transactions`
     (type='out', quantity=issue_qty, purpose=plan activity name,
     block_id from plan, reference_no=plan_number)
   - `create_pr_from_requirements` — batch-create a `purchase_requisitions`
     record + `pr_items` from all requirements with purchase_qty > 0 for a
     given plan; update `material_requirements.pr_id`

2. Page display:
   - Filter: by plan date, division, status
   - Grouped by Daily Activity Plan (plan_number, plan_date, division)
   - Per requirement row: material name, required_qty, current_stock,
     stock status badge (LOW/CRITICAL/OUT), issue_qty input, purchase_qty input
     (auto-calculated: purchase_qty = required - issue), unit, warehouse
   - Submit button per plan group: "Issue from stock & raise PR"
   - Already-processed rows shown as read-only with status badge

3. Sidebar entry in `includes/header.php` under FINANCIAL > PROCUREMENT:
   icon `bi-boxes`

**Relevant Context**
- `agro/inventory_materials.php` — how `material_transactions` are inserted and
  `vw_material_stock_summary` is queried
- `agro/database/materials_stock_schema.sql` — `material_transactions` columns:
  transaction_type, material_id, warehouse_id, quantity, unit_price,
  reference_no, purpose, block_id

**Status:** `[x] done`

---

### Sub-Task 5 — Module: Purchase Requisition (`purchase_requisitions.php`)

**Intent**
Procurement team screen to view and manage PRs generated from material
requirements. PRs can be converted directly to POs (no approval step).

**Expected Outcomes**
- `agro/purchase_requisitions.php` functional
- "Create PO from PR" action creates a `purchase_orders` + `po_items` records
- Sidebar entry added under FINANCIAL > Sales sub-group... actually under a
  new PROCUREMENT sub-group inside FINANCIAL in `includes/header.php`

**Todo List**
1. POST handlers:
   - `submit_pr` — set PR status = 'submitted'
   - `cancel_pr` — set status = 'cancelled'
   - `create_po` — INSERT `purchase_orders` + `po_items` from all non-cancelled
     pr_items; set PR status = 'ordered'

2. Page display:
   - Filter: year, month, division, status
   - PR list with: pr_number, pr_date, division, requested_by, item count,
     estimated total, status badge
   - PR detail view (expand/modal): list of pr_items with material, qty, price
   - "Create PO" button on submitted PRs — prompts for supplier_name,
     expected_delivery_date before creating PO

3. Add PROCUREMENT sub-group to FINANCIAL section in `includes/header.php`
   (same pattern as Budget and Sales sub-groups: collapsible with
   `toggleSection('subProcurement')`)
   Links: Material Requirements, Purchase Requisitions, Purchase Orders

**Relevant Context**
- `agro/includes/header.php` lines 684-830 — FINANCIAL section with existing
  Budget and Sales sub-groups as pattern to follow
- `agro/sales_contracts.php` — similar list+detail pattern to follow

**Status:** `[x] done`

---

### Sub-Task 6 — Module: Purchase Order & GRN (`purchase_orders.php`)

**Intent**
Final procurement screen. Procurement officer records receipt of goods (GRN).
On receive: stock-in transaction created automatically, budget actuals updated.

**Expected Outcomes**
- `agro/purchase_orders.php` functional
- Receiving a PO item: creates `material_transactions IN` + updates
  `activity_budget_monthly.actual_cost`
- PO status auto-updates to 'received' when all items received
- Sidebar entry in PROCUREMENT sub-group

**Todo List**
1. POST handlers:
   - `receive_po_item` — for a single po_item:
     a. UPDATE `po_items` SET received_qty, received_date, status='received'
     b. INSERT `material_transactions` (type='in', material_id, warehouse_id,
        quantity=received_qty, unit_price, reference_no=po_number,
        purpose='Purchase Order ' + po_number)
     c. Trace budget path:
        po_item → pr_item → material_req → plan_item → budget_month_id
        UPDATE `activity_budget_monthly`
        SET actual_cost = actual_cost + (received_qty * unit_price)
        WHERE monthly_id = plan_item.budget_month_id
     d. Check if all po_items received → UPDATE `purchase_orders` status='received'
        and `purchase_requisitions` status='received'
   - `cancel_po` — set PO + items status='cancelled'; revert PR to 'submitted'

2. Page display:
   - Filter: year, month, status
   - PO list: po_number, po_date, supplier, expected_delivery, item count,
     total value, status badge
   - PO detail (expand/modal): po_items with material, ordered_qty,
     received_qty input, unit_price, warehouse, received_date
   - "Receive Items" button per PO — opens receive modal with qty inputs
   - After receive: show updated stock level and budget actual change

3. Sidebar entry under PROCUREMENT sub-group in `includes/header.php`

**Relevant Context**
- `agro/delivery_receiving.php` — same GRN receive pattern to follow
- `agro/database/materials_stock_schema.sql` — `material_transactions` INSERT
- `activity_budget_monthly.actual_cost` — plain DECIMAL, safe to increment with
  `SET actual_cost = actual_cost + ?`
- Budget path trace: `po_items.pr_item_id` → `pr_items.material_req_id` →
  `material_requirements.plan_item_id` → `daily_activity_plan_items.budget_month_id`

**Status:** `[x] done`

---

## Schema Addition Note (for Sub-Task 1)

The current `activity_norms` table only defines **labour norms** (man_days_per_unit).
To auto-generate material requirements, an `activity_norm_materials` junction
table is needed:

```
activity_norm_materials
  norm_material_id  BIGINT PK
  norm_id           BIGINT UNSIGNED  FK → activity_norms.id
  material_id       INT              FK → materials.material_id
  qty_per_unit      DECIMAL(12,4)   -- e.g. 2.5 kg/hectare fertilizer
  unit_of_measure   VARCHAR(20)     -- same UOM as norm (hectare/ton/palm)
  notes             VARCHAR(255)
```

This table must be created in Sub-Task 1 and populated with sample data before
Sub-Task 3 can be tested end-to-end.

---

## Files To Create

| File | Sub-Task |
|------|----------|
| `agro/database/daily_activity_plan_schema.sql` | 1 |
| `agro/database/procurement_schema.sql` | 2 |
| `agro/daily_activity_plan.php` | 3 |
| `agro/material_requirements.php` | 4 |
| `agro/purchase_requisitions.php` | 5 |
| `agro/purchase_orders.php` | 6 |

## Files To Modify

| File | Sub-Task | Change |
|------|----------|--------|
| `agro/includes/header.php` | 3 | Add DAP + Material Req links under FIELD OPERATIONS; hide Work Orders |
| `agro/includes/header.php` | 5 | Add PROCUREMENT sub-group under FINANCIAL |
| `agro/header.php` | 3,5 | Same sidebar changes for the legacy header |
