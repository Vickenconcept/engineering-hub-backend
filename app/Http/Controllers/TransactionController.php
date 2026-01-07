<?php

namespace App\Http\Controllers;

use App\Models\Escrow;
use App\Models\Consultation;
use App\Models\AuditLog;
use App\Models\Milestone;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    /**
     * List transactions for the authenticated user (or all for admin)
     * 
     * For Clients: Shows money sent (escrow deposits, consultation payments) and money received (refunds)
     * For Companies: Shows money received (escrow releases) and money sent (if any)
     * For Admins: Shows all transactions
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 20);
        $type = $request->input('type'); // 'escrow', 'consultation', 'all'
        $status = $request->input('status'); // 'success', 'pending', 'failed', 'all'

        $transactions = collect();

        // Get escrow transactions
        if (!$type || $type === 'all' || $type === 'escrow') {
            $escrowQuery = Escrow::with([
                'milestone.project.client',
                'milestone.project.company.user'
            ]);

            // Filter by user role
            if ($user->isAdmin()) {
                // Admin sees all escrows
            } elseif ($user->isClient()) {
                // Client sees escrows for their projects (money they sent)
                $escrowQuery->whereHas('milestone.project', function ($q) use ($user) {
                    $q->where('client_id', $user->id);
                });
            } elseif ($user->isCompany()) {
                // Company sees escrows for their projects (money they receive)
                $escrowQuery->whereHas('milestone.project', function ($q) use ($user) {
                    $q->where('company_id', $user->company->id ?? null);
                });
            }

            $escrows = $escrowQuery->orderBy('created_at', 'desc')->get();

            foreach ($escrows as $escrow) {
                if (!$escrow->milestone) {
                    Log::warning('Escrow without milestone', ['escrow_id' => $escrow->id]);
                    continue;
                }

                $project = $escrow->milestone->project;
                if (!$project) {
                    Log::warning('Milestone without project', ['milestone_id' => $escrow->milestone->id]);
                    continue;
                }

                // 1. DEPOSIT TRANSACTION - Client paid for milestone escrow
                // This shows as OUTGOING for clients, not shown for companies (they didn't pay)
                if ($user->isAdmin() || ($user->isClient() && $project->client_id === $user->id)) {
                    $transactions->push([
                        'id' => $escrow->id . '_deposit',
                        'type' => 'escrow_deposit',
                        'amount' => (float) $escrow->amount,
                        'status' => 'success',
                        'payment_reference' => $escrow->payment_reference,
                        'description' => "Escrow deposit for milestone: {$escrow->milestone->title}",
                        'entity_type' => 'escrow',
                        'entity_id' => $escrow->id,
                        'milestone' => [
                            'id' => $escrow->milestone->id,
                            'title' => $escrow->milestone->title,
                        ],
                        'project' => [
                            'id' => $project->id,
                            'title' => $project->title,
                        ],
                        'client' => $project->client ? [
                            'id' => $project->client->id,
                            'name' => $project->client->name,
                        ] : null,
                        'company' => $project->company ? [
                            'id' => $project->company->id,
                            'name' => $project->company->company_name,
                        ] : null,
                        'created_at' => $escrow->created_at->toDateTimeString(),
                        'updated_at' => $escrow->updated_at->toDateTimeString(),
                    ]);
                }

                // 2. RELEASE TRANSACTION - Escrow released to company
                // This shows as INCOMING for companies, OUTGOING for clients
                if ($escrow->status === Escrow::STATUS_RELEASED) {
                    $releaseLog = AuditLog::where('entity_type', 'escrow')
                        ->where('entity_id', $escrow->id)
                        ->where('action', 'escrow.released')
                        ->orderBy('created_at', 'desc')
                        ->first();

                    // Show to company (they received money)
                    if ($user->isAdmin() || ($user->isCompany() && $project->company_id === ($user->company->id ?? null))) {
                        $transactions->push([
                            'id' => $escrow->id . '_release',
                            'type' => 'escrow_release',
                            'amount' => (float) $escrow->amount,
                            'status' => 'success',
                            'payment_reference' => $releaseLog && isset($releaseLog->metadata['transfer_reference']) 
                                ? $releaseLog->metadata['transfer_reference'] 
                                : ($escrow->payment_reference ?? null),
                            'description' => "Escrow released for milestone: {$escrow->milestone->title}",
                            'entity_type' => 'escrow',
                            'entity_id' => $escrow->id,
                            'milestone' => [
                                'id' => $escrow->milestone->id,
                                'title' => $escrow->milestone->title,
                            ],
                            'project' => [
                                'id' => $project->id,
                                'title' => $project->title,
                            ],
                            'client' => $project->client ? [
                                'id' => $project->client->id,
                                'name' => $project->client->name,
                            ] : null,
                            'company' => $project->company ? [
                                'id' => $project->company->id,
                                'name' => $project->company->company_name,
                            ] : null,
                            'created_at' => $releaseLog 
                                ? $releaseLog->created_at->toDateTimeString() 
                                : $escrow->updated_at->toDateTimeString(),
                            'updated_at' => $escrow->updated_at->toDateTimeString(),
                        ]);
                    }

                    // Also show to client (they paid, then it was released)
                    if ($user->isAdmin() || ($user->isClient() && $project->client_id === $user->id)) {
                        $transactions->push([
                            'id' => $escrow->id . '_release_client',
                            'type' => 'escrow_release',
                            'amount' => (float) $escrow->amount,
                            'status' => 'success',
                            'payment_reference' => $releaseLog && isset($releaseLog->metadata['transfer_reference']) 
                                ? $releaseLog->metadata['transfer_reference'] 
                                : ($escrow->payment_reference ?? null),
                            'description' => "Escrow released to company for milestone: {$escrow->milestone->title}",
                            'entity_type' => 'escrow',
                            'entity_id' => $escrow->id,
                            'milestone' => [
                                'id' => $escrow->milestone->id,
                                'title' => $escrow->milestone->title,
                            ],
                            'project' => [
                                'id' => $project->id,
                                'title' => $project->title,
                            ],
                            'client' => $project->client ? [
                                'id' => $project->client->id,
                                'name' => $project->client->name,
                            ] : null,
                            'company' => $project->company ? [
                                'id' => $project->company->id,
                                'name' => $project->company->company_name,
                            ] : null,
                            'created_at' => $releaseLog 
                                ? $releaseLog->created_at->toDateTimeString() 
                                : $escrow->updated_at->toDateTimeString(),
                            'updated_at' => $escrow->updated_at->toDateTimeString(),
                        ]);
                    }
                }

                // 3. REFUND TRANSACTION - Escrow refunded to client
                // This shows as INCOMING for clients, OUTGOING for companies
                if ($escrow->status === Escrow::STATUS_REFUNDED) {
                    $refundLog = AuditLog::where('entity_type', 'escrow')
                        ->where('entity_id', $escrow->id)
                        ->where('action', 'escrow.refunded')
                        ->orderBy('created_at', 'desc')
                        ->first();

                    // Show to client (they received refund)
                    if ($user->isAdmin() || ($user->isClient() && $project->client_id === $user->id)) {
                        $transactions->push([
                            'id' => $escrow->id . '_refund',
                            'type' => 'escrow_refund',
                            'amount' => (float) $escrow->amount,
                            'status' => 'success',
                            'payment_reference' => $refundLog && isset($refundLog->metadata['refund_reference']) 
                                ? $refundLog->metadata['refund_reference'] 
                                : ($escrow->payment_reference ?? null),
                            'description' => "Escrow refunded for milestone: {$escrow->milestone->title}",
                            'entity_type' => 'escrow',
                            'entity_id' => $escrow->id,
                            'milestone' => [
                                'id' => $escrow->milestone->id,
                                'title' => $escrow->milestone->title,
                            ],
                            'project' => [
                                'id' => $project->id,
                                'title' => $project->title,
                            ],
                            'client' => $project->client ? [
                                'id' => $project->client->id,
                                'name' => $project->client->name,
                            ] : null,
                            'company' => $project->company ? [
                                'id' => $project->company->id,
                                'name' => $project->company->company_name,
                            ] : null,
                            'created_at' => $refundLog 
                                ? $refundLog->created_at->toDateTimeString() 
                                : $escrow->updated_at->toDateTimeString(),
                            'updated_at' => $escrow->updated_at->toDateTimeString(),
                        ]);
                    }

                    // Also show to company (they didn't receive the money)
                    if ($user->isAdmin() || ($user->isCompany() && $project->company_id === ($user->company->id ?? null))) {
                        $transactions->push([
                            'id' => $escrow->id . '_refund_company',
                            'type' => 'escrow_refund',
                            'amount' => (float) $escrow->amount,
                            'status' => 'success',
                            'payment_reference' => $refundLog && isset($refundLog->metadata['refund_reference']) 
                                ? $refundLog->metadata['refund_reference'] 
                                : ($escrow->payment_reference ?? null),
                            'description' => "Escrow refunded to client for milestone: {$escrow->milestone->title}",
                            'entity_type' => 'escrow',
                            'entity_id' => $escrow->id,
                            'milestone' => [
                                'id' => $escrow->milestone->id,
                                'title' => $escrow->milestone->title,
                            ],
                            'project' => [
                                'id' => $project->id,
                                'title' => $project->title,
                            ],
                            'client' => $project->client ? [
                                'id' => $project->client->id,
                                'name' => $project->client->name,
                            ] : null,
                            'company' => $project->company ? [
                                'id' => $project->company->id,
                                'name' => $project->company->company_name,
                            ] : null,
                            'created_at' => $refundLog 
                                ? $refundLog->created_at->toDateTimeString() 
                                : $escrow->updated_at->toDateTimeString(),
                            'updated_at' => $escrow->updated_at->toDateTimeString(),
                        ]);
                    }
                }
            }
        }

        // Get consultation payment transactions
        if (!$type || $type === 'all' || $type === 'consultation') {
            $consultationQuery = Consultation::with(['client', 'company']);

            // Filter by user role
            if ($user->isAdmin()) {
                // Admin sees all paid consultations
            } elseif ($user->isClient()) {
                // Client sees consultations they paid for
                $consultationQuery->where('client_id', $user->id);
            } elseif ($user->isCompany()) {
                // Company sees consultations for their company (they received payment)
                $consultationQuery->where('company_id', $user->company->id ?? null);
            }

            $consultations = $consultationQuery
                ->where('payment_status', Consultation::PAYMENT_STATUS_PAID)
                ->orderBy('created_at', 'desc')
                ->get();

            foreach ($consultations as $consultation) {
                // Get payment reference from audit log
                $paymentLog = AuditLog::where('entity_type', 'consultation')
                    ->where('entity_id', $consultation->id)
                    ->where('action', 'consultation.paid')
                    ->orderBy('created_at', 'desc')
                    ->first();

                $transactions->push([
                    'id' => $consultation->id . '_payment',
                    'type' => 'consultation_payment',
                    'amount' => (float) $consultation->price,
                    'status' => 'success',
                    'payment_reference' => $paymentLog && isset($paymentLog->metadata['payment_reference']) 
                        ? $paymentLog->metadata['payment_reference'] 
                        : null,
                    'description' => "Consultation payment" . ($consultation->company ? ": {$consultation->company->company_name}" : ''),
                    'entity_type' => 'consultation',
                    'entity_id' => $consultation->id,
                    'consultation' => [
                        'id' => $consultation->id,
                        'scheduled_at' => $consultation->scheduled_at ? $consultation->scheduled_at->toDateTimeString() : null,
                    ],
                    'client' => $consultation->client ? [
                        'id' => $consultation->client->id,
                        'name' => $consultation->client->name,
                    ] : null,
                    'company' => $consultation->company ? [
                        'id' => $consultation->company->id,
                        'name' => $consultation->company->company_name,
                    ] : null,
                    'created_at' => $paymentLog 
                        ? $paymentLog->created_at->toDateTimeString() 
                        : ($consultation->updated_at ? $consultation->updated_at->toDateTimeString() : $consultation->created_at->toDateTimeString()),
                    'updated_at' => $consultation->updated_at ? $consultation->updated_at->toDateTimeString() : $consultation->created_at->toDateTimeString(),
                ]);
            }
        }

        // Filter by status if provided
        if ($status && $status !== 'all') {
            $transactions = $transactions->filter(function ($transaction) use ($status) {
                return $transaction['status'] === $status;
            });
        }

        // Sort by created_at descending
        $transactions = $transactions->sortByDesc('created_at')->values();

        // Paginate manually
        $currentPage = (int) $request->input('page', 1);
        $perPage = (int) $perPage;
        $total = $transactions->count();
        $items = $transactions->slice(($currentPage - 1) * $perPage, $perPage)->values();

        // Return data directly (not nested) to match frontend expectations
        return $this->successResponse(
            $items->toArray(), // Convert collection to array
            'Transactions retrieved successfully.',
            200,
            [
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $total > 0 ? ceil($total / $perPage) : 1,
                'from' => $total > 0 ? (($currentPage - 1) * $perPage) + 1 : 0,
                'to' => min($currentPage * $perPage, $total),
            ]
        );
    }
}
