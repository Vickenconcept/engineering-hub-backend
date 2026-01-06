<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * List audit logs with filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with('user');

        // Filter by action
        if ($request->has('action')) {
            $query->where('action', 'like', '%' . $request->action . '%');
        }

        // Filter by entity type
        if ($request->has('entity_type')) {
            $query->where('entity_type', $request->entity_type);
        }

        // Filter by entity ID
        if ($request->has('entity_id')) {
            $query->where('entity_id', $request->entity_id);
        }

        // Filter by user
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $logs = $query->latest()->paginate($request->get('per_page', 50));

        return $this->paginatedResponse($logs, 'Audit logs retrieved successfully');
    }
}
