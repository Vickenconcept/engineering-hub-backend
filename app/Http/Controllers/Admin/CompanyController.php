<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class CompanyController extends Controller
{
    public function __construct(
        protected readonly FileUploadService $uploadService
    ) {
    }
    /**
     * List all companies with filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = Company::with('user');

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by verification status
        if ($request->has('verified')) {
            if ($request->verified === 'true') {
                $query->whereNotNull('verified_at');
            } else {
                $query->whereNull('verified_at');
            }
        }

        $companies = $query->latest()->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($companies, 'Companies retrieved successfully');
    }

    /**
     * Show a specific company
     */
    public function show(string $id): JsonResponse
    {
        $company = Company::with('user')->findOrFail($id);

        return $this->successResponse($company, 'Company retrieved successfully');
    }

    /**
     * Create a new company (admin only)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // User data
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'min:8'],
            'user_status' => ['sometimes', 'in:active,suspended,pending'],
            
            // Company data
            'company_name' => ['required', 'string', 'max:255'],
            'registration_number' => ['required', 'string', 'unique:companies,registration_number'],
            'license_documents' => ['nullable', 'array'],
            'license_documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'portfolio_links' => ['nullable', 'array'],
            'portfolio_links.*' => ['url', 'max:500'],
            'specialization' => ['nullable', 'array'],
            'specialization.*' => ['string', 'max:100'],
            'consultation_fee' => ['nullable', 'numeric', 'min:0'],
            'company_status' => ['sometimes', 'in:pending,approved,rejected,suspended'],
        ]);

        try {
            // Create user first
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'role' => User::ROLE_COMPANY,
                'status' => $validated['user_status'] ?? User::STATUS_ACTIVE,
            ]);

            // Handle file uploads to Cloudinary
            $licenseDocuments = [];
            if ($request->hasFile('license_documents')) {
                foreach ($request->file('license_documents') as $file) {
                    try {
                        $folder = "engineering-hub/companies/licenses";
                        $result = $this->uploadService->uploadFile($file, $folder);
                        $licenseDocuments[] = $result['url'];
                    } catch (\Exception $e) {
                        Log::error('Failed to upload license document: ' . $e->getMessage());
                        // Continue without this document
                    }
                }
            }

            // Create company
            $company = Company::create([
                'user_id' => $user->id,
                'company_name' => $validated['company_name'],
                'registration_number' => $validated['registration_number'],
                'license_documents' => !empty($licenseDocuments) ? $licenseDocuments : null,
                'portfolio_links' => $validated['portfolio_links'] ?? null,
                'specialization' => $validated['specialization'] ?? null,
                'consultation_fee' => $validated['consultation_fee'] ?? null,
                'status' => $validated['company_status'] ?? Company::STATUS_APPROVED,
                'verified_at' => ($validated['company_status'] ?? Company::STATUS_APPROVED) === Company::STATUS_APPROVED ? now() : null,
            ]);

            // If company is approved, ensure user is active
            if ($company->status === Company::STATUS_APPROVED && $user->status !== User::STATUS_ACTIVE) {
                $user->update(['status' => User::STATUS_ACTIVE]);
            }

            // Log audit action
            app(AuditLogService::class)->logCompanyAction('created', $company->id, [
                'created_by' => auth()->id(),
                'company_name' => $company->company_name,
            ]);

            return $this->createdResponse(
                $company->load('user'),
                'Company created successfully.'
            );
        } catch (\Exception $e) {
            Log::error('Failed to create company: ' . $e->getMessage());
            return $this->errorResponse('Failed to create company: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Approve a company
     */
    public function approve(string $id): JsonResponse
    {
        $company = Company::findOrFail($id);

        if ($company->status === Company::STATUS_APPROVED) {
            return $this->errorResponse('Company is already approved', 400);
        }

        $company->update([
            'status' => Company::STATUS_APPROVED,
            'verified_at' => now(),
        ]);

        // Activate user account
        $company->user->update([
            'status' => \App\Models\User::STATUS_ACTIVE,
        ]);

        // Log audit action
        app(AuditLogService::class)->logCompanyAction('approved', $company->id, [
            'approved_by' => auth()->id(),
        ]);

        return $this->successResponse(
            $company->load('user'),
            'Company approved successfully.'
        );
    }

    /**
     * Reject a company
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $company = Company::findOrFail($id);

        if ($company->status === Company::STATUS_REJECTED) {
            return $this->errorResponse('Company is already rejected', 400);
        }

        $company->update([
            'status' => Company::STATUS_REJECTED,
        ]);

        // Log audit action
        app(AuditLogService::class)->logCompanyAction('rejected', $company->id, [
            'rejected_by' => auth()->id(),
            'reason' => $validated['reason'] ?? null,
        ]);

        return $this->successResponse(
            $company->load('user'),
            'Company rejected successfully.'
        );
    }

    /**
     * Suspend a company
     */
    public function suspend(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $company = Company::findOrFail($id);

        if ($company->status === Company::STATUS_SUSPENDED) {
            return $this->errorResponse('Company is already suspended', 400);
        }

        $company->update([
            'status' => Company::STATUS_SUSPENDED,
        ]);

        // Suspend user account
        $company->user->update([
            'status' => \App\Models\User::STATUS_SUSPENDED,
        ]);

        // Log audit action
        app(AuditLogService::class)->logCompanyAction('suspended', $company->id, [
            'suspended_by' => auth()->id(),
            'reason' => $validated['reason'] ?? null,
        ]);

        return $this->successResponse(
            $company->load('user'),
            'Company suspended successfully.'
        );
    }
}
