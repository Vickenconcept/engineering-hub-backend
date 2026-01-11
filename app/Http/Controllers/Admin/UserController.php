<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /**
     * List all users with filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // Filter by role
        if ($request->has('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search by name or email
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->latest()->paginate($request->get('per_page', 15));

        return $this->paginatedResponse($users, 'Users retrieved successfully');
    }

    /**
     * Show a specific user
     */
    public function show(string $id): JsonResponse
    {
        $user = User::with('company')->findOrFail($id);

        return $this->successResponse($user, 'User retrieved successfully');
    }

    /**
     * Create a new user (admin only)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', 'in:client,company'],
            'status' => ['sometimes', 'in:active,suspended,pending'],
        ]);

        // Prevent creating admin users (only one admin allowed)
        if (isset($validated['role']) && $validated['role'] === User::ROLE_ADMIN) {
            return $this->errorResponse('Cannot create admin users. Only one admin is allowed.', 400);
        }

        // Check if admin already exists (extra safety check)
        if ($validated['role'] === User::ROLE_ADMIN) {
            $existingAdmin = User::where('role', User::ROLE_ADMIN)->first();
            if ($existingAdmin) {
                return $this->errorResponse('Only one admin is allowed in the system', 400);
            }
        }

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'status' => $validated['status'] ?? User::STATUS_ACTIVE,
        ]);

        // Log audit action
        app(AuditLogService::class)->logUserAction('created', $user->id, [
            'created_by' => auth()->id(),
            'role' => $user->role,
        ]);

        return $this->createdResponse(
            $user->fresh(),
            'User created successfully.'
        );
    }

    /**
     * Update user data
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255', 'unique:users,email,' . $id],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['sometimes', 'required', 'in:client,company,admin'],
        ]);

        $user = User::findOrFail($id);

        // Prevent updating your own role
        if (isset($validated['role']) && $user->id === auth()->id() && $validated['role'] !== $user->role) {
            return $this->errorResponse('You cannot change your own role', 400);
        }

        // Prevent changing role to admin if there's already an admin (only one admin allowed)
        if (isset($validated['role']) && $validated['role'] === User::ROLE_ADMIN && $user->role !== User::ROLE_ADMIN) {
            $existingAdmin = User::where('role', User::ROLE_ADMIN)->where('id', '!=', $id)->first();
            if ($existingAdmin) {
                return $this->errorResponse('Only one admin is allowed in the system', 400);
            }
        }

        $user->update($validated);

        // Log audit action
        app(AuditLogService::class)->logUserAction('updated', $user->id, [
            'updated_by' => auth()->id(),
            'changes' => $validated,
        ]);

        return $this->successResponse(
            $user->fresh(),
            'User updated successfully.'
        );
    }

    /**
     * Activate a user
     */
    public function activate(string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->status === User::STATUS_ACTIVE) {
            return $this->errorResponse('User is already active', 400);
        }

        if ($user->id === auth()->id()) {
            return $this->errorResponse('You cannot change your own status', 400);
        }

        $user->update([
            'status' => User::STATUS_ACTIVE,
        ]);

        // Log audit action
        app(AuditLogService::class)->logUserAction('activated', $user->id, [
            'activated_by' => auth()->id(),
        ]);

        return $this->successResponse(
            $user->fresh(),
            'User activated successfully.'
        );
    }

    /**
     * Suspend a user
     */
    public function suspend(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user = User::findOrFail($id);

        if ($user->status === User::STATUS_SUSPENDED) {
            return $this->errorResponse('User is already suspended', 400);
        }

        if ($user->id === auth()->id()) {
            return $this->errorResponse('You cannot change your own status', 400);
        }

        $user->update([
            'status' => User::STATUS_SUSPENDED,
        ]);

        // Log audit action
        app(AuditLogService::class)->logUserAction('suspended', $user->id, [
            'suspended_by' => auth()->id(),
            'reason' => $validated['reason'] ?? null,
        ]);

        return $this->successResponse(
            $user->fresh(),
            'User suspended successfully.'
        );
    }

    /**
     * Delete a user
     */
    public function destroy(string $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return $this->errorResponse('You cannot delete your own account', 400);
        }

        // Log audit action before deletion
        app(AuditLogService::class)->logUserAction('deleted', $user->id, [
            'deleted_by' => auth()->id(),
        ]);

        $user->delete();

        return $this->successResponse(null, 'User deleted successfully.');
    }
}

