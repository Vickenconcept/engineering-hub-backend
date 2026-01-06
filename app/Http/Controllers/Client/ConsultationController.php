<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConsultationController extends Controller
{
    /**
     * List client's consultations
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $consultations = Consultation::where('client_id', $user->id)
            ->with(['company.user', 'company'])
            ->latest()
            ->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($consultations, 'Consultations retrieved successfully');
    }

    /**
     * Show a specific consultation
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        $consultation = Consultation::where('client_id', $user->id)
            ->with(['company.user', 'company'])
            ->findOrFail($id);

        return $this->successResponse($consultation, 'Consultation retrieved successfully');
    }

    /**
     * Create a new consultation booking
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'exists:companies,id'],
            'scheduled_at' => ['required', 'date', 'after:now'],
            'duration_minutes' => ['nullable', 'integer', 'min:15', 'max:120'],
            'price' => ['required', 'numeric', 'min:0'],
        ]);

        // Verify company is approved
        $company = Company::findOrFail($validated['company_id']);
        if (!$company->isApproved()) {
            return $this->errorResponse('Company is not approved yet', 403);
        }

        $consultation = Consultation::create([
            'client_id' => $request->user()->id,
            'company_id' => $validated['company_id'],
            'scheduled_at' => $validated['scheduled_at'],
            'duration_minutes' => $validated['duration_minutes'] ?? 30,
            'price' => $validated['price'],
            'payment_status' => Consultation::PAYMENT_STATUS_PENDING,
            'status' => Consultation::STATUS_SCHEDULED,
        ]);

        return $this->createdResponse(
            $consultation->load(['company.user', 'company']),
            'Consultation booked successfully. Please proceed to payment.'
        );
    }

    /**
     * Pay for a consultation
     */
    public function pay(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        $consultation = Consultation::where('client_id', $user->id)
            ->findOrFail($id);

        if ($consultation->isPaid()) {
            return $this->errorResponse('Consultation is already paid', 400);
        }

        $validated = $request->validate([
            'payment_reference' => ['required', 'string'],
            'payment_provider' => ['required', 'in:stripe,paystack'],
        ]);

        // TODO: Verify payment with payment provider
        // For now, we'll just mark it as paid
        // In production, verify the payment reference with the provider first

        $consultation->update([
            'payment_status' => Consultation::PAYMENT_STATUS_PAID,
        ]);

        // Generate meeting link (placeholder - integrate with video service)
        $consultation->update([
            'meeting_link' => 'https://meet.example.com/' . $consultation->id,
        ]);

        return $this->successResponse(
            $consultation->load(['company.user', 'company']),
            'Payment successful. Consultation confirmed.'
        );
    }
}
