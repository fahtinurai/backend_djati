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

    // Legacy mobile alias
    Route::post('mobile/fcm-token', [FcmTokenController::class, 'store']);
    Route::post('mobile/fcm-token/delete', [FcmTokenController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ADMIN ROUTES
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->prefix('admin')->group(function () {

        Route::get('dashboard', [DashboardController::class, 'index']);

        /*
        |--------------------------------------------------------------------------
        | USERS & VEHICLES
        |--------------------------------------------------------------------------
        */
        Route::apiResource('users', UserController::class);
        Route::apiResource('vehicles', VehicleController::class);

        /*
        |--------------------------------------------------------------------------
        | VEHICLE ASSIGNMENT
        |--------------------------------------------------------------------------
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

        Route::post(
            'damage-reports/{damageReport}/store-finished-repair',
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
        | Digunakan oleh React Admin MaintenanceScheduling:
        | GET  /api/admin/bookings?status=requested
        | GET  /api/admin/bookings?status=all
        | GET  /api/admin/technicians
        | POST /api/admin/bookings/{booking}/approve
        | POST /api/admin/bookings/{booking}/reschedule
        | POST /api/admin/bookings/{booking}/cancel
        |
        */
        Route::get('bookings', [AdminBookingApprovalController::class, 'index']);

        // Digunakan dropdown mechanic / technician di React Admin
        Route::get('technicians', [AdminBookingApprovalController::class, 'technicians']);

        Route::post('bookings/{booking}/approve', [AdminBookingApprovalController::class, 'approve']);
        Route::post('bookings/{booking}/reschedule', [AdminBookingApprovalController::class, 'reschedule']);
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
        | DRIVER VEHICLES
        |--------------------------------------------------------------------------
        */
        Route::get('my-vehicle', [DriverDamageReportController::class, 'myVehicle']);
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
        | Alur:
        | 1. Driver membuat damage report.
        | 2. Flutter memanggil:
        |    POST /api/driver/damage-reports/{damageReport}/booking
        | 3. Backend membuat service booking status requested.
        | 4. Admin melihat booking di Maintenance Scheduling.
        | 5. Setelah admin approve, driver melihat jadwal di:
        |    GET /api/driver/bookings
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
        | Route lama. Masih dipertahankan agar fitur lama tidak langsung rusak.
        |
        | GET  /api/technician/damage-reports
        | GET  /api/technician/damage-reports/{damageReport}
        | POST /api/technician/damage-reports/{damageReport}/respond
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
        | Route baru yang dipakai Flutter maintenance scheduling:
        |
        | GET  /api/technician/service-jobs?status=active
        | GET  /api/technician/service-jobs?status=all
        | GET  /api/technician/service-jobs/{booking}
        | POST /api/technician/service-jobs/{booking}/start
        | POST /api/technician/service-jobs/{booking}/complete
        |
        | Route lama /jobs juga dipertahankan sebagai alias:
        |
        | GET  /api/technician/jobs
        | GET  /api/technician/jobs/{booking}
        | POST /api/technician/jobs/{booking}/start
        | POST /api/technician/jobs/{booking}/complete
        |
        */

        // New maintenance scheduling route
        Route::get('service-jobs', [ServiceJobController::class, 'index']);
        Route::get('service-jobs/{booking}', [ServiceJobController::class, 'show']);
        Route::post('service-jobs/{booking}/start', [ServiceJobController::class, 'start']);
        Route::post('service-jobs/{booking}/complete', [ServiceJobController::class, 'complete']);

        // Legacy alias route
        Route::get('jobs', [ServiceJobController::class, 'index']);
        Route::get('jobs/{booking}', [ServiceJobController::class, 'show']);
        Route::post('jobs/{booking}/start', [ServiceJobController::class, 'start']);
        Route::post('jobs/{booking}/complete', [ServiceJobController::class, 'complete']);

        /*
        |--------------------------------------------------------------------------
        | SPAREPART USAGE
        |--------------------------------------------------------------------------
        |
        | GET  /api/technician/parts?search=...
        | POST /api/technician/part-usages
        | GET  /api/technician/my-part-usages
        |
        */
        Route::get('parts', [TechnicianPartUsageController::class, 'parts']);
        Route::post('part-usages', [TechnicianPartUsageController::class, 'store']);
        Route::get('my-part-usages', [TechnicianPartUsageController::class, 'myUsages']);

        /*
        |--------------------------------------------------------------------------
        | TECHNICIAN REVIEWS
        |--------------------------------------------------------------------------
        */
        Route::get('reviews', [TechnicianReviewController::class, 'index']);
        Route::get('reviews/{review}', [TechnicianReviewController::class, 'show']);
    });
});