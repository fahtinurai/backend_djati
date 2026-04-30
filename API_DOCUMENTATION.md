# API Documentation - Vehicle Management System

## Base URL

```
http://localhost:8000/api
```

## Authentication

Menggunakan Laravel Sanctum. Setelah login, gunakan token di header:

```
Authorization: Bearer {token}
```

---

## 1. PUBLIC ENDPOINTS

### Login

```http
POST /login
```

**Request Body:**

```json
{
    "username": "admin",
    "password": "password123"
}
```

**Response:**

```json
{
    "token": "1|xyz...",
    "user": {
        "id": 1,
        "username": "admin",
        "role": "admin"
    }
}
```

---

## 2. ADMIN ENDPOINTS

### 2.1 User Management (Driver & Teknisi)

#### Get All Users

```http
GET /admin/users
```

#### Create User

```http
POST /admin/users
```

**Request Body:**

```json
{
    "username": "driver1",
    "password": "password123",
    "role": "driver"
}
```

**Role options:** `admin`, `driver`, `teknisi`

#### Update User

```http
PUT /admin/users/{id}
```

**Request Body:**

```json
{
    "username": "driver1_updated",
    "password": "newpassword",
    "role": "driver",
    "is_active": true
}
```

#### Delete User

```http
DELETE /admin/users/{id}
```

---

### 2.2 Vehicle Management

#### Get All Vehicles

```http
GET /admin/vehicles
```

#### Create Vehicle

```http
POST /admin/vehicles
```

**Request Body:**

```json
{
    "brand": "Toyota",
    "model": "Avanza",
    "plate_number": "B1234XYZ",
    "year": 2023
}
```

**Note:** `plate_number` harus unique!

#### Update Vehicle

```http
PUT /admin/vehicles/{id}
```

#### Delete Vehicle

```http
DELETE /admin/vehicles/{id}
```

---

### 2.3 Vehicle Assignment

#### Get All Assignments

```http
GET /admin/vehicle-assignments
```

#### Assign Vehicle to Driver

```http
POST /admin/vehicle-assignments
```

**Request Body:**

```json
{
    "vehicle_id": 1,
    "driver_id": 2
}
```

**Note:** Satu kendaraan hanya bisa di-assign ke satu driver!

#### Delete Assignment

```http
DELETE /admin/vehicle-assignments/{id}
```

---

### 2.4 Damage Report Management

#### Get All Damage Reports

```http
GET /admin/damage-reports
```

#### Get Damage Report Detail

```http
GET /admin/damage-reports/{id}
```

#### Get Follow-up Reports (butuh review admin)

```http
GET /admin/damage-reports/follow-ups/list
```

#### Mark Report as Completed

```http
POST /admin/damage-reports/{id}/complete
```

**Request Body:**

```json
{
    "admin_note": "Sudah diperbaiki dan dicek oleh admin"
}
```

---

## 3. DRIVER ENDPOINTS

### 3.1 Vehicle Verification

#### Verify Vehicle by Plate Number

```http
POST /driver/vehicles/verify
```

**Request Body:**

```json
{
    "plate_number": "B1234XYZ"
}
```

**Response Success:**

```json
{
  "message": "Kendaraan terverifikasi",
  "vehicle": { ... },
  "assignment": { ... }
}
```

**Response Error:**

```json
{
    "message": "Kendaraan ini tidak di-assign ke Anda"
}
```

#### Get My Assigned Vehicles

```http
GET /driver/vehicles
```

---

### 3.2 Damage Report

#### Create Damage Report

```http
POST /driver/damage-reports
```

**Request Body:**

```json
{
    "plate_number": "B1234XYZ",
    "description": "Ban bocor dan rem blong"
}
```

#### Get My Damage Reports

```http
GET /driver/damage-reports
```

#### Get Damage Report Detail (dengan status dari teknisi)

```http
GET /driver/damage-reports/{id}
```

#### Update Damage Report

```http
PUT /driver/damage-reports/{id}
```

#### Delete Damage Report

```http
DELETE /driver/damage-reports/{id}
```

---

## 4. TEKNISI ENDPOINTS

### 4.1 View Damage Reports

#### Get All Damage Reports

```http
GET /technician/damage-reports
```

#### Get Damage Report Detail

```http
GET /technician/damage-reports/{id}
```

---

### 4.2 Respond to Damage Reports

#### Create Response

```http
POST /technician/damage-reports/{id}/respond
```

**Request Body:**

```json
{
    "status": "proses",
    "note": "Sedang dalam perbaikan, estimasi 2 hari"
}
```

**Status options:**

-   `proses` - Sedang dalam perbaikan
-   `butuh_followup_admin` - Butuh tindakan dari admin
-   `fatal` - Kerusakan parah
-   `selesai` - Perbaikan selesai

#### Update Response

```http
PUT /technician/technician-responses/{response_id}
```

**Request Body:**

```json
{
    "status": "selesai",
    "note": "Perbaikan selesai, kendaraan siap digunakan"
}
```

#### Get My Responses History

```http
GET /technician/my-responses
```

---

## WORKFLOW EXAMPLE

### Scenario: Driver Melaporkan Kerusakan

1. **Driver Login**

    ```http
    POST /login
    {
      "username": "driver1",
      "password": "password123"
    }
    ```

2. **Driver Verifikasi Kendaraan**

    ```http
    POST /driver/vehicles/verify
    {
      "plate_number": "B1234XYZ"
    }
    ```

3. **Driver Buat Laporan Kerusakan**

    ```http
    POST /driver/damage-reports
    {
      "plate_number": "B1234XYZ",
      "description": "Mesin overheat"
    }
    ```

4. **Teknisi Melihat Laporan**

    ```http
    GET /technician/damage-reports
    ```

5. **Teknisi Memberikan Respons**

    ```http
    POST /technician/damage-reports/1/respond
    {
      "status": "proses",
      "note": "Radiator perlu diganti"
    }
    ```

6. **Driver Cek Status Perbaikan**

    ```http
    GET /driver/damage-reports/1
    ```

7. **Teknisi Update Status**

    ```http
    PUT /technician/technician-responses/1
    {
      "status": "butuh_followup_admin",
      "note": "Perlu spare part baru, budget melebihi limit"
    }
    ```

8. **Admin Melihat Follow-up**

    ```http
    GET /admin/damage-reports/follow-ups/list
    ```

9. **Admin Tandai Selesai**
    ```http
    POST /admin/damage-reports/1/complete
    {
      "admin_note": "Spare part sudah dibeli, perbaikan selesai"
    }
    ```

---

## Error Responses

**401 Unauthorized**

```json
{
    "message": "Unauthenticated."
}
```

**403 Forbidden**

```json
{
    "message": "Forbidden - Anda tidak memiliki akses"
}
```

**404 Not Found**

```json
{
    "message": "Resource not found"
}
```

**422 Validation Error**

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "username": ["The username field is required."]
    }
}
```
