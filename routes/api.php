<?php

use App\Http\Controllers\Api\AdminPortalController;
use App\Http\Controllers\Api\AdminHelpdeskController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CustomerPortalController;
use App\Http\Controllers\Api\HelpdeskController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/services', [CatalogController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/security', [AuthController::class, 'security']);
    Route::patch('/auth/profile', [AuthController::class, 'updateProfile']);
    Route::patch('/auth/password', [AuthController::class, 'updatePassword']);
    Route::patch('/auth/two-factor', [AuthController::class, 'updateTwoFactor']);
    Route::delete('/auth/sessions/others', [AuthController::class, 'destroyOtherSessions']);
    Route::delete('/auth/sessions/{token}', [AuthController::class, 'destroySession']);
    Route::get('/orders/me', [CustomerPortalController::class, 'orders']);
    Route::get('/customer-services/me', [CustomerPortalController::class, 'services']);
    Route::get('/helpdesk/tickets/me', [HelpdeskController::class, 'index']);
    Route::post('/customer-services/{customerService}/report-issue', [CustomerPortalController::class, 'reportServiceIssue']);
    Route::get('/notifications/me', [CustomerPortalController::class, 'notifications']);
    Route::patch('/notifications/{notification}', [CustomerPortalController::class, 'updateNotification']);
    Route::post('/notifications/mark-all-read', [CustomerPortalController::class, 'markAllNotificationsRead']);
    Route::delete('/notifications/{notification}', [CustomerPortalController::class, 'destroyNotification']);
    Route::post('/orders/checkout', [CustomerPortalController::class, 'checkout']);
    Route::patch('/customer-services/{customerService}/request-cancellation', [CustomerPortalController::class, 'requestServiceCancellation']);
    Route::post('/orders/{portalOrder}/upload-proof', [CustomerPortalController::class, 'uploadPaymentProof']);

    Route::prefix('/admin')->middleware('admin')->group(function () {
        Route::get('/clients', [AdminPortalController::class, 'clients']);
        Route::get('/users', [AdminPortalController::class, 'adminUsers']);
        Route::post('/users', [AdminPortalController::class, 'createAdminUser']);
        Route::get('/purchases', [AdminPortalController::class, 'purchases']);
        Route::get('/helpdesk/tickets', [AdminHelpdeskController::class, 'index']);
        Route::get('/helpdesk/tickets/{helpdeskTicket}', [AdminHelpdeskController::class, 'show']);
        Route::patch('/helpdesk/tickets/{helpdeskTicket}', [AdminHelpdeskController::class, 'update']);
        Route::patch('/purchases/{portalOrder}/approve', [AdminPortalController::class, 'approveOrder']);
        Route::get('/customer-services', [AdminPortalController::class, 'services']);
        Route::post('/catalog-services', [AdminPortalController::class, 'createCatalogService']);
        Route::patch('/catalog-services/{service}', [AdminPortalController::class, 'updateCatalogService']);
        Route::post('/customer-services', [AdminPortalController::class, 'createService']);
        Route::patch('/customer-services/{customerService}/request-cancellation', [AdminPortalController::class, 'requestServiceCancellation']);
        Route::patch('/customer-services/{customerService}/approve-cancellation', [AdminPortalController::class, 'approveServiceCancellation']);
        Route::patch('/customer-services/{customerService}/reject-cancellation', [AdminPortalController::class, 'rejectServiceCancellation']);
        Route::patch('/customer-services/{customerService}/status', [AdminPortalController::class, 'updateServiceStatus']);
        Route::patch('/clients/{user}/billing', [AdminPortalController::class, 'updateClientBilling']);
        Route::patch('/users/{user}', [AdminPortalController::class, 'updateAdminUser']);
        Route::patch('/users/{user}/password', [AdminPortalController::class, 'resetAdminUserPassword']);
        Route::patch('/users/{user}/status', [AdminPortalController::class, 'toggleAdminUserStatus']);
        Route::patch('/clients/{user}/approve-registration', [AdminPortalController::class, 'approveClientRegistration']);
        Route::patch('/clients/{user}/reject-registration', [AdminPortalController::class, 'rejectClientRegistration']);
        Route::patch('/profile-update-requests/{profileUpdateRequest}/approve', [AdminPortalController::class, 'approveProfileUpdateRequest']);
        Route::patch('/profile-update-requests/{profileUpdateRequest}/reject', [AdminPortalController::class, 'rejectProfileUpdateRequest']);
    });
});
