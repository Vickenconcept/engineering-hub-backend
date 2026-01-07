<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    /**
     * List verified and approved companies for clients to browse
     */
    public function index(Request $request): JsonResponse
    {
        $query = Company::with('user')
            ->where('status', Company::STATUS_APPROVED)
            ->whereNotNull('verified_at');

        // Optional: Filter by specialization
        if ($request->has('specialization')) {
            $specialization = $request->get('specialization');
            $query->whereJsonContains('specialization', $specialization);
        }

        // Optional: Search by company name
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where('company_name', 'like', "%{$search}%");
        }

        $companies = $query->latest()->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($companies, 'Verified companies retrieved successfully');
    }

    /**
     * Show a specific verified company
     */
    public function show(string $id): JsonResponse
    {
        $company = Company::with('user')
            ->where('status', Company::STATUS_APPROVED)
            ->whereNotNull('verified_at')
            ->findOrFail($id);

        return $this->successResponse($company, 'Company retrieved successfully');
    }
}

