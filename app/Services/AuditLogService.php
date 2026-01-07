<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/**
 * Audit Logging Service
 * 
 * Centralized service for logging all critical actions
 */
class AuditLogService
{
    /**
     * Log a milestone action
     */
    public function logMilestoneAction(string $action, string $milestoneId, ?array $metadata = null): AuditLog
    {
        return AuditLog::log(
            "milestone.{$action}",
            'milestone',
            $milestoneId,
            Auth::id(),
            $metadata
        );
    }

    /**
     * Log an escrow action
     */
    public function logEscrowAction(string $action, string $escrowId, ?array $metadata = null): AuditLog
    {
        return AuditLog::log(
            "escrow.{$action}",
            'escrow',
            $escrowId,
            Auth::id(),
            $metadata
        );
    }

    /**
     * Log a project action
     */
    public function logProjectAction(string $action, string $projectId, ?array $metadata = null): AuditLog
    {
        return AuditLog::log(
            "project.{$action}",
            'project',
            $projectId,
            Auth::id(),
            $metadata
        );
    }

    /**
     * Log a company action
     */
    public function logCompanyAction(string $action, string $companyId, ?array $metadata = null): AuditLog
    {
        return AuditLog::log(
            "company.{$action}",
            'company',
            $companyId,
            Auth::id(),
            $metadata
        );
    }

    /**
     * Log a dispute action
     */
    public function logDisputeAction(string $action, string $disputeId, ?array $metadata = null): AuditLog
    {
        return AuditLog::log(
            "dispute.{$action}",
            'dispute',
            $disputeId,
            Auth::id(),
            $metadata
        );
    }

    /**
     * Log a generic action
     */
    public function log(string $action, string $entityType, ?string $entityId = null, ?array $metadata = null): AuditLog
    {
        return AuditLog::log(
            $action,
            $entityType,
            $entityId,
            Auth::id(),
            $metadata
        );
    }
}

