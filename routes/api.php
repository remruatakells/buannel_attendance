<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\UserController;

Route::prefix('attendance')->group(function () {
    Route::post('/', [AttendanceController::class, 'mark']);

    Route::get('/admin', [AttendanceController::class, 'adminAttendance']);
    Route::get('/today', [AttendanceController::class, 'today']);
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

    Route::post('/login', [UserController::class, 'login']);
});
