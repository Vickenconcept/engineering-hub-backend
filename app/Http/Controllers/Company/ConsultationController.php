<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsultationController extends Controller
{
    /**
     * List company's consultations
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        // Block suspended companies
        if ($company->status === \App\Models\Company::STATUS_SUSPENDED) {
            return $this->errorResponse('Your company account is suspended. Please contact support to appeal.', 403);
        }

        $consultations = Consultation::where('company_id', $company->id)
            ->with(['client'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($consultations, 'Consultations retrieved successfully');
    }

    /**
     * Show a specific consultation
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        $consultation = Consultation::where('company_id', $company->id)
            ->with(['client'])
            ->findOrFail($id);

        return $this->successResponse($consultation, 'Consultation retrieved successfully');
    }

    /**
     * Mark consultation as completed
     */
    public function complete(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $company = $user->company;
        
        if (!$company) {
            return $this->errorResponse('Company profile not found', 404);
        }

        // Block suspended companies
        if ($company->status === \App\Models\Company::STATUS_SUSPENDED) {
            return $this->errorResponse('Your company account is suspended. You cannot complete consultations.', 403);
        }

        $consultation = Consultation::where('company_id', $company->id)
            ->findOrFail($id);

        if ($consultation->status === Consultation::STATUS_COMPLETED) {
            return $this->errorResponse('Consultation is already completed', 400);
        }

        if (!$consultation->isPaid()) {
            return $this->errorResponse('Consultation must be paid before completion', 400);
        }

        $consultation->update([
            'status' => Consultation::STATUS_COMPLETED,
        ]);

        return $this->successResponse(
            $consultation->load(['client']),
            'Consultation marked as completed successfully.'
        );
    }
}
