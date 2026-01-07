<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
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
