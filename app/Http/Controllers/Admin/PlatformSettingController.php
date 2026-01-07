<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformSettingController extends Controller
{
    /**
     * Get platform fee percentage
     */
    public function getPlatformFee(): JsonResponse
    {
        $percentage = PlatformSetting::getPlatformFeePercentage();
        $setting = PlatformSetting::where('key', 'platform_fee_percentage')->first();

        return $this->successResponse([
            'percentage' => $percentage,
            'description' => $setting->description ?? null,
        ], 'Platform fee retrieved successfully');
    }

    /**
     * Update platform fee percentage
     */
    public function updatePlatformFee(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'percentage' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        // Validate percentage is within PRD range (5-8%)
        if ($validated['percentage'] < 5 || $validated['percentage'] > 8) {
            return $this->errorResponse('Platform fee must be between 5% and 8%', 400);
        }

        $setting = PlatformSetting::set(
            'platform_fee_percentage',
            (string) $validated['percentage'],
            'Platform fee percentage (5-8% of escrow amount)'
        );

        return $this->successResponse([
            'percentage' => (float) $setting->value,
            'description' => $setting->description,
        ], 'Platform fee updated successfully');
    }
}
