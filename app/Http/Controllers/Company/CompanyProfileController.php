<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CompanyProfileController extends Controller
{
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
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Check if profile already exists
        if ($user->company) {
            return $this->errorResponse('Company profile already exists. Use update instead.', 400);
        }

        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'unique:companies,registration_number'],
            'license_documents' => ['nullable', 'array'],
            'license_documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'], // 5MB max
            'portfolio_links' => ['nullable', 'array'],
            'portfolio_links.*' => ['url'],
            'specialization' => ['nullable', 'array'],
            'specialization.*' => ['string', 'max:100'],
        ]);

        // Handle file uploads
        $licenseDocuments = [];
        if ($request->hasFile('license_documents')) {
            foreach ($request->file('license_documents') as $file) {
                $path = $file->store('companies/licenses', 'public');
                $licenseDocuments[] = $path;
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

        // Cannot update if approved (requires admin approval for changes)
        if ($company->isApproved()) {
            return $this->errorResponse('Cannot update approved profile. Contact admin for changes.', 403);
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
        ]);

        // Handle file uploads
        if ($request->hasFile('license_documents')) {
            $licenseDocuments = [];
            foreach ($request->file('license_documents') as $file) {
                $path = $file->store('companies/licenses', 'public');
                $licenseDocuments[] = $path;
            }
            $validated['license_documents'] = $licenseDocuments;
        }

        $company->update($validated);

        // Log audit action
        app(AuditLogService::class)->logCompanyAction('updated', $company->id);

        return $this->successResponse(
            $company->load('user'),
            'Company profile updated successfully.'
        );
    }
}
