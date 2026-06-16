<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

/*
|--------------------------------------------------------------------------
| ADMIN CONTROLLERS
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\VehicleController;
use App\Http\Controllers\Api\Admin\VehicleAssignmentController;
use App\Http\Controllers\Api\Admin\PartController;
use App\Http\Controllers\Api\Admin\StockMovementController;
use App\Http\Controllers\Api\Admin\RepairController;
use App\Http\Controllers\Api\Admin\FinanceTransactionController;
use App\Http\Controllers\Api\Admin\PartUsageApprovalController;
use App\Http\Controllers\Api\Admin\DamageReportController as AdminDamageReportController;
use App\Http\Controllers\Api\Admin\ServiceBookingApprovalController as AdminBookingApprovalController;

/*
|--------------------------------------------------------------------------
| DRIVER CONTROLLERS
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\Driver\DamageReportController as DriverDamageReportController;
use App\Http\Controllers\Api\Driver\ServiceBookingController as DriverServiceBookingController;
use App\Http\Controllers\Api\Driver\ServiceReminderController as DriverServiceReminderController;
use App\Http\Controllers\Api\Driver\TechnicianReviewController as DriverTechnicianReviewController;
use App\Http\Controllers\Api\Driver\VehicleDailyLogController as DriverVehicleDailyLogController;

/*
|--------------------------------------------------------------------------
| TECHNICIAN CONTROLLERS
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\Technician\DamageReportController as TechnicianDamageReportController;
use App\Http\Controllers\Api\Technician\PartUsageController as TechnicianPartUsageController;
use App\Http\Controllers\Api\Technician\ServiceJobController;
use App\Http\Controllers\Api\Technician\TechnicianReviewController as TechnicianReviewController;

/*
|--------------------------------------------------------------------------
| MOBILE / FCM
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\Mobile\FcmTokenController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES
|--------------------------------------------------------------------------
*/
Route::post('login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| AUTHENTICATED ROUTES
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    /*
    |--------------------------------------------------------------------------
    | FCM TOKEN
    |--------------------------------------------------------------------------
    */
    Route::post('fcm/register', [FcmTokenController::class, 'store']);
    Route::post('fcm/unregister', [FcmTokenController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | LEGACY MOBILE FCM ALIAS
    |--------------------------------------------------------------------------
    */
    Route::post('mobile/fcm-token', [FcmTokenController::class, 'store']);
    Route::post('mobile/fcm-token/delete', [FcmTokenController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ADMIN ROUTES
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->prefix('admin')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | DASHBOARD
        |--------------------------------------------------------------------------
        */
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('dashboard/chart', [DashboardController::class, 'chart']);

        /*
        |--------------------------------------------------------------------------
        | USERS & VEHICLES
        |--------------------------------------------------------------------------
        |
        | Vehicle flow:
        | 1. Admin membuat kendaraan.
        | 2. Admin mengisi initial_hour_meter / initial_kpi.
        | 3. initial tidak diubah oleh teknisi.
        | 4. Teknisi nantinya hanya update current_hour_meter / latest_hour_meter
        |    melalui ServiceJobController ketika pekerjaan selesai.
        |
        */
        Route::apiResource('users', UserController::class);
        Route::apiResource('vehicles', VehicleController::class);

        /*
        |--------------------------------------------------------------------------
        | VEHICLE ASSIGNMENT
        |--------------------------------------------------------------------------
        |
        | Flow:
        | VehiclePage.jsx
        | -> admin membuat kendaraan
        | -> admin assign kendaraan ke driver
        | -> driver membaca kendaraan melalui /api/driver/my-vehicle
        |
        */
        Route::get('vehicle-assignments', [VehicleAssignmentController::class, 'index']);
        Route::post('vehicle-assignments', [VehicleAssignmentController::class, 'store']);
        Route::get('vehicle-assignments/{vehicleAssignment}', [VehicleAssignmentController::class, 'show']);
        Route::delete('vehicle-assignments/{vehicleAssignment}', [VehicleAssignmentController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | DAMAGE REPORTS ADMIN
        |--------------------------------------------------------------------------
        */
        Route::get('damage-reports/follow-ups/list', [AdminDamageReportController::class, 'followUps']);
        Route::get('damage-reports/finished-repairs', [AdminDamageReportController::class, 'finishedRepairs']);

        Route::get('damage-reports', [AdminDamageReportController::class, 'index']);
        Route::get('damage-reports/{damageReport}', [AdminDamageReportController::class, 'show']);

        Route::post('damage-reports/{damageReport}/complete', [AdminDamageReportController::class, 'markAsCompleted']);
        Route::post('damage-reports/{damageReport}/approve-follow-up', [AdminDamageReportController::class, 'markAsCompleted']);
        Route::post('damage-reports/{damageReport}/approve-followup', [AdminDamageReportController::class, 'markAsCompleted']);

        Route::post('damage-reports/{damageReport}/reject', [AdminDamageReportController::class, 'reject']);
        Route::post('damage-reports/{damageReport}/reject-report', [AdminDamageReportController::class, 'reject']);

        Route::post(
            'damage-reports/{damageReport}/store-finished-repair',
            [AdminDamageReportController::class, 'storeFinishedRepairHistory']
        );

        /*
        |--------------------------------------------------------------------------
        | DAMAGE REPORTS LEGACY ALIAS
        |--------------------------------------------------------------------------
        */
        Route::get('reports', [AdminDamageReportController::class, 'index']);
        Route::get('reports/{damageReport}', [AdminDamageReportController::class, 'show']);

        Route::post('reports/{damageReport}/complete', [AdminDamageReportController::class, 'markAsCompleted']);
        Route::post('reports/{damageReport}/approve-follow-up', [AdminDamageReportController::class, 'markAsCompleted']);
        Route::post('reports/{damageReport}/approve-followup', [AdminDamageReportController::class, 'markAsCompleted']);

        Route::post('reports/{damageReport}/reject', [AdminDamageReportController::class, 'reject']);
        Route::post('reports/{damageReport}/reject-report', [AdminDamageReportController::class, 'reject']);

        Route::post(
            'reports/{damageReport}/store-finished-repair',
            [AdminDamageReportController::class, 'storeFinishedRepairHistory']
        );

        /*
        |--------------------------------------------------------------------------
        | INVENTORY
        |--------------------------------------------------------------------------
        */
        Route::get('parts', [PartController::class, 'index']);
        Route::post('parts', [PartController::class, 'store']);
        Route::put('parts/{part}', [PartController::class, 'update']);
        Route::delete('parts/{part}', [PartController::class, 'destroy']);

        Route::get('stock-movements', [StockMovementController::class, 'index']);
        Route::post('stock-movements', [StockMovementController::class, 'store']);

        /*
        |--------------------------------------------------------------------------
        | SPAREPART APPROVAL
        |--------------------------------------------------------------------------
        |
        | Flow:
        | 1. Teknisi request sparepart dari mobile.
        | 2. Request masuk ke admin.
        | 3. Admin approve / reject.
        | 4. Jika approve, backend sebaiknya mengurangi stok.
        |
        */
        Route::get('part-usages/pending', [PartUsageApprovalController::class, 'pending']);
        Route::get('part-usages', [PartUsageApprovalController::class, 'index']);
        Route::post('part-usages/{partUsage}/approve', [PartUsageApprovalController::class, 'approve']);
        Route::post('part-usages/{partUsage}/reject', [PartUsageApprovalController::class, 'reject']);

        /*
        |--------------------------------------------------------------------------
        | REPAIR HISTORY ADMIN
        |--------------------------------------------------------------------------
        */
        Route::get('repairs', [RepairController::class, 'index']);
        Route::post('repairs', [RepairController::class, 'store']);
        Route::get('repairs/{repair}', [RepairController::class, 'show']);
        Route::post('repairs/{repair}/finalize', [RepairController::class, 'finalize']);

        /*
        |--------------------------------------------------------------------------
        | FINANCE
        |--------------------------------------------------------------------------
        */
        Route::get('transactions', [FinanceTransactionController::class, 'index']);
        Route::post('transactions', [FinanceTransactionController::class, 'store']);
        Route::put('transactions/{financeTransaction}', [FinanceTransactionController::class, 'update']);
        Route::delete('transactions/{financeTransaction}', [FinanceTransactionController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | BOOKING APPROVAL / MAINTENANCE SCHEDULING
        |--------------------------------------------------------------------------
        |
        | Flow:
        | 1. Driver membuat booking dari damage report.
        | 2. Admin melihat booking di Maintenance Scheduling.
        | 3. Admin memilih teknisi dan approve booking.
        | 4. Jika booking tidak disetujui, admin reject booking dari Maintenance Scheduling.
        | 5. Booking masuk ke teknisi terpilih setelah approve.
        | 6. Teknisi start / complete job dari mobile.
        |
        */
        Route::get('bookings', [AdminBookingApprovalController::class, 'index']);

        /*
        |--------------------------------------------------------------------------
        | DROPDOWN TECHNICIAN
        |--------------------------------------------------------------------------
        |
        | Dipakai React Admin untuk memilih teknisi.
        | Backend harus filter role = teknisi agar dropdown tidak menampilkan
        | admin / driver.
        |
        */
        Route::get('technicians', [AdminBookingApprovalController::class, 'technicians']);

        Route::post('bookings/{booking}/approve', [AdminBookingApprovalController::class, 'approve']);
        Route::post('bookings/{booking}/reschedule', [AdminBookingApprovalController::class, 'reschedule']);
        Route::post('bookings/{booking}/reject', [AdminBookingApprovalController::class, 'reject']);
        Route::post('bookings/{booking}/cancel', [AdminBookingApprovalController::class, 'cancel']);
    });

    /*
    |--------------------------------------------------------------------------
    | DRIVER ROUTES
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:driver')->prefix('driver')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | DRIVER VEHICLE ASSIGNMENT
        |--------------------------------------------------------------------------
        |
        | Endpoint yang dipakai Flutter:
        | GET /api/driver/my-vehicle
        |
        | Response ideal:
        | {
        |   "data": {
        |     "id": 1,
        |     "vehicle_id": 1,
        |     "driver_id": 2,
        |     "assigned_at": "...",
        |     "vehicle": {
        |       "id": 1,
        |       "equipment_name": "...",
        |       "plate_number": "...",
        |       "serial_number": "...",
        |       "initial_kpi": 100,
        |       "initial_hour_meter": 100,
        |       "current_hour_meter": 180,
        |       "target_availability": 90,
        |       "current_ma": 94.5,
        |       "status": "active"
        |     }
        |   }
        | }
        |
        | Catatan:
        | initial_hour_meter tetap data awal dari admin.
        | current_hour_meter / current_ma diupdate setelah teknisi complete job.
        |
        */
        /*
        |--------------------------------------------------------------------------
        | PENTING - DRIVER MY VEHICLE
        |--------------------------------------------------------------------------
        |
        | Endpoint ini dipakai Flutter DamageReportPage.dart.
        | Arahkan ke DriverDamageReportController, bukan Admin VehicleAssignmentController,
        | supaya response assigned unit membawa HM terbaru dari vehicleResponse:
        | - current_hour_meter
        | - latest_hour_meter
        | - final_hour_meter
        | - hour_meter_terbaru
        | - current_ma
        |
        */
        Route::get('my-vehicle', [DriverDamageReportController::class, 'myVehicle']);
        Route::get('my-assigned-vehicle', [DriverDamageReportController::class, 'myVehicle']);

        /*
        |--------------------------------------------------------------------------
        | DRIVER VEHICLES LEGACY
        |--------------------------------------------------------------------------
        |
        | Tetap dipertahankan agar UI lama yang mengambil daftar kendaraan
        | dari DamageReportController tidak langsung rusak.
        |
        */
        Route::get('vehicles', [DriverDamageReportController::class, 'myVehicles']);
        Route::post('vehicles/verify', [DriverDamageReportController::class, 'verifyVehicle']);

        /*
        |--------------------------------------------------------------------------
        | DRIVER VEHICLE DAILY LOGS
        |--------------------------------------------------------------------------
        */
        Route::get('vehicle-daily-logs', [DriverVehicleDailyLogController::class, 'index']);
        Route::post('vehicle-daily-logs', [DriverVehicleDailyLogController::class, 'store']);
        Route::get('vehicle-daily-logs/{vehicleDailyLog}', [DriverVehicleDailyLogController::class, 'show']);

        /*
        |--------------------------------------------------------------------------
        | DRIVER DAMAGE REPORTS
        |--------------------------------------------------------------------------
        |
        | Flow:
        | 1. Driver membuka DamageReportPage.dart.
        | 2. Flutter mengambil kendaraan dari /api/driver/my-vehicle.
        | 3. Driver submit laporan ke /api/driver/damage-reports.
        | 4. Backend menyimpan image ke storage public.
        | 5. Response mengembalikan id + image_url.
        |
        */
        Route::get('damage-reports', [DriverDamageReportController::class, 'index']);
        Route::post('damage-reports', [DriverDamageReportController::class, 'store']);
        Route::get('damage-reports/{damageReport}', [DriverDamageReportController::class, 'show']);
        Route::put('damage-reports/{damageReport}', [DriverDamageReportController::class, 'update']);
        Route::delete('damage-reports/{damageReport}', [DriverDamageReportController::class, 'destroy']);

        /*
        |--------------------------------------------------------------------------
        | DRIVER SERVICE BOOKING
        |--------------------------------------------------------------------------
        |
        | Flow:
        | 1. Driver membuat damage report.
        | 2. Flutter memanggil:
        |    POST /api/driver/damage-reports/{damageReport}/booking
        | 3. Backend membuat service booking status requested.
        | 4. Admin melihat booking di Maintenance Scheduling.
        | 5. Setelah admin approve, driver melihat jadwal di:
        |    GET /api/driver/bookings
        |
        | Data booking driver sebaiknya include:
        | - damage_report
        | - vehicle
        | - technician
        | - mttr / mtbf / ma
        | - final_hour_meter / current_hour_meter
        |
        */
        Route::get('bookings', [DriverServiceBookingController::class, 'index']);
        Route::post('damage-reports/{damageReport}/booking', [DriverServiceBookingController::class, 'store']);
        Route::get('damage-reports/{damageReport}/booking', [DriverServiceBookingController::class, 'show']);
        Route::post('bookings/{booking}/cancel', [DriverServiceBookingController::class, 'cancel']);

        /*
        |--------------------------------------------------------------------------
        | DRIVER SERVICE REMINDER
        |--------------------------------------------------------------------------
        */
        Route::put('vehicles/{vehicle}/service-reminder', [DriverServiceReminderController::class, 'update']);

        /*
        |--------------------------------------------------------------------------
        | DRIVER TECHNICIAN REVIEW
        |--------------------------------------------------------------------------
        */
        Route::get('damage-reports/{damageReport}/review', [DriverTechnicianReviewController::class, 'show']);
        Route::post('damage-reports/{damageReport}/review', [DriverTechnicianReviewController::class, 'store']);
    });

    /*
    |--------------------------------------------------------------------------
    | TECHNICIAN ROUTES
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:teknisi')->prefix('technician')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | TECHNICIAN DAMAGE REPORTS - LEGACY
        |--------------------------------------------------------------------------
        |
        | Route lama tetap dipertahankan agar page lama tidak langsung rusak.
        | Untuk flow baru, teknisi sebaiknya memakai service-jobs.
        |
        */
        Route::get('damage-reports', [TechnicianDamageReportController::class, 'index']);
        Route::get('damage-reports/{damageReport}', [TechnicianDamageReportController::class, 'show']);
        Route::post('damage-reports/{damageReport}/respond', [TechnicianDamageReportController::class, 'respond']);
        Route::put('technician-responses/{technicianResponse}', [TechnicianDamageReportController::class, 'updateResponse']);
        Route::get('my-responses', [TechnicianDamageReportController::class, 'myResponses']);

        /*
        |--------------------------------------------------------------------------
        | TECHNICIAN SERVICE JOB / MAINTENANCE SCHEDULING
        |--------------------------------------------------------------------------
        |
        | Flow:
        | 1. Admin approve booking dan memilih technician_id.
        | 2. Teknisi login melihat job miliknya:
        |    GET /api/technician/service-jobs?status=active
        | 3. Teknisi start job:
        |    POST /api/technician/service-jobs/{booking}/start
        | 4. Teknisi complete job:
        |    POST /api/technician/service-jobs/{booking}/complete
        |
        | Complete job menerima data mentah:
        | - final_hour_meter
        | - current_hour_meter
        | - latest_hour_meter
        | - total_repair_time
        | - total_operational_time
        | - failure_count
        | - actual_operating_hours
        | - breakdown_hours
        |
        | Backend yang sebaiknya menghitung:
        | - mttr
        | - mtbf
        | - ma
        |
        | Backend yang sebaiknya update:
        | - service_bookings.status = completed
        | - service_bookings.completed_at
        | - vehicles.current_hour_meter
        | - vehicles.current_ma
        | - damage_reports.status
        | - repair history
        |
        */
        Route::get('service-jobs', [ServiceJobController::class, 'index']);
        Route::get('service-jobs/{booking}', [ServiceJobController::class, 'show']);
        Route::post('service-jobs/{booking}/start', [ServiceJobController::class, 'start']);
        Route::post('service-jobs/{booking}/complete', [ServiceJobController::class, 'complete']);

        /*
        |--------------------------------------------------------------------------
        | TECHNICIAN SERVICE JOB - MAINTENANCE DATA ALIAS
        |--------------------------------------------------------------------------
        |
        | Alias ini tidak mengubah struktur lama.
        | Tetap diarahkan ke method complete karena controller yang sama
        | yang harus menangani update data maintenance.
        |
        | Dipakai jika nanti Flutter ingin endpoint yang lebih jelas:
        | POST /api/technician/service-jobs/{booking}/maintenance-data
        | POST /api/technician/service-jobs/{booking}/update-maintenance-data
        |
        */
        Route::post('service-jobs/{booking}/maintenance-data', [ServiceJobController::class, 'complete']);
        Route::post('service-jobs/{booking}/update-maintenance-data', [ServiceJobController::class, 'complete']);

        /*
        |--------------------------------------------------------------------------
        | LEGACY ALIAS ROUTE
        |--------------------------------------------------------------------------
        */
        Route::get('jobs', [ServiceJobController::class, 'index']);
        Route::get('jobs/{booking}', [ServiceJobController::class, 'show']);
        Route::post('jobs/{booking}/start', [ServiceJobController::class, 'start']);
        Route::post('jobs/{booking}/complete', [ServiceJobController::class, 'complete']);

        /*
        |--------------------------------------------------------------------------
        | LEGACY ALIAS - MAINTENANCE DATA
        |--------------------------------------------------------------------------
        */
        Route::post('jobs/{booking}/maintenance-data', [ServiceJobController::class, 'complete']);
        Route::post('jobs/{booking}/update-maintenance-data', [ServiceJobController::class, 'complete']);

        /*
        |--------------------------------------------------------------------------
        | SPAREPART USAGE
        |--------------------------------------------------------------------------
        |
        | Flow:
        | 1. Teknisi membuka job.
        | 2. Teknisi start job.
        | 3. Saat status in_progress, teknisi request sparepart.
        | 4. Request sparepart sebaiknya membawa:
        |    - part_id
        |    - damage_report_id
        |    - service_booking_id / booking_id
        |    - qty
        |    - note
        | 5. Admin approve / reject dari route admin.
        |
        */
        Route::get('parts', [TechnicianPartUsageController::class, 'parts']);
        Route::post('part-usages', [TechnicianPartUsageController::class, 'store']);

        /*
        |--------------------------------------------------------------------------
        | TECHNICIAN PART USAGE HISTORY
        |--------------------------------------------------------------------------
        |
        | my-part-usages dipakai mobile sekarang.
        | part-usages GET ditambahkan sebagai alias agar lebih konsisten
        | jika service Flutter memakai /technician/part-usages.
        |
        */
        Route::get('my-part-usages', [TechnicianPartUsageController::class, 'myUsages']);
        Route::get('part-usages', [TechnicianPartUsageController::class, 'myUsages']);

        /*
        |--------------------------------------------------------------------------
        | TECHNICIAN REVIEWS
        |--------------------------------------------------------------------------
        */
        Route::get('reviews', [TechnicianReviewController::class, 'index']);
        Route::get('reviews/{review}', [TechnicianReviewController::class, 'show']);
    });
});