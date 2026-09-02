# Cloud Deployment — Files to Upload
**Last updated: July 2026**
Upload destination: Hostinger → `public_html/agro/`

---

## ⚠️ Before You Start
1. Switch the database config in `config/database.php` — cloud credentials.
2. Upload files via FileZilla or Hostinger File Manager.

---

## PHP Pages Fixed (modal-outside-table + null guard)

All pages below had the same two problems:
- **View modals nested inside `<tbody>`** — invalid HTML, breaks grid rendering
- **`htmlspecialchars()` on nullable DB fields** — PHP 8.1 deprecation warning

| File | Fix applied |
|---|---|
| `maintenance.php` | Modal outside table, CSS override fixed |
| `work_orders.php` | Modal outside table, null guards |
| `nursery_production.php` | Modal outside table, correct table/column names (`nursery_maintenance`, `nursery_stocks`) |
| `harvest_plans.php` | Modal outside table, null guards on `harvesting_criteria`, `assigned_team`, `supervisor`, `notes` |
| `harvest_realizations.php` | Modal outside table, null guards |
| `ffb_delivery.php` | Modal outside table, null guards |

---

## Still Needs Fixing (same modal problem)

| File | Status |
|---|---|
| `fertilization.php` | ⏳ Not yet fixed |
| `harvest_productivity.php` | ⏳ Not yet fixed |
| `harvest_quality.php` | ⏳ Not yet fixed |
| `mill_processing.php` | ⏳ Not yet fixed |
| `mill_production.php` | ⏳ Not yet fixed |
| `mill_quality.php` | ⏳ Not yet fixed |
| `pest_control.php` | ⏳ Not yet fixed |

---

## Quick Upload Checklist

### Upload these files
- [ ] `maintenance.php`
- [ ] `work_orders.php`
- [ ] `nursery_production.php`
- [ ] `harvest_plans.php`
- [ ] `harvest_realizations.php`
- [ ] `ffb_delivery.php`

### Verify on cloud after upload
- [ ] Nursery Production — grid shows rows, view modal opens
- [ ] Harvest Plans — grid shows rows, edit form opens without deprecation warning
- [ ] Harvest Realizations — grid shows rows, view modal opens
- [ ] FFB Delivery — grid shows rows, view modal opens
