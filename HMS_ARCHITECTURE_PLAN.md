# Hospital Management System (HMS) - Architecture & Implementation Plan
## Sistem Manajemen Rumah Sakit - Rencana Arsitektur & Implementasi

**Project Name:** Indonesian Hospital Management System (HMS-ID)  
**Technology Stack:** PHP 8.x, MariaDB/MySQL, Bootstrap 5, jQuery  
**Target Environment:** XAMPP (Windows)  
**Language Support:** Bilingual (English/Bahasa Indonesia)

---

## 1. System Overview / Gambaran Sistem

### 1.1 Core Objectives
- Complete hospital operations management
- BPJS (Indonesian National Health Insurance) integration ready
- Indonesian medical terminology support
- Bilingual interface with language toggle
- Responsive design for desktop and mobile
- Role-based access control (RBAC)
- Comprehensive reporting and analytics

### 1.2 Target Users
1. **Super Admin** - System administrator
2. **Hospital Admin** - Hospital management
3. **Doctors** (Dokter) - Medical practitioners
4. **Nurses** (Perawat) - Nursing staff
5. **Pharmacists** (Apoteker) - Pharmacy staff
6. **Lab Technicians** (Analis Lab) - Laboratory staff
7. **Radiologists** (Radiografer) - Radiology staff
8. **Receptionists** (Resepsionis) - Front desk staff
9. **Billing Staff** (Kasir) - Finance/billing staff

---

## 2. System Architecture / Arsitektur Sistem

