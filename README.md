# erpAgro - Agrobusiness Solution

A comprehensive web-based system for managing palm oil plantation master data with a 5-level hierarchical structure.

## 🌴 Overview

The erpAgro - Agrobusiness Solution is designed to manage the complete organizational hierarchy of palm oil plantations, from company level down to individual planting blocks. Built with PHP and MariaDB/MySQL, it provides an intuitive interface for managing estates, mills, nurseries, and their associated data.

## 🏗️ System Architecture

### 5-Level Hierarchy

```
Level 1: Company (PT Perkebunan Nusantara)
    ↓
Level 2: Business Unit (Estate Riau, Mill Jambi, Nursery Sumatra)
    ↓
Level 3: Division (Afdeling A, Processing Unit 1)
    ↓
Level 4: Planting Year (2020, 2021, 2022)
    ↓
Level 5: Block (Block 01, Block 02)
```

### Why This Structure?

- **Company**: Top-level organization management
- **Business Unit**: Flexible for different operation types (Estate, Mill, Nursery, Workshop)
- **Division**: Operational sections within business units
- **Planting Year**: Groups blocks by planting year for better tracking and analysis
- **Block**: Individual planting blocks with detailed information

## ✨ Key Features

### Master Data Management
- ✅ **Companies**: Manage multiple companies with full details
- ✅ **Business Units**: Support for Estates, Mills, Nurseries, and other facilities
- ✅ **Divisions**: Organize business units into manageable sections
- ✅ **Planting Years**: Track planting cohorts by year
- ✅ **Blocks**: Detailed block information with GPS coordinates

### Data Tracking
- 📊 Automatic area calculations and aggregations
- 📅 Plant age tracking and status determination
- 🌱 Plant maturity status (TBM/TM/TR)
- 📈 Harvest readiness tracking
- 🗺️ GeoJSON support for mapping

### User Interface
- 🎨 Modern, responsive design (Bootstrap 5)
- 📱 Mobile-friendly interface
- 🔍 Advanced search and filtering
- 📤 Export to CSV functionality
- 📊 Dashboard with real-time statistics

### Data Integrity
- 🔗 Referential integrity with foreign keys
- 🔄 Automatic updates via triggers
- 📝 Audit trail (created_by, updated_by, timestamps)
- ⚡ Stored procedures for complex operations

## 🛠️ Technology Stack

- **Backend**: PHP 7.4+ (Pure PHP, no framework)
- **Database**: MariaDB 10.4+ / MySQL 5.7+
- **Frontend**: Bootstrap 5, Bootstrap Icons
- **Server**: Apache (XAMPP)
- **JavaScript**: jQuery (optional)

## 📋 System Requirements

- XAMPP (Apache + MariaDB/MySQL + PHP)
- PHP 7.4 or higher
- MariaDB 10.4+ or MySQL 5.7+
- Web Browser (Chrome, Firefox, Edge, Safari)
- Minimum 2GB RAM
- 500MB Free Disk Space

## 🚀 Quick Start

