<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CreateCompanyProfileRequest;
use App\Models\Company;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CompanyProfileController extends Controller
{
    public function __construct(
        protected readonly FileUploadService $uploadService
    ) {
    }

    /**
     * Get company profile
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $company = Company::where('user_id', $user->id)
            ->with('user')
            ->first();

        if (!$company) {
            return $this->notFoundResponse('Company profile not found. Please create your profile.');
        }

        return $this->successResponse($company, 'Company profile retrieved successfully');
    }

    /**
     * Create company profile
     */
    public function store(CreateCompanyProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        // Check if profile already exists
        if ($user->company) {
            return $this->errorResponse('Company profile already exists. Use update instead.', 400);
        }

        $validated = $request->validated();

        // Handle file uploads to Cloudinary
        $licenseDocuments = [];
        if ($request->hasFile('license_documents')) {
            foreach ($request->file('license_documents') as $file) {
                try {
                    $folder = "engineering-hub/companies/licenses";
                    $result = $this->uploadService->uploadFile($file, $folder);
                    $licenseDocuments[] = $result['url']; // Store Cloudinary URL
                } catch (\Exception $e) {
                    return $this->errorResponse('Failed to upload license document: ' . $e->getMessage(), 500);
                }
            }
        }

        $company = Company::create([
            'user_id' => $user->id,
            'company_name' => $validated['company_name'],
            'registration_number' => $validated['registration_number'],
            'license_documents' => !empty($licenseDocuments) ? $licenseDocuments : null,
            'portfolio_links' => $validated['portfolio_links'] ?? null,
            'specialization' => $validated['specialization'] ?? null,
            'status' => Company::STATUS_PENDING,
        ]);

        // Log audit action
        app(AuditLogService::class)->logCompanyAction('created', $company->id, [
            'company_name' => $company->company_name,
        ]);

        return $this->createdResponse(
            $company->load('user'),
            'Company profile created successfully. Awaiting admin approval.'
        );
    }

    /**
     * Update company profile
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $company = Company::where('user_id', $user->id)->firstOrFail();

        // Block suspended companies from updating profile (except consultation_fee)
        if ($company->status === Company::STATUS_SUSPENDED) {
            // Allow only consultation_fee updates for suspended companies
            $allowedFields = ['consultation_fee'];
            $requestKeys = collect($request->all())->keys();
            $hasRestrictedField = $requestKeys->diff($allowedFields)->isNotEmpty();
            
            if ($hasRestrictedField) {
                return $this->errorResponse('Your company account is suspended. You can only update consultation fee. Please contact support to appeal your suspension.', 403);
            }
        }

        // Log incoming request data for debugging
        Log::info('Company Profile Update Request', [
            'user_id' => $user->id,
            'company_id' => $company->id,
            'all_request_data' => $request->all(),
            'consultation_fee_raw' => $request->input('consultation_fee'),
            'has_consultation_fee' => $request->has('consultation_fee'),
        ]);

        // Cannot update core profile fields if approved (requires admin approval for changes)
        // But consultation_fee can be updated anytime as it's a business setting
        $coreFields = ['company_name', 'registration_number', 'license_documents', 'portfolio_links', 'specialization'];
        $requestKeys = collect($request->all())->keys()->filter(function($key) {
            return $key !== 'consultation_fee'; // Exclude consultation_fee from core fields check
        });
        $hasCoreFieldUpdate = $requestKeys->intersect($coreFields)->isNotEmpty();
        
        // If company is approved, check if core fields are actually being CHANGED
        if ($company->isApproved() && $hasCoreFieldUpdate) {
            // Check if the values are actually different from current values
            $hasActualChange = false;
            foreach ($coreFields as $field) {
                if ($request->has($field)) {
                    $requestValue = $request->input($field);
                    $currentValue = $company->$field;
                    
                    // Compare values (handle arrays specially)
                    if (is_array($requestValue) && is_array($currentValue)) {
                        if (json_encode($requestValue) !== json_encode($currentValue)) {
                            $hasActualChange = true;
                            break;
                        }
                    } elseif ($requestValue != $currentValue) {
                        $hasActualChange = true;
                        break;
                    }
                }
            }
            
            if ($hasActualChange) {
                return $this->errorResponse('Cannot update core profile fields after approval. Contact admin for changes.', 403);
            }
            // If no actual changes to core fields, allow the update (might be updating consultation_fee only)
        }

        $validated = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:255'],
            'registration_number' => ['sometimes', 'string', 'unique:companies,registration_number,' . $company->id],
            'license_documents' => ['nullable', 'array'],
            'license_documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'portfolio_links' => ['nullable', 'array'],
            'portfolio_links.*' => ['url'],
            'specialization' => ['nullable', 'array'],
            'specialization.*' => ['string', 'max:100'],
            'consultation_fee' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);
        
        Log::info('After Validation', [
            'validated_data' => $validated,
            'consultation_fee_in_validated' => isset($validated['consultation_fee']),
        ]);

        // Handle file uploads to Cloudinary
        if ($request->hasFile('license_documents')) {
            $licenseDocuments = [];
            foreach ($request->file('license_documents') as $file) {
                try {
                    $folder = "engineering-hub/companies/licenses";
                    $result = $this->uploadService->uploadFile($file, $folder);
                    $licenseDocuments[] = $result['url']; // Store Cloudinary URL
                } catch (\Exception $e) {
                    return $this->errorResponse('Failed to upload license document: ' . $e->getMessage(), 500);
                }
            }
            $validated['license_documents'] = $licenseDocuments;
        }
        
        // Explicitly handle consultation_fee - ensure it's included in update even if 0
        // Check both request input and validated array
        if ($request->has('consultation_fee') || isset($validated['consultation_fee'])) {
            $feeValue = $request->input('consultation_fee') ?? $validated['consultation_fee'] ?? null;
            // Convert to float, or null if empty string
            $validated['consultation_fee'] = ($feeValue !== null && $feeValue !== '' && $feeValue !== 'null') 
                ? (float) $feeValue 
                : null;
        }

        Log::info('Before Update', [
            'validated_data' => $validated,
            'consultation_fee_value' => $validated['consultation_fee'] ?? 'NOT SET',
        ]);

        $company->update($validated);
        $company->refresh(); // Refresh to get updated data
        
        Log::info('After Update', [
            'company_consultation_fee' => $company->consultation_fee,
        ]);

        // Log audit action
        app(AuditLogService::class)->logCompanyAction('updated', $company->id);

        return $this->successResponse(
            $company->load('user'),
            'Company profile updated successfully.'
        );
    }

    /**
     * Appeal suspension / Re-request approval
     * This creates an appeal notification for admin - does NOT auto-change status
     */
    public function appeal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $user = $request->user();
        
        $company = Company::where('user_id', $user->id)->firstOrFail();

        // Only allow appeal if suspended or rejected
        if (!in_array($company->status, [Company::STATUS_SUSPENDED, Company::STATUS_REJECTED])) {
            return $this->errorResponse('You can only appeal if your account is suspended or rejected', 400);
        }

        // DO NOT change status - just log the appeal for admin review
        // Admin will manually review and decide whether to lift suspension or re-approve
        app(AuditLogService::class)->logCompanyAction('appeal_submitted', $company->id, [
            'appealed_by' => $user->id,
            'current_status' => $company->status,
            'suspension_reason' => $company->suspension_reason,
            'appeal_message' => $validated['message'] ?? null,
        ]);

        // Send notifications
        $company->load('user');
        $company->user->notify(new AppealSubmittedNotification($company, $validated['message'] ?? null));
        
        // Notify all admins
        $admins = User::where('role', User::ROLE_ADMIN)->get();
        foreach ($admins as $admin) {
            $admin->notify(new AppealSubmittedNotification($company, $validated['message'] ?? null));
        }

        return $this->successResponse(
            $company->fresh()->load('user'),
            'Appeal submitted successfully. Our support team will review your request and contact you soon. Please do not submit multiple appeals.'
        );
    }
}
