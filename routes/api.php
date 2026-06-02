<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\OrganizationAttendancePolicyController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrganizationTimingController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\AdminAccessToken;

Route::prefix('attendance')->group(function () {
    Route::post('/', [AttendanceController::class, 'mark']);
    Route::post('/login', [UserController::class, 'login']);

    Route::middleware([AdminAccessToken::class])->group(function () {
        Route::get('/admin', [AttendanceController::class, 'adminAttendance']);
        Route::post('/admin', [AttendanceController::class, 'storeAdmin']);
        Route::get('/admin/excel', [AttendanceController::class, 'adminAttendanceExcel']);
        Route::get('/today', [AttendanceController::class, 'today']);
        Route::get('/user/{userId}/excel', [AttendanceController::class, 'userAttendanceExcel']);
        Route::get('/user/{userId}', [AttendanceController::class, 'userAttendance']);

        Route::put('/update/{id}', [AttendanceController::class, 'update']);
        Route::delete('/delete/{id}', [AttendanceController::class, 'delete']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);

        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations', [OrganizationController::class, 'store']);
        Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
        Route::put('/organizations/{organization}', [OrganizationController::class, 'update']);
        Route::patch('/organizations/{organization}', [OrganizationController::class, 'update']);
        Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);
        Route::get('/organizations/{organization}/timing', [OrganizationTimingController::class, 'show']);
        Route::put('/organizations/{organization}/timing', [OrganizationTimingController::class, 'update']);
        Route::patch('/organizations/{organization}/timing', [OrganizationTimingController::class, 'update']);
        Route::get('/organizations/{organization}/attendance-policy', [OrganizationAttendancePolicyController::class, 'show']);
        Route::put('/organizations/{organization}/attendance-policy', [OrganizationAttendancePolicyController::class, 'update']);
        Route::patch('/organizations/{organization}/attendance-policy', [OrganizationAttendancePolicyController::class, 'update']);
    });
});
