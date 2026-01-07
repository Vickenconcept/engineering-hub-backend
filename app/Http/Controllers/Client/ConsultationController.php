<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Consultation\CreateConsultationRequest;
use App\Models\Consultation;
use App\Models\Company;
use App\Services\Payment\PaymentServiceInterface;
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
    public function show(Request $request, string $id): JsonResponse
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
    public function store(CreateConsultationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Verify company is approved
        $company = Company::findOrFail($validated['company_id']);
        if (!$company->isApproved()) {
            return $this->errorResponse('Company is not approved yet', 403);
        }

        // Use company's consultation fee as default if client doesn't specify or if price is 0
        $price = $validated['price'] ?? 0;
        if ($price <= 0 && $company->consultation_fee) {
            $price = $company->consultation_fee;
        }

        $consultation = Consultation::create([
            'client_id' => $request->user()->id,
            'company_id' => $validated['company_id'],
            'scheduled_at' => $validated['scheduled_at'],
            'duration_minutes' => $validated['duration_minutes'] ?? 30,
            'price' => $price,
            'payment_status' => Consultation::PAYMENT_STATUS_PENDING,
            'status' => Consultation::STATUS_SCHEDULED,
        ]);

        return $this->createdResponse(
            $consultation->load(['company.user', 'company']),
            'Consultation booked successfully. Please proceed to payment.'
        );
    }

    /**
     * Initialize payment for a consultation
     */
    public function pay(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $consultation = Consultation::where('client_id', $user->id)
            ->findOrFail($id);

        if ($consultation->isPaid()) {
            return $this->errorResponse('Consultation is already paid', 400);
        }

        $paymentService = app(PaymentServiceInterface::class);

        try {
            // Initialize payment
            $paymentData = $paymentService->initializePayment(
                amount: (float) $consultation->price,
                currency: 'NGN',
                metadata: [
                    'email' => $user->email,
                    'consultation_id' => $consultation->id,
                    'type' => 'consultation',
                    'callback_url' => config('app.url') . '/payment/callback',
                ]
            );

            return $this->successResponse([
                'payment_url' => $paymentData['authorization_url'],
                'reference' => $paymentData['reference'],
                'consultation' => $consultation,
            ], 'Payment initialized. Redirect to payment_url to complete payment.');
        } catch (\Exception $e) {
            return $this->errorResponse('Failed to initialize payment: ' . $e->getMessage(), 500);
        }
    }
}
