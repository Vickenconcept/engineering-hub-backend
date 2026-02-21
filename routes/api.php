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

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [App\Http\Controllers\NotificationController::class, 'index']);
        Route::get('/unread-count', [App\Http\Controllers\NotificationController::class, 'unreadCount']);
        Route::post('/mark-all-read', [App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
        Route::post('/{id}/read', [App\Http\Controllers\NotificationController::class, 'markAsRead']);
        Route::delete('/{id}', [App\Http\Controllers\NotificationController::class, 'destroy']);
    });

    // Client routes
    Route::middleware('role:client')->prefix('client')->group(function () {
        // Companies (browse verified companies)
        Route::get('companies', [App\Http\Controllers\Client\CompanyController::class, 'index']);
        Route::get('companies/{id}', [App\Http\Controllers\Client\CompanyController::class, 'show']);
        
        // Consultations
        Route::apiResource('consultations', App\Http\Controllers\Client\ConsultationController::class)
            ->except(['update', 'destroy'])
            ->names([
                'index' => 'client.consultations.index',
                'store' => 'client.consultations.store',
                'show' => 'client.consultations.show',
            ]);
        Route::post('consultations/{id}/pay', [App\Http\Controllers\Client\ConsultationController::class, 'pay']);
        
        // Projects
        Route::apiResource('projects', App\Http\Controllers\Client\ProjectController::class)
            ->except(['update', 'destroy'])
            ->names([
                'index' => 'client.projects.index',
                'store' => 'client.projects.store',
                'show' => 'client.projects.show',
            ]);
        Route::post('projects/{id}/complete', [App\Http\Controllers\Client\ProjectController::class, 'complete']);
        
        // Milestones
        Route::post('milestones/{id}/fund', [App\Http\Controllers\Client\MilestoneController::class, 'fundEscrow']);
        Route::post('milestones/{id}/approve', [App\Http\Controllers\Client\MilestoneController::class, 'approve']);
        Route::post('milestones/{id}/reject', [App\Http\Controllers\Client\MilestoneController::class, 'reject']);
        Route::post('milestones/{id}/verify', [App\Http\Controllers\Client\MilestoneController::class, 'verify']);
        Route::put('milestones/{id}/notes', [App\Http\Controllers\Client\MilestoneController::class, 'updateNotes']);
        Route::post('document-update-requests/{id}/grant', [App\Http\Controllers\Client\ProjectController::class, 'grantDocumentUpdate']);
        Route::post('document-update-requests/{id}/deny', [App\Http\Controllers\Client\ProjectController::class, 'denyDocumentUpdate']);
    });

    // Company routes
    Route::middleware('role:company')->prefix('company')->group(function () {
        // Company profile
        Route::get('profile', [App\Http\Controllers\Company\CompanyProfileController::class, 'show']);
        Route::post('profile', [App\Http\Controllers\Company\CompanyProfileController::class, 'store']);
        Route::put('profile', [App\Http\Controllers\Company\CompanyProfileController::class, 'update']);
        Route::post('profile/update', [App\Http\Controllers\Company\CompanyProfileController::class, 'update']); // For FormData updates
        Route::post('profile/appeal', [App\Http\Controllers\Company\CompanyProfileController::class, 'appeal']);
        
        // Consultations
        Route::apiResource('consultations', App\Http\Controllers\Company\ConsultationController::class)
            ->except(['update', 'destroy'])
            ->names([
                'index' => 'company.consultations.index',
                'store' => 'company.consultations.store',
                'show' => 'company.consultations.show',
            ]);
        Route::post('consultations/{id}/complete', [App\Http\Controllers\Company\ConsultationController::class, 'complete']);
        
        // Projects
        Route::apiResource('projects', App\Http\Controllers\Company\ProjectController::class)
            ->except(['update', 'destroy'])
            ->names([
                'index' => 'company.projects.index',
                'store' => 'company.projects.store',
                'show' => 'company.projects.show',
            ]);
        Route::post('projects/{id}/milestones', [App\Http\Controllers\Company\ProjectController::class, 'createMilestones']);
        Route::post('projects/{id}/documents', [App\Http\Controllers\Company\ProjectController::class, 'uploadDocuments']);
        Route::post('projects/{id}/complete', [App\Http\Controllers\Company\ProjectController::class, 'complete']);
        Route::post('projects/{id}/document-update-request', [App\Http\Controllers\Company\ProjectController::class, 'requestDocumentUpdate']);
        
        // Milestones
        Route::get('milestones/{id}', [App\Http\Controllers\Company\MilestoneController::class, 'show']);
        Route::post('milestones/{id}/submit', [App\Http\Controllers\Company\MilestoneController::class, 'submit']);
        Route::post('milestones/{id}/evidence', [App\Http\Controllers\Company\MilestoneController::class, 'uploadEvidence']);
        Route::put('milestones/{id}/notes', [App\Http\Controllers\Company\MilestoneController::class, 'updateNotes']);
    });

    // Admin routes
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        // Users
        Route::get('users', [App\Http\Controllers\Admin\UserController::class, 'index']);
        Route::post('users', [App\Http\Controllers\Admin\UserController::class, 'store']);
        Route::get('users/{id}', [App\Http\Controllers\Admin\UserController::class, 'show']);
        Route::put('users/{id}', [App\Http\Controllers\Admin\UserController::class, 'update']);
        Route::post('users/{id}/activate', [App\Http\Controllers\Admin\UserController::class, 'activate']);
        Route::post('users/{id}/suspend', [App\Http\Controllers\Admin\UserController::class, 'suspend']);
        Route::delete('users/{id}', [App\Http\Controllers\Admin\UserController::class, 'destroy']);
        
        // Companies
        Route::get('companies', [App\Http\Controllers\Admin\CompanyController::class, 'index']);
        Route::post('companies', [App\Http\Controllers\Admin\CompanyController::class, 'store']);
        Route::get('companies/{id}', [App\Http\Controllers\Admin\CompanyController::class, 'show']);
        Route::post('companies/{id}/approve', [App\Http\Controllers\Admin\CompanyController::class, 'approve']);
        Route::post('companies/{id}/reject', [App\Http\Controllers\Admin\CompanyController::class, 'reject']);
        Route::post('companies/{id}/suspend', [App\Http\Controllers\Admin\CompanyController::class, 'suspend']);
        Route::post('companies/{id}/lift-suspension', [App\Http\Controllers\Admin\CompanyController::class, 'liftSuspension']);
        Route::get('companies/{id}/appeals', [App\Http\Controllers\Admin\CompanyController::class, 'getAppeals']);
        
        // Milestones
        Route::get('milestones', [App\Http\Controllers\Admin\MilestoneController::class, 'index']);
        Route::post('milestones/{id}/release', [App\Http\Controllers\Admin\MilestoneController::class, 'release']);

        // Escrow hold reference: lookup by hold_ref (client, company, project, milestone)
        Route::get('escrow-hold-refs/{holdRef}', [App\Http\Controllers\Admin\EscrowHoldReferenceController::class, 'show']);
        
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