### 1. Install XAMPP
Download and install XAMPP from [apachefriends.org](https://www.apachefriends.org)

### 2. Copy Files
```bash
# Copy to XAMPP htdocs
C:\xampp\htdocs\plantation_master\
```

### 3. Import Schema to Existing Database
```sql
-- Using existing 'plantation' database
-- Import schema
source C:/xampp/htdocs/plantation_master/database/schema.sql;
```

### 4. Access Application
Open browser and navigate to:
```
http://localhost/plantation_master/
```

For detailed installation instructions, see [INSTALLATION.md](INSTALLATION.md)

## 📁 Project Structure

```
plantation_master/
├── config/
│   └── database.php          # Database configuration
├── database/
│   └── schema.sql            # Database schema with sample data
├── includes/
│   ├── header.php            # Common header template
│   ├── footer.php            # Common footer template
│   └── functions.php         # Helper functions
├── index.php                 # Dashboard
├── companies.php             # Companies management
├── business_units.php        # Business units management (to be created)
├── divisions.php             # Divisions management (to be created)
├── planting_years.php        # Planting years management (to be created)
├── blocks.php                # Blocks management (to be created)
├── plant_varieties.php       # Plant varieties management (to be created)
├── INSTALLATION.md           # Installation guide
└── README.md                 # This file
```

## 📊 Database Schema

### Main Tables

1. **companies** - Company information
2. **business_units** - Estates, Mills, Nurseries
3. **divisions** - Afdeling, Processing Units
4. **planting_years** - Planting year groups
5. **blocks** - Individual planting blocks
6. **plant_varieties** - Plant variety/clone information
7. **block_plant_varieties** - Link blocks to varieties

### Views

- `v_complete_hierarchy` - Complete 5-level hierarchy
- `v_business_unit_summary` - Business unit statistics
- `v_planting_year_summary` - Planting year statistics

### Stored Procedures

- `sp_update_block_ages()` - Update plant ages
- `sp_update_planting_year_areas()` - Update planting year areas
- `sp_update_division_areas()` - Update division areas
- `sp_update_business_unit_areas()` - Update business unit areas

## 🎯 Use Cases

### For Estate Managers
- Track all estates and their divisions
- Monitor planting progress by year
- View block-level details and status
- Generate area reports

### For Mill Managers
- Manage mill facilities
- Track processing capacity
- Monitor FFB sources

### For Plantation Directors
- Overview of all companies and business units
- Total area and plant statistics
- Maturity status (TBM vs TM)
- Strategic planning data

### For Agronomists
- Plant variety tracking
- Age-based analysis
- Replanting planning
- Yield forecasting data

## 📈 Sample Data Included

The system comes with pre-loaded sample data:

- **2 Companies**: PTPN, Astra Agro Lestari
- **4 Business Units**: 3 Estates, 1 Mill
- **7 Divisions**: Multiple Afdeling and Processing Units
- **7 Planting Years**: 2013-2020
- **11 Blocks**: Mix of TBM and TM status
- **4 Plant Varieties**: DxP Tenera, AVROS, Yangambi, Socfindo

## 🔐 Security Considerations

⚠️ **For Production Use:**

1. ✅ Implement user authentication
2. ✅ Add role-based access control
3. ✅ Change default MySQL password
4. ✅ Use prepared statements (already implemented)
5. ✅ Enable HTTPS
6. ✅ Implement CSRF protection
7. ✅ Regular security audits
8. ✅ Database backups

## 🐛 Troubleshooting

### Common Issues

**Database Connection Failed**
- Check MySQL is running in XAMPP
- Verify database credentials in `config/database.php`

**Table doesn't exist**
- Re-import `schema.sql`
- Check database name is correct

**Page not found**
- Verify folder location: `C:\xampp\htdocs\plantation_master\`
- Check Apache is running

For more troubleshooting, see [INSTALLATION.md](INSTALLATION.md)

## 📝 Development Status

### ✅ Completed
- [x] Database schema with 5-level hierarchy
- [x] Database connection and configuration
- [x] Helper functions and utilities
- [x] Header and footer templates
- [x] Dashboard with statistics
- [x] Companies management (CRUD)
- [x] Installation guide

### 🚧 In Progress
- [ ] Business Units management (CRUD)
- [ ] Divisions management (CRUD)
- [ ] Planting Years management (CRUD)
- [ ] Blocks management (CRUD)
- [ ] Plant Varieties management (CRUD)

### 📅 Planned
- [ ] User authentication system
- [ ] Role-based access control
- [ ] Advanced reporting module
- [ ] Data import/export (Excel)
- [ ] Map visualization (GeoJSON)
- [ ] Mobile app integration
- [ ] API endpoints (REST)

## 🤝 Contributing

This is a custom development project. For modifications or enhancements:

1. Test changes in development environment
2. Backup database before major changes
3. Document new features
4. Update this README

## 📄 License

Proprietary - Internal Use Only

## 👥 Support

For technical support or questions:
- Review documentation (README.md, INSTALLATION.md)
- Check troubleshooting section
- Contact system administrator

## 📚 Related Documentation

- [INSTALLATION.md](INSTALLATION.md) - Detailed installation guide
- [DATABASE_SCHEMA_DETAILED.md](../DATABASE_SCHEMA_DETAILED.md) - Complete database documentation
- [SAP_GROW_PLANTATION_PROJECT_PLAN.md](../SAP_GROW_PLANTATION_PROJECT_PLAN.md) - Project planning

## 🎉 Acknowledgments

- Bootstrap 5 for UI framework
- Bootstrap Icons for iconography
- XAMPP for development environment
- MariaDB/MySQL for database

---

**Version**: 1.0.0  
**Last Updated**: June 2026  
**Status**: Active Development

For the latest updates and full documentation, refer to the project repository.