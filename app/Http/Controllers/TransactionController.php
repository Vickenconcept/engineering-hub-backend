<?php

namespace App\Http\Controllers;

use App\Helpers\MoneyFormatter;
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
                'milestone.project.company.user',
                'holdReference',
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
                        'amount' => MoneyFormatter::format($escrow->amount),
                        'status' => 'success',
                        'payment_reference' => $escrow->payment_reference,
                        'hold_ref' => $escrow->holdReference?->hold_ref,
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

                    // Calculate amounts
                    $totalAmount = (float) $escrow->amount;
                    $platformFee = (float) ($escrow->platform_fee ?? 0);
                    $netAmount = (float) ($escrow->net_amount ?? ($totalAmount - $platformFee));

                    // Get transfer references
                    $companyTransferRef = $releaseLog && isset($releaseLog->metadata['company_transfer_reference']) 
                        ? $releaseLog->metadata['company_transfer_reference'] 
                        : ($releaseLog && isset($releaseLog->metadata['transfer_reference']) 
                            ? $releaseLog->metadata['transfer_reference'] 
                            : null);

                    $platformFeeTransferRef = $releaseLog && isset($releaseLog->metadata['platform_fee_transfer_reference']) 
                        ? $releaseLog->metadata['platform_fee_transfer_reference'] 
                        : null;

                    // COMPANY: Shows net amount they received (after platform fee)
                    if ($user->isAdmin() || ($user->isCompany() && $project->company_id === ($user->company->id ?? null))) {
                        $transactions->push([
                            'id' => $escrow->id . '_release',
                            'type' => 'escrow_release',
                            'amount' => MoneyFormatter::format($netAmount),
                            'status' => 'success',
                            'payment_reference' => $companyTransferRef,
                            'hold_ref' => $escrow->holdReference?->hold_ref,
                            'description' => "Escrow released for milestone: {$escrow->milestone->title}" . ($platformFee > 0 ? " (₦" . MoneyFormatter::format($platformFee) . " platform fee deducted)" : ''),
                            'entity_type' => 'escrow',
                            'entity_id' => $escrow->id,
                            'total_amount' => MoneyFormatter::format($totalAmount),
                            'platform_fee' => MoneyFormatter::format($platformFee),
                            'net_amount' => MoneyFormatter::format($netAmount),
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

                    // CLIENT: Shows full amount (they paid it, then it was released)
                    if ($user->isAdmin() || ($user->isClient() && $project->client_id === $user->id)) {
                        $transactions->push([
                            'id' => $escrow->id . '_release_client',
                            'type' => 'escrow_release',
                            'amount' => MoneyFormatter::format($totalAmount),
                            'status' => 'success',
                            'payment_reference' => $companyTransferRef,
                            'hold_ref' => $escrow->holdReference?->hold_ref,
                            'description' => "Escrow released to company for milestone: {$escrow->milestone->title}",
                            'entity_type' => 'escrow',
                            'entity_id' => $escrow->id,
                            'total_amount' => MoneyFormatter::format($totalAmount),
                            'platform_fee' => MoneyFormatter::format($platformFee),
                            'net_amount' => MoneyFormatter::format($netAmount),
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

                    // ADMIN: Shows platform fee they received
                    if ($user->isAdmin() && $platformFee > 0) {
                        $transactions->push([
                            'id' => $escrow->id . '_platform_fee',
                            'type' => 'platform_fee',
                            'amount' => MoneyFormatter::format($platformFee),
                            'status' => 'success',
                            'payment_reference' => $platformFeeTransferRef,
                            'hold_ref' => $escrow->holdReference?->hold_ref,
                            'description' => "Platform fee from escrow release" . ($project->company ? ": {$project->company->company_name}" : '') . ($project->client ? " (Client: {$project->client->name})" : '') . " - Milestone: {$escrow->milestone->title}",
                            'entity_type' => 'escrow',
                            'entity_id' => $escrow->id,
                            'total_amount' => MoneyFormatter::format($totalAmount),
                            'platform_fee' => MoneyFormatter::format($platformFee),
                            'net_amount' => MoneyFormatter::format($netAmount),
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
                            'amount' => MoneyFormatter::format($escrow->amount),
                            'status' => 'success',
                            'payment_reference' => $refundLog && isset($refundLog->metadata['refund_reference']) 
                                ? $refundLog->metadata['refund_reference'] 
                                : ($escrow->payment_reference ?? null),
                            'hold_ref' => $escrow->holdReference?->hold_ref,
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
                            'amount' => MoneyFormatter::format($escrow->amount),
                            'status' => 'success',
                            'payment_reference' => $refundLog && isset($refundLog->metadata['refund_reference']) 
                                ? $refundLog->metadata['refund_reference'] 
                                : ($escrow->payment_reference ?? null),
                            'hold_ref' => $escrow->holdReference?->hold_ref,
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

                $totalAmount = (float) $consultation->price;
                $platformFee = (float) ($consultation->platform_fee ?? 0);
                $netAmount = (float) ($consultation->net_amount ?? ($totalAmount - $platformFee));

                // Get transfer references and status
                $companyTransferLog = AuditLog::where('entity_type', 'consultation')
                    ->where('entity_id', $consultation->id)
                    ->where('action', 'consultation.company.transferred')
                    ->orderBy('created_at', 'desc')
                    ->first();

                $platformFeeTransferLog = AuditLog::where('entity_type', 'consultation')
                    ->where('entity_id', $consultation->id)
                    ->where('action', 'consultation.platform.fee.transferred')
                    ->orderBy('created_at', 'desc')
                    ->first();

                // Get transfer status from payment log
                $companyTransferStatus = $paymentLog && isset($paymentLog->metadata['company_transfer_status']) 
                    ? $paymentLog->metadata['company_transfer_status'] 
                    : ($companyTransferLog ? 'transferred' : 'pending');
                
                $platformFeeTransferStatus = $paymentLog && isset($paymentLog->metadata['platform_fee_transfer_status']) 
                    ? $paymentLog->metadata['platform_fee_transfer_status'] 
                    : ($platformFeeTransferLog ? 'transferred' : 'pending');

                // CLIENT: Shows full amount they paid
                if ($user->isAdmin() || ($user->isClient() && $consultation->client_id === $user->id)) {
                    $transactions->push([
                        'id' => $consultation->id . '_payment_client',
                        'type' => 'consultation_payment',
                        'amount' => MoneyFormatter::format($totalAmount),
                        'status' => 'success',
                        'payment_reference' => $paymentLog && isset($paymentLog->metadata['payment_reference']) 
                            ? $paymentLog->metadata['payment_reference'] 
                            : null,
                        'description' => "Consultation payment" . ($consultation->company ? ": {$consultation->company->company_name}" : ''),
                        'entity_type' => 'consultation',
                        'entity_id' => $consultation->id,
                        'platform_fee' => MoneyFormatter::format($platformFee),
                        'net_amount' => MoneyFormatter::format($netAmount),
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

                // COMPANY: Shows net amount they received (after platform fee)
                if ($user->isAdmin() || ($user->isCompany() && $consultation->company_id === ($user->company->id ?? null))) {
                    // Determine status based on transfer
                    $companyTransactionStatus = ($companyTransferStatus === 'transferred') ? 'success' : 'pending';
                    $companyDescription = "Consultation payment received" . ($consultation->client ? " from {$consultation->client->name}" : '') . ($platformFee > 0 ? " (₦" . MoneyFormatter::format($platformFee) . " platform fee deducted)" : '');
                    
                    // Add warning if money is in Paystack balance
                    if ($companyTransferStatus !== 'transferred') {
                        $companyDescription .= " - ⚠️ Money held in Paystack balance (no payment account connected)";
                    }
                    
                    $transactions->push([
                        'id' => $consultation->id . '_payment_company',
                        'type' => 'consultation_payment',
                        'amount' => MoneyFormatter::format($netAmount),
                        'status' => $companyTransactionStatus,
                        'payment_reference' => $companyTransferLog && isset($companyTransferLog->metadata['transfer_reference']) 
                            ? $companyTransferLog->metadata['transfer_reference'] 
                            : null,
                        'description' => $companyDescription,
                        'entity_type' => 'consultation',
                        'entity_id' => $consultation->id,
                        'total_amount' => MoneyFormatter::format($totalAmount),
                        'platform_fee' => MoneyFormatter::format($platformFee),
                        'net_amount' => MoneyFormatter::format($netAmount),
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
                        'created_at' => $companyTransferLog 
                            ? $companyTransferLog->created_at->toDateTimeString() 
                            : ($paymentLog ? $paymentLog->created_at->toDateTimeString() : ($consultation->updated_at ? $consultation->updated_at->toDateTimeString() : $consultation->created_at->toDateTimeString())),
                        'updated_at' => $consultation->updated_at ? $consultation->updated_at->toDateTimeString() : $consultation->created_at->toDateTimeString(),
                    ]);
                }

                // ADMIN: Shows platform fee they received
                if ($user->isAdmin() && $platformFee > 0) {
                    // Determine status based on transfer
                    $platformFeeTransactionStatus = ($platformFeeTransferStatus === 'transferred') ? 'success' : 'pending';
                    $platformFeeDescription = "Platform fee from consultation" . ($consultation->company ? ": {$consultation->company->company_name}" : '') . ($consultation->client ? " (Client: {$consultation->client->name})" : '');
                    
                    // Add warning if money is in Paystack balance
                    if ($platformFeeTransferStatus !== 'transferred') {
                        $platformFeeDescription .= " - ⚠️ Money held in Paystack balance (no payment account connected)";
                    }
                    
                    $transactions->push([
                        'id' => $consultation->id . '_platform_fee',
                        'type' => 'platform_fee',
                        'amount' => MoneyFormatter::format($platformFee),
                        'status' => $platformFeeTransactionStatus,
                        'payment_reference' => $platformFeeTransferLog && isset($platformFeeTransferLog->metadata['transfer_reference']) 
                            ? $platformFeeTransferLog->metadata['transfer_reference'] 
                            : null,
                        'description' => $platformFeeDescription,
                        'entity_type' => 'consultation',
                        'entity_id' => $consultation->id,
                        'total_amount' => MoneyFormatter::format($totalAmount),
                        'platform_fee' => MoneyFormatter::format($platformFee),
                        'net_amount' => MoneyFormatter::format($netAmount),
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
                        'created_at' => $platformFeeTransferLog 
                            ? $platformFeeTransferLog->created_at->toDateTimeString() 
                            : ($paymentLog ? $paymentLog->created_at->toDateTimeString() : ($consultation->updated_at ? $consultation->updated_at->toDateTimeString() : $consultation->created_at->toDateTimeString())),
                        'updated_at' => $consultation->updated_at ? $consultation->updated_at->toDateTimeString() : $consultation->created_at->toDateTimeString(),
                    ]);
                }
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
