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
//nawra menambahkan RepairController
use App\Http\Controllers\Api\Admin\RepairController;
use App\Http\Controllers\Api\Admin\FinanceTransactionController;
use App\Http\Controllers\Api\Admin\PartUsageApprovalController;
use App\Http\Controllers\Api\Admin\DamageReportController as AdminDamageReportController;
use App\Http\Controllers\Api\Admin\ServiceBookingApprovalController as AdminBookingApprovalController;
use App\Http\Controllers\Api\Admin\CostEstimateApprovalController as AdminCostEstimateApprovalController;

/*
|--------------------------------------------------------------------------
| DRIVER CONTROLLERS
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\Driver\DamageReportController as DriverDamageReportController;
use App\Http\Controllers\Api\Driver\ServiceBookingController as DriverServiceBookingController;
use App\Http\Controllers\Api\Driver\ServiceReminderController as DriverServiceReminderController;
use App\Http\Controllers\Api\Driver\CostEstimateController as DriverCostEstimateController;
use App\Http\Controllers\Api\Driver\TechnicianReviewController as DriverTechnicianReviewController;

/*
|--------------------------------------------------------------------------
| TECHNICIAN CONTROLLERS
|--------------------------------------------------------------------------
*/
use App\Http\Controllers\Api\Technician\DamageReportController as TechnicianDamageReportController;
use App\Http\Controllers\Api\Technician\PartUsageController as TechnicianPartUsageController;
use App\Http\Controllers\Api\Technician\CostEstimateController as TechnicianCostEstimateController;
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
| PUBLIC
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
    | FCM TOKEN (Flutter & Web compatible)
    |--------------------------------------------------------------------------
    */
    Route::post('fcm/register', [FcmTokenController::class, 'store']);
    Route::post('fcm/unregister', [FcmTokenController::class, 'destroy']);

    // legacy mobile alias
    Route::post('mobile/fcm-token', [FcmTokenController::class, 'store']);
    Route::post('mobile/fcm-token/delete', [FcmTokenController::class, 'destroy']);

    /*
    |--------------------------------------------------------------------------
    | ADMIN ROUTES
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->prefix('admin')->group(function () {

        Route::get('dashboard', [DashboardController::class, 'index']);

        // users & vehicles
        Route::apiResource('users', UserController::class);
        Route::apiResource('vehicles', VehicleController::class);

        // vehicle assignment
        Route::get('vehicle-assignments', [VehicleAssignmentController::class, 'index']);
        Route::post('vehicle-assignments', [VehicleAssignmentController::class, 'store']);
        Route::delete('vehicle-assignments/{vehicleAssignment}', [VehicleAssignmentController::class, 'destroy']);

        // damage reports
        Route::get('damage-reports', [AdminDamageReportController::class, 'index']);
        Route::get('damage-reports/{damageReport}', [AdminDamageReportController::class, 'show']);
        Route::post('damage-reports/{damageReport}/complete', [AdminDamageReportController::class, 'markAsCompleted']);
        Route::get('damage-reports/follow-ups/list', [AdminDamageReportController::class, 'followUps']);

        // inventory
        Route::get('parts', [PartController::class, 'index']);
        Route::post('parts', [PartController::class, 'store']);
        Route::put('parts/{part}', [PartController::class, 'update']);
        Route::delete('parts/{part}', [PartController::class, 'destroy']);

        Route::get('stock-movements', [StockMovementController::class, 'index']);
        Route::post('stock-movements', [StockMovementController::class, 'store']);

        // sparepart approval
        Route::get('part-usages', [PartUsageApprovalController::class, 'index']);
        Route::get('part-usages/pending', [PartUsageApprovalController::class, 'pending']);
        Route::post('part-usages/{partUsage}/approve', [PartUsageApprovalController::class, 'approve']);
        Route::post('part-usages/{partUsage}/reject', [PartUsageApprovalController::class, 'reject']);


        // repair history
        // Nawra menambahkan route Repairs
        Route::get('repairs', [RepairController::class, 'index']);
        Route::get('repairs/{repair}', [RepairController::class, 'show']);
        Route::post('repairs', [RepairController::class, 'store']);
        Route::post('repairs/{repair}/finalize', [RepairController::class, 'finalize']);

        // finance
        Route::get('transactions', [FinanceTransactionController::class, 'index']);
        Route::post('transactions', [FinanceTransactionController::class, 'store']);
        Route::put('transactions/{financeTransaction}', [FinanceTransactionController::class, 'update']);
        Route::delete('transactions/{financeTransaction}', [FinanceTransactionController::class, 'destroy']);

        // booking approval
        Route::get('bookings', [AdminBookingApprovalController::class, 'index']);
        Route::post('bookings/{booking}/approve', [AdminBookingApprovalController::class, 'approve']);
        Route::post('bookings/{booking}/cancel', [AdminBookingApprovalController::class, 'cancel']);

        // cost estimate approval
        Route::get('cost-estimates', [AdminCostEstimateApprovalController::class, 'index']);
        Route::post('cost-estimates/{costEstimate}/approve', [AdminCostEstimateApprovalController::class, 'approve']);
        Route::post('cost-estimates/{costEstimate}/reject', [AdminCostEstimateApprovalController::class, 'reject']);
    });

    /*
    |--------------------------------------------------------------------------
    | DRIVER ROUTES
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:driver')->prefix('driver')->group(function () {

        // vehicles
        Route::post('vehicles/verify', [DriverDamageReportController::class, 'verifyVehicle']);
        Route::get('vehicles', [DriverDamageReportController::class, 'myVehicles']);

        // damage reports
        Route::post('damage-reports', [DriverDamageReportController::class, 'store']);
        Route::get('damage-reports', [DriverDamageReportController::class, 'index']);
        Route::get('damage-reports/{damageReport}', [DriverDamageReportController::class, 'show']);
        Route::put('damage-reports/{damageReport}', [DriverDamageReportController::class, 'update']);
        Route::delete('damage-reports/{damageReport}', [DriverDamageReportController::class, 'destroy']);

        // service booking
        Route::post('damage-reports/{damageReport}/booking', [DriverServiceBookingController::class, 'store']);
        Route::get('damage-reports/{damageReport}/booking', [DriverServiceBookingController::class, 'show']);
        Route::post('bookings/{booking}/cancel', [DriverServiceBookingController::class, 'cancel']);

        // service reminder
        Route::put('vehicles/{vehicle}/service-reminder', [DriverServiceReminderController::class, 'update']);

        // cost estimate (view only)
        Route::get('damage-reports/{damageReport}/cost-estimate', [DriverCostEstimateController::class, 'show']);

        // technician review
        Route::get('damage-reports/{damageReport}/review', [DriverTechnicianReviewController::class, 'show']);
        Route::post('damage-reports/{damageReport}/review', [DriverTechnicianReviewController::class, 'store']);
    });

    /*
    |--------------------------------------------------------------------------
    | TECHNICIAN ROUTES
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:teknisi')->prefix('technician')->group(function () {

        // damage reports
        Route::get('damage-reports', [TechnicianDamageReportController::class, 'index']);
        Route::get('damage-reports/{damageReport}', [TechnicianDamageReportController::class, 'show']);

        // respond
        Route::post('damage-reports/{damageReport}/respond', [TechnicianDamageReportController::class, 'respond']);
        Route::put('technician-responses/{technicianResponse}', [TechnicianDamageReportController::class, 'updateResponse']);
        Route::get('my-responses', [TechnicianDamageReportController::class, 'myResponses']);

        // sparepart
        Route::post('part-usages', [TechnicianPartUsageController::class, 'store']);
        Route::get('my-part-usages', [TechnicianPartUsageController::class, 'myUsages']);

        // cost estimate
        Route::post('damage-reports/{damageReport}/cost-estimate', [TechnicianCostEstimateController::class, 'store']);
        Route::put('cost-estimates/{costEstimate}', [TechnicianCostEstimateController::class, 'update']);
        Route::post('cost-estimates/{costEstimate}/submit', [TechnicianCostEstimateController::class, 'submit']);

        // service job
        Route::get('jobs', [ServiceJobController::class, 'index']);
        Route::get('jobs/{booking}', [ServiceJobController::class, 'show']);
        Route::post('jobs/{booking}/start', [ServiceJobController::class, 'start']);
        Route::post('jobs/{booking}/complete', [ServiceJobController::class, 'complete']);

        // reviews
        Route::get('reviews', [TechnicianReviewController::class, 'index']);
        Route::get('reviews/{review}', [TechnicianReviewController::class, 'show']);
    });
});
