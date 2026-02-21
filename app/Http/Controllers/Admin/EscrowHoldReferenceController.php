<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EscrowHoldReference;
use Illuminate\Http\JsonResponse;

/**
 * Lookup central escrow hold reference by hold_ref.
 * Returns client (payer), company (payee), project, milestone, and Paystack refs.
 */
class EscrowHoldReferenceController extends Controller
{
    public function show(string $holdRef): JsonResponse
    {
        $ref = EscrowHoldReference::with([
            'client:id,name,email',
            'company:id,company_name',
            'project:id,title,client_id,company_id',
            'milestone:id,project_id,title,amount,status',
            'escrow:id,milestone_id,amount,status,payment_reference',
        ])->where('hold_ref', $holdRef)->first();

        if (!$ref) {
            return $this->errorResponse('Hold reference not found', 404);
        }

        return $this->successResponse([
            'hold_ref' => $ref->hold_ref,
            'status' => $ref->status,
            'paystack_charge_reference' => $ref->paystack_charge_reference,
            'paystack_transfer_reference' => $ref->paystack_transfer_reference,
            'client' => $ref->client ? [
                'id' => $ref->client->id,
                'name' => $ref->client->name,
                'email' => $ref->client->email,
            ] : null,
            'company' => $ref->company ? [
                'id' => $ref->company->id,
                'company_name' => $ref->company->company_name,
            ] : null,
            'project' => $ref->project ? [
                'id' => $ref->project->id,
                'title' => $ref->project->title,
            ] : null,
            'milestone' => $ref->milestone ? [
                'id' => $ref->milestone->id,
                'title' => $ref->milestone->title,
                'amount' => $ref->milestone->amount,
                'status' => $ref->milestone->status,
            ] : null,
            'escrow' => $ref->escrow ? [
                'id' => $ref->escrow->id,
                'amount' => $ref->escrow->amount,
                'status' => $ref->escrow->status,
                'payment_reference' => $ref->escrow->payment_reference,
            ] : null,
        ]);
    }
}
