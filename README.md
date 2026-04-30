# Vehicle Management System API

Sistem manajemen kendaraan dengan fitur pelaporan kerusakan, assignment driver, dan tracking perbaikan oleh teknisi.

## 🚀 Setup Setelah Clone

Setelah clone project, ikuti langkah berikut:

### 1. Install Dependencies

```bash
composer install
```

### 2. Setup Environment

```bash
# Copy .env.example ke .env
copy .env.example .env

# Generate application key
php artisan key:generate
```

### 3. Setup Database

```bash
# Jalankan migrasi
php artisan migrate

# Jalankan seeder (opsional, untuk data testing)
php artisan db:seed
```

### 4. Jalankan Server

```bash
php artisan serve
```

Server akan berjalan di: `http://localhost:8000`

---

## 📋 Fitur Sistem

### **ADMIN**

1. ✅ Membuat username dan password untuk driver dan teknisi
2. ✅ Membuat data kendaraan berdasarkan merk dan plat (plat harus unique)
3. ✅ Assign kendaraan ke driver
4. ✅ Menyimpan data kerusakan mobil yang sudah diperbaiki
5. ✅ Melihat follow-up kendaraan rusak dari teknisi

### **DRIVER**

1. ✅ Login dengan username dan password dari admin
2. ✅ Verifikasi kendaraan dengan plat nomor yang sudah di-assign
3. ✅ Memasukan laporan kerusakan setelah verifikasi kendaraan
4. ✅ Melihat proses perbaikan mobil dari teknisi

### **TEKNISI**

1. ✅ Login dengan username dan password dari admin
2. ✅ Melihat data kerusakan dari driver
3. ✅ Update status kerusakan (proses, butuh follow-up admin, selesai)
4. ✅ Memberikan catatan pada kerusakan kendaraan

---

## 🗂️ Database Structure

### Users Table

-   `id` - Primary Key
-   `username` - Unique username
-   `password` - Hashed password
-   `role` - enum: admin, driver, teknisi
-   `is_active` - Boolean

### Vehicles Table

-   `id` - Primary Key
-   `brand` - Merk kendaraan
-   `model` - Model kendaraan
-   `plate_number` - Plat nomor (UNIQUE)
-   `year` - Tahun

### Vehicle Assignments Table

-   `id` - Primary Key
-   `vehicle_id` - Foreign Key to vehicles
-   `driver_id` - Foreign Key to users
-   `assigned_at` - Timestamp

### Damage Reports Table

-   `id` - Primary Key
-   `vehicle_id` - Foreign Key to vehicles
-   `driver_id` - Foreign Key to users
-   `description` - Text deskripsi kerusakan

### Technician Responses Table

-   `id` - Primary Key
-   `damage_id` - Foreign Key to damage_reports
-   `technician_id` - Foreign Key to users
-   `status` - enum: proses, butuh_followup_admin, fatal, selesai
-   `note` - Catatan teknisi

---

## 🔐 Login Credentials (Setelah Seeder)

### Admin

-   Username: `admin`
-   Password: `admin123`

### Driver 1

-   Username: `driver1`
-   Password: `driver123`
-   Kendaraan: B1234XYZ (Toyota Avanza)

### Driver 2

-   Username: `driver2`
-   Password: `driver123`
-   Kendaraan: B5678ABC (Honda Brio)

### Teknisi 1

-   Username: `teknisi1`
-   Password: `teknisi123`

### Teknisi 2

-   Username: `teknisi2`
-   Password: `teknisi123`

---

## 📚 API Documentation

Lihat dokumentasi lengkap API di: [API_DOCUMENTATION.md](./API_DOCUMENTATION.md)

### Quick Start Testing dengan cURL

#### 1. Login sebagai Driver

```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"driver1\",\"password\":\"driver123\"}"
```

#### 2. Verifikasi Kendaraan

```bash
curl -X POST http://localhost:8000/api/driver/vehicles/verify \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d "{\"plate_number\":\"B1234XYZ\"}"
```

#### 3. Buat Laporan Kerusakan

```bash
curl -X POST http://localhost:8000/api/driver/damage-reports \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d "{\"plate_number\":\"B1234XYZ\",\"description\":\"Mesin overheat\"}"
```

---

## 🛠️ Tech Stack

-   **Framework:** Laravel 12
-   **Authentication:** Laravel Sanctum
-   **Database:** SQLite (default) / MySQL
-   **PHP Version:** ^8.2

---

## 📁 Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       ├── AuthController.php
│   │       ├── Admin/
│   │       │   ├── UserController.php
│   │       │   ├── VehicleController.php
│   │       │   ├── VehicleAssignmentController.php
│   │       │   └── DamageReportController.php
│   │       ├── Driver/
│   │       │   └── DamageReportController.php
│   │       └── Technician/
│   │           └── DamageReportController.php
│   └── Middleware/
│       └── CheckRole.php
└── Models/
    ├── User.php
    ├── Vehicle.php
    ├── VehicleAssignment.php
    ├── DamageReport.php
    └── TechnicianResponse.php
```

---

## 🔒 Security Features

-   ✅ Password di-hash menggunakan bcrypt
-   ✅ API authentication dengan Laravel Sanctum
-   ✅ Role-based access control (RBAC)
-   ✅ Validation untuk semua input
-   ✅ Unique constraint pada plate_number
-   ✅ Authorization check untuk akses data

---

## 🧪 Testing

### Manual Testing dengan Postman

1. Import collection API (lihat API_DOCUMENTATION.md)
2. Set base URL: `http://localhost:8000/api`
3. Login untuk mendapatkan token
4. Set token di Authorization header

### Testing Flow

1. Login sebagai admin → Buat user & kendaraan → Assign kendaraan
2. Login sebagai driver → Verifikasi kendaraan → Buat laporan kerusakan
3. Login sebagai teknisi → Lihat laporan → Beri respons
4. Login sebagai admin → Lihat follow-up → Tandai selesai

---

## 🐛 Troubleshooting

### Error: "SQLSTATE[HY000] [14] unable to open database file"

```bash
# Buat database file
touch database/database.sqlite
php artisan migrate
```

### Error: "Token mismatch"

```bash
php artisan config:clear
php artisan cache:clear
```

### Error: "Class not found"

```bash
composer dump-autoload
php artisan optimize:clear
```

---

## 📝 Notes

-   Project ini menggunakan SQLite sebagai default database
-   Untuk production, ubah ke MySQL/PostgreSQL di `.env`
-   Password di-hash dengan bcrypt untuk keamanan
-   Setiap role memiliki endpoint yang berbeda dengan middleware protection

---

## 👥 Contributors

Developed for Web Programming Project

---

## 📄 License

This project is for educational purposes.