```
┌─────────────────────────────────────────────────────────────┐
│                    PRESENTATION LAYER                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │  Dashboard   │  │  Modules UI  │  │   Reports    │      │
│  │  (Bilingual) │  │  (Bilingual) │  │  (Bilingual) │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
                            ↕
┌─────────────────────────────────────────────────────────────┐
│                    APPLICATION LAYER                         │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  Authentication & Authorization (RBAC)               │   │
│  └──────────────────────────────────────────────────────┘   │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   Patient    │  │  Appointment │  │     EMR      │      │
│  │  Management  │  │  Scheduling  │  │   System     │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   Pharmacy   │  │  Laboratory  │  │  Radiology   │      │
│  │  Management  │  │  Management  │  │  Management  │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   Billing    │  │   Inpatient  │  │  Outpatient  │      │
│  │  & BPJS      │  │  Management  │  │  Management  │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │    Staff     │  │  Department  │  │  Inventory   │      │
│  │  Management  │  │  Management  │  │  Management  │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
└─────────────────────────────────────────────────────────────┘
                            ↕
┌─────────────────────────────────────────────────────────────┐
│                      DATA LAYER                              │
│                   MariaDB Database                           │
│  ┌──────────────────────────────────────────────────────┐   │
│  │  Normalized Relational Database Schema               │   │
│  │  - Users & Roles                                     │   │
│  │  - Patients & Medical Records                        │   │
│  │  - Appointments & Schedules                          │   │
│  │  - Pharmacy & Inventory                              │   │
│  │  - Laboratory & Radiology                            │   │
│  │  - Billing & BPJS                                    │   │
│  │  - Inpatient & Outpatient                            │   │
│  │  - Staff & Departments                               │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Directory Structure / Struktur Direktori

```
hospital/
├── config/
│   ├── database.php              # Database configuration
│   ├── settings.php              # System settings
│   └── language.php              # Language configuration
├── includes/
│   ├── header.php                # Common header
│   ├── footer.php                # Common footer
│   ├── sidebar.php               # Navigation sidebar
│   ├── functions.php             # Common functions
│   └── auth.php                  # Authentication functions
├── assets/
│   ├── css/
│   │   ├── bootstrap.min.css     # Bootstrap 5
│   │   ├── style.css             # Custom styles
│   │   └── indonesian-theme.css  # Indonesian hospital theme
│   ├── js/
│   │   ├── bootstrap.bundle.min.js
│   │   ├── jquery.min.js
│   │   ├── main.js               # Main JavaScript
│   │   └── language-toggle.js    # Language switching
│   ├── images/
│   │   └── logo.png              # Hospital logo
│   └── fonts/
├── lang/
│   ├── en.php                    # English translations
│   └── id.php                    # Indonesian translations
├── modules/
│   ├── auth/
│   │   ├── login.php
│   │   ├── logout.php
│   │   └── register.php
│   ├── dashboard/
│   │   └── index.php
│   ├── patients/
│   │   ├── index.php             # Patient list
│   │   ├── add.php               # Register patient
│   │   ├── edit.php              # Edit patient
│   │   ├── view.php              # View patient details
│   │   └── medical-records.php   # Medical records
│   ├── appointments/
│   │   ├── index.php             # Appointment list
│   │   ├── calendar.php          # Calendar view
│   │   ├── add.php               # Book appointment
│   │   └── manage.php            # Manage appointments
│   ├── emr/
│   │   ├── index.php             # EMR dashboard
│   │   ├── consultation.php      # Doctor consultation
│   │   ├── diagnosis.php         # Diagnosis entry
│   │   ├── prescription.php      # Prescription
│   │   └── history.php           # Medical history
│   ├── pharmacy/
│   │   ├── index.php             # Pharmacy dashboard
│   │   ├── medicines.php         # Medicine inventory
│   │   ├── prescriptions.php     # Prescription management
│   │   ├── dispensing.php        # Medicine dispensing
│   │   └── stock.php             # Stock management
│   ├── laboratory/
│   │   ├── index.php             # Lab dashboard
│   │   ├── tests.php             # Test catalog
│   │   ├── orders.php            # Test orders
│   │   ├── results.php           # Test results
│   │   └── reports.php           # Lab reports
│   ├── radiology/
│   │   ├── index.php             # Radiology dashboard
│   │   ├── imaging.php           # Imaging types
│   │   ├── orders.php            # Imaging orders
│   │   ├── results.php           # Imaging results
│   │   └── reports.php           # Radiology reports
│   ├── billing/
│   │   ├── index.php             # Billing dashboard
│   │   ├── invoices.php          # Invoice management
│   │   ├── payments.php          # Payment processing
│   │   ├── bpjs.php              # BPJS claims
│   │   └── reports.php           # Financial reports
│   ├── inpatient/
│   │   ├── index.php             # Inpatient dashboard
│   │   ├── admission.php         # Patient admission
│   │   ├── beds.php              # Bed management
│   │   ├── wards.php             # Ward management
│   │   └── discharge.php         # Patient discharge
│   ├── outpatient/
│   │   ├── index.php             # Outpatient dashboard
│   │   ├── registration.php      # OPD registration
│   │   ├── queue.php             # Queue management
│   │   └── consultation.php      # OPD consultation
│   ├── staff/
│   │   ├── index.php             # Staff list
│   │   ├── add.php               # Add staff
│   │   ├── edit.php              # Edit staff
│   │   ├── schedule.php          # Staff schedule
│   │   └── attendance.php        # Attendance
│   ├── departments/
│   │   ├── index.php             # Department list
│   │   ├── manage.php            # Manage departments
│   │   └── staff.php             # Department staff
│   ├── inventory/
│   │   ├── index.php             # Inventory dashboard
│   │   ├── items.php             # Item management
│   │   ├── stock.php             # Stock levels
│   │   ├── orders.php            # Purchase orders
│   │   └── suppliers.php         # Supplier management
│   └── reports/
│       ├── index.php             # Reports dashboard
│       ├── patient-reports.php   # Patient reports
│       ├── financial-reports.php # Financial reports
│       ├── operational-reports.php # Operational reports
│       └── analytics.php         # Analytics dashboard
├── database/
│   ├── schema.sql                # Complete database schema
│   ├── sample-data.sql           # Sample/demo data
│   ├── bpjs-data.sql             # BPJS reference data
│   └── indonesian-medical-terms.sql # Medical terminology
├── api/
│   ├── patients.php              # Patient API endpoints
│   ├── appointments.php          # Appointment API
│   └── common.php                # Common API functions
├── docs/
│   ├── INSTALLATION.md           # Installation guide
│   ├── USER_MANUAL_EN.md         # User manual (English)
│   ├── USER_MANUAL_ID.md         # User manual (Indonesian)
│   └── API_DOCUMENTATION.md      # API documentation
├── index.php                     # Main entry point
└── README.md                     # Project readme
```

---

## 4. Database Schema Overview / Gambaran Skema Database

### 4.1 Core Tables (24+ tables)

#### Authentication & Users
- `users` - System users
- `roles` - User roles
- `permissions` - System permissions
- `user_roles` - User-role mapping

#### Patient Management
- `patients` - Patient information
- `patient_contacts` - Emergency contacts
- `patient_insurance` - Insurance details (BPJS)
- `medical_records` - Medical history

#### Appointments
- `appointments` - Appointment bookings
- `appointment_slots` - Available time slots
- `appointment_status` - Status tracking

#### EMR (Electronic Medical Records)
- `consultations` - Doctor consultations
- `diagnoses` - Diagnosis records (ICD-10)
- `prescriptions` - Prescription records
- `prescription_items` - Prescription details
- `vital_signs` - Patient vital signs

#### Pharmacy
- `medicines` - Medicine catalog
- `medicine_categories` - Medicine categories
- `pharmacy_stock` - Stock levels
- `medicine_dispensing` - Dispensing records

#### Laboratory
- `lab_tests` - Test catalog
- `lab_orders` - Test orders
- `lab_results` - Test results
- `lab_test_parameters` - Test parameters

#### Radiology
- `radiology_types` - Imaging types (X-Ray, CT, MRI, etc.)
- `radiology_orders` - Imaging orders
- `radiology_results` - Imaging results

#### Billing
- `invoices` - Patient invoices
- `invoice_items` - Invoice line items
- `payments` - Payment records
- `payment_methods` - Payment methods
- `bpjs_claims` - BPJS claim records

#### Inpatient
- `admissions` - Patient admissions
- `wards` - Hospital wards
- `beds` - Bed inventory
- `bed_assignments` - Bed assignments
- `discharges` - Discharge records

#### Outpatient
- `opd_registrations` - OPD registrations
- `opd_queue` - Queue management
- `opd_consultations` - OPD consultations

#### Staff & Departments
- `staff` - Staff information
- `departments` - Hospital departments
- `staff_schedules` - Staff schedules
- `staff_attendance` - Attendance records

#### Inventory
- `inventory_items` - Medical supplies
- `inventory_categories` - Item categories
- `inventory_stock` - Stock levels
- `purchase_orders` - Purchase orders
- `suppliers` - Supplier information

#### System
- `settings` - System settings
- `audit_logs` - Activity logs
- `notifications` - System notifications

---

## 5. Key Features by Module / Fitur Utama per Modul

### 5.1 Patient Registration (Registrasi Pasien)
- Complete patient demographics
- Photo upload
- Emergency contact information
- BPJS/insurance details
- Medical history
- Allergy information
- Blood type and vital information

### 5.2 Appointment System (Sistem Janji Temu)
- Calendar-based booking
- Doctor availability management
- SMS/Email notifications
- Queue management
- Walk-in registration
- Appointment reminders

### 5.3 Electronic Medical Records (Rekam Medis Elektronik)
- Patient medical history
- Consultation notes
- Diagnosis (ICD-10 codes)
- Treatment plans
- Prescription management
- Vital signs tracking
- Document attachments
- Medical certificates

### 5.4 Pharmacy Management (Manajemen Farmasi)
- Medicine inventory
- Prescription processing
- Medicine dispensing
- Stock alerts
- Expiry tracking
- Supplier management
- Purchase orders

### 5.5 Laboratory (Laboratorium)
- Test catalog management
- Test ordering
- Sample collection
- Result entry
- Report generation
- Reference ranges
- Quality control

### 5.6 Radiology (Radiologi)
- Imaging type management (X-Ray, CT, MRI, Ultrasound)
- Order management
- Result documentation
- Image storage references
- Report generation

### 5.7 Billing & BPJS (Penagihan & BPJS)
- Invoice generation
- Multiple payment methods
- BPJS claim processing
- Payment receipts
- Financial reports
- Outstanding payments
- Payment history

### 5.8 Inpatient Management (Manajemen Rawat Inap)
- Patient admission
- Bed allocation
- Ward management
- Daily care records
- Discharge planning
- Discharge summary

### 5.9 Outpatient Management (Manajemen Rawat Jalan)
- OPD registration
- Queue management
- Consultation records
- Follow-up scheduling

### 5.10 Staff Management (Manajemen Staf)
- Staff profiles
- Role assignment
- Schedule management
- Attendance tracking
- Leave management
- Performance tracking

### 5.11 Reporting & Analytics (Pelaporan & Analitik)
- Patient statistics
- Financial reports
- Operational reports
- Department performance
- Doctor performance
- Revenue analysis
- BPJS claim reports
- Inventory reports

---

## 6. Indonesian Healthcare Compliance / Kepatuhan Kesehatan Indonesia

### 6.1 BPJS Integration Features
- BPJS card number validation
- BPJS patient class (Kelas 1, 2, 3)
- BPJS claim submission
- BPJS coverage verification
- BPJS reporting

### 6.2 Indonesian Medical Standards
- ICD-10 diagnosis codes (Indonesian version)
- Indonesian medical terminology
- Standard operating procedures (SOP)
- Ministry of Health (Kemenkes) compliance
- Hospital accreditation standards (KARS)

### 6.3 Required Documents
- Informed consent forms
- Medical certificates (Surat Keterangan Sakit)
- Referral letters (Surat Rujukan)
- Discharge summaries (Resume Medis)
- Birth certificates (Surat Kelahiran)
- Death certificates (Surat Kematian)

---

## 7. Technical Specifications / Spesifikasi Teknis

### 7.1 Frontend Technologies
- **HTML5** - Semantic markup
- **CSS3** - Modern styling
- **Bootstrap 5** - Responsive framework
- **jQuery** - DOM manipulation
- **DataTables** - Table management
- **Chart.js** - Data visualization
- **FullCalendar** - Appointment calendar
- **Select2** - Enhanced dropdowns
- **SweetAlert2** - Beautiful alerts

### 7.2 Backend Technologies
- **PHP 8.x** - Server-side scripting
- **PDO** - Database abstraction
- **Session Management** - User sessions
- **File Upload** - Document management
- **PDF Generation** - Report generation (TCPDF/mPDF)
- **Email** - PHPMailer for notifications

### 7.3 Database
- **MariaDB 10.x** - Primary database
- **InnoDB Engine** - Transaction support
- **UTF-8 Encoding** - Unicode support
- **Foreign Keys** - Referential integrity
- **Indexes** - Performance optimization
- **Views** - Complex queries
- **Stored Procedures** - Business logic

### 7.4 Security Features
- **Password Hashing** - bcrypt/Argon2
- **SQL Injection Prevention** - Prepared statements
- **XSS Protection** - Input sanitization
- **CSRF Protection** - Token validation
- **Session Security** - Secure session handling
- **Role-Based Access Control** - Permission system
- **Audit Logging** - Activity tracking
- **Data Encryption** - Sensitive data protection

---

## 8. User Interface Design / Desain Antarmuka Pengguna

### 8.1 Design Principles
- Clean and professional medical interface
- Indonesian hospital color scheme (blue/green/white)
- Responsive design (mobile, tablet, desktop)
- Intuitive navigation
- Accessibility compliance
- Fast loading times
- Consistent layout across modules

### 8.2 Dashboard Components
- Quick statistics cards
- Recent activities
- Appointment calendar
- Patient queue
- Alerts and notifications
- Quick actions
- Performance charts

### 8.3 Language Toggle
- Prominent language switcher (EN/ID)
- Persistent language preference
- Complete translation coverage
- Date/time localization
- Currency formatting (IDR)

---

## 9. Sample Data for Demo / Data Contoh untuk Demo

### 9.1 Demo Users
- Super Admin (admin/admin123)
- Doctor (dr.budi/doctor123)
- Nurse (ns.siti/nurse123)
- Pharmacist (apt.andi/pharma123)
- Lab Technician (lab.dewi/lab123)
- Receptionist (rec.maya/reception123)

### 9.2 Demo Patients
- 50+ sample patients with complete profiles
- Various age groups and conditions
- BPJS and non-BPJS patients
- Complete medical histories

### 9.3 Demo Data
- Appointments (past and upcoming)
- Medical records
- Prescriptions
- Lab results
- Radiology reports
- Invoices and payments
- Inventory items
- Staff schedules

---

## 10. Implementation Phases / Fase Implementasi

### Phase 1: Foundation (Days 1-2)
- Project structure setup
- Database schema creation
- Authentication system
- Basic UI framework
- Language system

### Phase 2: Core Modules (Days 3-5)
- Patient management
- Appointment system
- EMR system
- Dashboard

### Phase 3: Clinical Modules (Days 6-8)
- Pharmacy management
- Laboratory system
- Radiology system

### Phase 4: Financial & Operations (Days 9-11)
- Billing system
- BPJS integration
- Inpatient management
- Outpatient management

### Phase 5: Administration (Days 12-13)
- Staff management
- Department management
- Inventory management

### Phase 6: Reporting & Polish (Days 14-15)
- Reports and analytics
- Sample data insertion
- UI refinement
- Testing
- Documentation

---

## 11. Installation Requirements / Persyaratan Instalasi

### 11.1 Server Requirements
- **Web Server:** Apache 2.4+ (XAMPP)
- **PHP:** 8.0 or higher
- **Database:** MariaDB 10.4+ or MySQL 8.0+
- **Memory:** 512MB minimum (1GB recommended)
- **Disk Space:** 500MB minimum

### 11.2 PHP Extensions Required
- PDO
- PDO_MySQL
- mbstring
- openssl
- json
- gd (for image processing)
- fileinfo
- zip

### 11.3 Browser Support
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

---

## 12. Success Metrics / Metrik Keberhasilan

### 12.1 Functional Completeness
- ✅ All 11 core modules implemented
- ✅ BPJS integration ready
- ✅ Bilingual support complete
- ✅ Role-based access working
- ✅ All CRUD operations functional

### 12.2 Performance
- Page load time < 2 seconds
- Database queries optimized
- Responsive on mobile devices
- Handles 100+ concurrent users

### 12.3 Usability
- Intuitive navigation
- Clear error messages
- Helpful tooltips
- Comprehensive documentation
- Easy data entry

---

## 13. Future Enhancements / Pengembangan Masa Depan

### 13.1 Advanced Features
- Mobile app (Android/iOS)
- Telemedicine integration
- AI-powered diagnosis assistance
- Electronic signature
- Barcode/QR code scanning
- SMS gateway integration
- WhatsApp notifications
- Online appointment booking portal
- Patient portal
- Doctor mobile app

### 13.2 Integration Possibilities
- Government health systems (SATUSEHAT)
- Insurance companies
- Laboratory equipment
- Pharmacy systems
- Accounting software
- HR systems

---

## 14. Support & Maintenance / Dukungan & Pemeliharaan

### 14.1 Documentation Provided
- Installation guide (EN/ID)
- User manual (EN/ID)
- Administrator guide
- API documentation
- Database schema documentation
- Troubleshooting guide

### 14.2 Training Materials
- Video tutorials
- User guides
- Quick reference cards
- FAQ document

---

## Conclusion / Kesimpulan

This Hospital Management System is designed to be a comprehensive, production-ready solution for Indonesian hospitals. It combines modern web technologies with Indonesian healthcare requirements, providing a bilingual, BPJS-ready system that can handle all aspects of hospital operations from patient registration to billing and reporting.

The system is built with scalability, security, and usability in mind, making it suitable for both small clinics and large hospitals. The modular architecture allows for easy customization and future enhancements.

---

**Document Version:** 1.0  
**Last Updated:** 2026-06-23  
**Prepared By:** Bob (AI Assistant)  
**Status:** Ready for Implementation