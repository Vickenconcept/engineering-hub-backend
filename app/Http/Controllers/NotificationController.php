<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * Get user's notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $notifications = $user->notifications()
            ->latest()
            ->paginate($request->get('per_page', 20));

        // Mark as read if requested
        if ($request->boolean('mark_as_read')) {
            $user->unreadNotifications->markAsRead();
        }

        return $this->paginatedResponse($notifications, 'Notifications retrieved successfully');
    }

    /**
     * Get unread notifications count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $count = $user->unreadNotifications()->count();

        return $this->successResponse([
            'count' => $count,
        ], 'Unread notifications count retrieved successfully');
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $notification = $user->notifications()->findOrFail($id);
        $notification->markAsRead();

        return $this->successResponse($notification, 'Notification marked as read');
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        
        $user->unreadNotifications->markAsRead();

        return $this->successResponse(null, 'All notifications marked as read');
    }

    /**
     * Delete a notification
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        
        $notification = $user->notifications()->findOrFail($id);
        $notification->delete();

        return $this->successResponse(null, 'Notification deleted successfully');
    }
}
