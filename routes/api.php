<?php

use Illuminate\Support\Facades\Route;

// Public routes with rate limiting
Route::middleware('throttle:10,1')->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('/register', [App\Http\Controllers\Auth\AuthController::class, 'register']);
        Route::post('/login', [App\Http\Controllers\Auth\AuthController::class, 'login']);
    });
});

// Payment routes
Route::post('/payments/webhook', [App\Http\Controllers\PaymentController::class, 'handleWebhook'])
    ->middleware('throttle:100,1'); // Higher limit for webhooks

Route::middleware(['auth:sanctum', 'throttle:30,1'])->group(function () {
    Route::post('/payments/verify', [App\Http\Controllers\PaymentController::class, 'verifyPayment']);
});

// Protected routes with rate limiting
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [App\Http\Controllers\Auth\AuthController::class, 'logout']);
        Route::get('/me', [App\Http\Controllers\Auth\AuthController::class, 'me']);
    });

    // File upload
    Route::post('/upload', [App\Http\Controllers\FileUploadController::class, 'upload']);

    // Client routes
    Route::middleware('role:client')->prefix('client')->group(function () {
        // Companies (browse verified companies)
        Route::get('companies', [App\Http\Controllers\Client\CompanyController::class, 'index']);
        Route::get('companies/{id}', [App\Http\Controllers\Client\CompanyController::class, 'show']);
        
        // Consultations
        Route::apiResource('consultations', App\Http\Controllers\Client\ConsultationController::class)->except(['update', 'destroy']);
        Route::post('consultations/{id}/pay', [App\Http\Controllers\Client\ConsultationController::class, 'pay']);
        
        // Projects
        Route::apiResource('projects', App\Http\Controllers\Client\ProjectController::class)->except(['update', 'destroy']);
        
        // Milestones
        Route::post('milestones/{id}/fund', [App\Http\Controllers\Client\MilestoneController::class, 'fundEscrow']);
        Route::post('milestones/{id}/approve', [App\Http\Controllers\Client\MilestoneController::class, 'approve']);
        Route::post('milestones/{id}/reject', [App\Http\Controllers\Client\MilestoneController::class, 'reject']);
        Route::post('milestones/{id}/verify', [App\Http\Controllers\Client\MilestoneController::class, 'verify']);
        Route::put('milestones/{id}/notes', [App\Http\Controllers\Client\MilestoneController::class, 'updateNotes']);
    });

    // Company routes
    Route::middleware('role:company')->prefix('company')->group(function () {
        // Company profile
        Route::get('profile', [App\Http\Controllers\Company\CompanyProfileController::class, 'show']);
        Route::post('profile', [App\Http\Controllers\Company\CompanyProfileController::class, 'store']);
        Route::put('profile', [App\Http\Controllers\Company\CompanyProfileController::class, 'update']);
        Route::post('profile/update', [App\Http\Controllers\Company\CompanyProfileController::class, 'update']); // For FormData updates
        
        // Consultations
        Route::apiResource('consultations', App\Http\Controllers\Company\ConsultationController::class)->except(['update', 'destroy']);
        Route::post('consultations/{id}/complete', [App\Http\Controllers\Company\ConsultationController::class, 'complete']);
        
        // Projects
        Route::apiResource('projects', App\Http\Controllers\Company\ProjectController::class)->except(['update', 'destroy']);
        Route::post('projects/{id}/milestones', [App\Http\Controllers\Company\ProjectController::class, 'createMilestones']);
        
        // Milestones
        Route::get('milestones/{id}', [App\Http\Controllers\Company\MilestoneController::class, 'show']);
        Route::post('milestones/{id}/submit', [App\Http\Controllers\Company\MilestoneController::class, 'submit']);
        Route::post('milestones/{id}/evidence', [App\Http\Controllers\Company\MilestoneController::class, 'uploadEvidence']);
        Route::put('milestones/{id}/notes', [App\Http\Controllers\Company\MilestoneController::class, 'updateNotes']);
    });

    // Admin routes
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        // Companies
        Route::get('companies', [App\Http\Controllers\Admin\CompanyController::class, 'index']);
        Route::get('companies/{id}', [App\Http\Controllers\Admin\CompanyController::class, 'show']);
        Route::post('companies/{id}/approve', [App\Http\Controllers\Admin\CompanyController::class, 'approve']);
        Route::post('companies/{id}/reject', [App\Http\Controllers\Admin\CompanyController::class, 'reject']);
        Route::post('companies/{id}/suspend', [App\Http\Controllers\Admin\CompanyController::class, 'suspend']);
        
        // Milestones
        Route::get('milestones', [App\Http\Controllers\Admin\MilestoneController::class, 'index']);
        Route::post('milestones/{id}/release', [App\Http\Controllers\Admin\MilestoneController::class, 'release']);
        
        // Disputes
        Route::get('disputes', [App\Http\Controllers\Admin\DisputeController::class, 'index']);
        Route::get('disputes/{id}', [App\Http\Controllers\Admin\DisputeController::class, 'show']);
        Route::post('disputes/{id}/resolve', [App\Http\Controllers\Admin\DisputeController::class, 'resolve']);
        
        // Audit logs
        Route::get('audit-logs', [App\Http\Controllers\Admin\AuditLogController::class, 'index']);
    });

    // Payment accounts (all authenticated users)
    Route::prefix('payment-accounts')->group(function () {
        Route::get('/', [App\Http\Controllers\PaymentAccountController::class, 'index']);
        Route::post('/', [App\Http\Controllers\PaymentAccountController::class, 'store']);
        Route::put('{id}', [App\Http\Controllers\PaymentAccountController::class, 'update']);
        Route::delete('{id}', [App\Http\Controllers\PaymentAccountController::class, 'destroy']);
        Route::post('{id}/set-default', [App\Http\Controllers\PaymentAccountController::class, 'setDefault']);
        Route::get('/banks', [App\Http\Controllers\PaymentAccountController::class, 'getBanks']);
        Route::post('/verify', [App\Http\Controllers\PaymentAccountController::class, 'verifyAccount']);
    });

    // Admin: Get payment accounts for a user
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('users/{userId}/payment-accounts', [App\Http\Controllers\PaymentAccountController::class, 'getUserAccounts']);
        
        // Platform settings
        Route::get('platform-settings/fee', [App\Http\Controllers\Admin\PlatformSettingController::class, 'getPlatformFee']);
        Route::put('platform-settings/fee', [App\Http\Controllers\Admin\PlatformSettingController::class, 'updatePlatformFee']);
    });

    // Escrow operations
    Route::prefix('escrow')->group(function () {
        // Company: Request escrow release
        Route::middleware('role:company')->post('milestones/{id}/release', [
            App\Http\Controllers\PaymentAccountController::class, 'requestEscrowRelease'
        ]);
        
        // Client: Request escrow refund
        Route::middleware('role:client')->post('milestones/{id}/refund', [
            App\Http\Controllers\PaymentAccountController::class, 'requestEscrowRefund'
        ]);
    });

    // Transactions (all authenticated users)
    Route::get('/transactions', [App\Http\Controllers\TransactionController::class, 'index']);

    // Shared routes (all authenticated users)
    Route::prefix('projects')->group(function () {
        Route::get('{id}', [App\Http\Controllers\ProjectController::class, 'show']);
    });

    Route::prefix('disputes')->group(function () {
        Route::post('/', [App\Http\Controllers\DisputeController::class, 'store']);
    });
});

