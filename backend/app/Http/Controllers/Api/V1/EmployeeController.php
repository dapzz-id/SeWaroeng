<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Outlet;
use App\Models\Role;
use App\Models\User;
use App\Models\UserTenantMembership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    /**
     * List all employees/members for the active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $query = UserTenantMembership::where('tenant_id', $tenantId)
            ->with(['user', 'role', 'permittedOutlets']);

        if ($request->filled('role')) {
            $query->whereHas('role', function ($q) use ($request) {
                $q->where('slug', $request->query('role'));
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->query('search').'%';
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'ILIKE', $search)
                    ->orWhere('email', 'ILIKE', $search)
                    ->orWhere('phone_number', 'ILIKE', $search);
            });
        }

        $memberships = $query->orderBy('created_at', 'desc')->get();

        $data = $memberships->map(fn (UserTenantMembership $m) => $this->formatMembership($m));

        return ApiResponse::success(data: $data);
    }

    /**
     * Get list of assignable system roles.
     */
    public function roles(): JsonResponse
    {
        $roles = Role::where('slug', '!=', 'platform_superadmin')
            ->with('permissions')
            ->get()
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'slug' => $r->slug,
                'description' => $r->description,
                'permissions' => $r->permissions->pluck('slug'),
            ]);

        return ApiResponse::success(data: $roles);
    }

    /**
     * Add or invite an employee to the active tenant.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:8'],
            'role_id' => ['nullable', 'uuid', 'exists:roles,id'],
            'role_slug' => ['nullable', 'string', 'exists:roles,slug'],
            'outlet_access' => ['required', Rule::in(['all', 'specific'])],
            'assigned_outlet_ids' => ['nullable', 'array'],
            'assigned_outlet_ids.*' => ['uuid', Rule::exists('outlets', 'id')->where('tenant_id', $tenantId)],
        ]);

        // Resolve role
        $role = null;
        if (! empty($validated['role_id'])) {
            $role = Role::findOrFail($validated['role_id']);
        } elseif (! empty($validated['role_slug'])) {
            $role = Role::where('slug', $validated['role_slug'])->firstOrFail();
        } else {
            $role = Role::where('slug', 'staff')->firstOrFail();
        }

        return DB::transaction(function () use ($validated, $role, $tenantId) {
            // Find or create user
            $user = User::where('email', strtolower(trim($validated['email'])))->first();

            if ($user) {
                // Check if already member
                $existing = UserTenantMembership::where('user_id', $user->id)
                    ->where('tenant_id', $tenantId)
                    ->first();

                if ($existing) {
                    return ApiResponse::error(
                        message: 'Karyawan dengan email ini sudah terdaftar dalam bisnis ini.',
                        code: 'EMPLOYEE_ALREADY_EXISTS',
                        status: 422
                    );
                }
            } else {
                $password = ! empty($validated['password'])
                    ? Hash::make($validated['password'])
                    : Hash::make(Str::random(16));

                $user = User::create([
                    'name' => $validated['name'],
                    'email' => strtolower(trim($validated['email'])),
                    'phone_number' => $validated['phone_number'] ?? null,
                    'password' => $password,
                    'is_active' => true,
                ]);
            }

            $assignedOutlets = $validated['outlet_access'] === 'specific'
                ? ($validated['assigned_outlet_ids'] ?? [])
                : [];

            $membership = UserTenantMembership::create([
                'user_id' => $user->id,
                'tenant_id' => $tenantId,
                'role_id' => $role->id,
                'status' => 'active',
                'outlet_access' => $validated['outlet_access'],
                'assigned_outlet_ids' => $assignedOutlets,
            ]);

            if ($validated['outlet_access'] === 'specific' && ! empty($assignedOutlets)) {
                $membership->permittedOutlets()->sync($assignedOutlets);
            }

            $membership->load(['user', 'role', 'permittedOutlets']);

            return ApiResponse::success(
                data: $this->formatMembership($membership),
                message: 'Karyawan berhasil ditambahkan.',
                status: 201
            );
        });
    }

    /**
     * Show single employee details.
     */
    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $membership = UserTenantMembership::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with(['user', 'role', 'permittedOutlets'])
            ->firstOrFail();

        return ApiResponse::success(data: $this->formatMembership($membership));
    }

    /**
     * Update employee role, outlets, or status.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $membership = UserTenantMembership::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with(['user', 'role'])
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'phone_number' => ['nullable', 'string', 'max:20'],
            'role_id' => ['nullable', 'uuid', 'exists:roles,id'],
            'role_slug' => ['nullable', 'string', 'exists:roles,slug'],
            'status' => ['nullable', Rule::in(['active', 'suspended', 'invited'])],
            'outlet_access' => ['nullable', Rule::in(['all', 'specific'])],
            'assigned_outlet_ids' => ['nullable', 'array'],
            'assigned_outlet_ids.*' => ['uuid', Rule::exists('outlets', 'id')->where('tenant_id', $tenantId)],
        ]);

        // Prevent disabling or changing role of the last owner
        if ($membership->role->slug === 'owner') {
            $isDemoting = (! empty($validated['role_id']) && $validated['role_id'] !== $membership->role_id)
                || (! empty($validated['role_slug']) && $validated['role_slug'] !== 'owner')
                || (isset($validated['status']) && $validated['status'] !== 'active');

            if ($isDemoting) {
                $ownerCount = UserTenantMembership::where('tenant_id', $tenantId)
                    ->whereHas('role', fn ($q) => $q->where('slug', 'owner'))
                    ->where('status', 'active')
                    ->count();

                if ($ownerCount <= 1) {
                    return ApiResponse::error(
                        message: 'Bisnis harus memiliki setidaknya satu pemilik aktif.',
                        code: 'CANNOT_DEMOTE_LAST_OWNER',
                        status: 422
                    );
                }
            }
        }

        return DB::transaction(function () use ($membership, $validated) {
            // Update user profile info
            if (! empty($validated['name']) || array_key_exists('phone_number', $validated)) {
                $userUpdates = [];
                if (! empty($validated['name'])) {
                    $userUpdates['name'] = $validated['name'];
                }
                if (array_key_exists('phone_number', $validated)) {
                    $userUpdates['phone_number'] = $validated['phone_number'];
                }
                $membership->user->update($userUpdates);
            }

            // Update role if requested
            if (! empty($validated['role_id'])) {
                $membership->role_id = $validated['role_id'];
            } elseif (! empty($validated['role_slug'])) {
                $role = Role::where('slug', $validated['role_slug'])->firstOrFail();
                $membership->role_id = $role->id;
            }

            if (! empty($validated['status'])) {
                $membership->status = $validated['status'];
            }

            if (! empty($validated['outlet_access'])) {
                $membership->outlet_access = $validated['outlet_access'];
                if ($validated['outlet_access'] === 'specific') {
                    $assignedOutlets = $validated['assigned_outlet_ids'] ?? [];
                    $membership->assigned_outlet_ids = $assignedOutlets;
                    $membership->permittedOutlets()->sync($assignedOutlets);
                } else {
                    $membership->assigned_outlet_ids = [];
                    $membership->permittedOutlets()->detach();
                }
            } elseif (isset($validated['assigned_outlet_ids']) && $membership->outlet_access === 'specific') {
                $membership->assigned_outlet_ids = $validated['assigned_outlet_ids'];
                $membership->permittedOutlets()->sync($validated['assigned_outlet_ids']);
            }

            $membership->save();
            $membership->load(['user', 'role', 'permittedOutlets']);

            return ApiResponse::success(
                data: $this->formatMembership($membership),
                message: 'Data karyawan berhasil diperbarui.'
            );
        });
    }

    /**
     * Remove employee from tenant (revoke membership).
     */
    public function destroy(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $membership = UserTenantMembership::where('tenant_id', $tenantId)
            ->where('id', $id)
            ->with('role')
            ->firstOrFail();

        if ($membership->role->slug === 'owner') {
            $ownerCount = UserTenantMembership::where('tenant_id', $tenantId)
                ->whereHas('role', fn ($q) => $q->where('slug', 'owner'))
                ->where('status', 'active')
                ->count();

            if ($ownerCount <= 1) {
                return ApiResponse::error(
                    message: 'Tidak dapat menghapus pemilik bisnis terakhir.',
                    code: 'CANNOT_REMOVE_LAST_OWNER',
                    status: 422
                );
            }
        }

        DB::transaction(function () use ($membership) {
            $membership->permittedOutlets()->detach();
            $membership->delete();
        });

        return ApiResponse::success(
            message: 'Karyawan berhasil dihapus dari bisnis ini.'
        );
    }

    /**
     * Helper formatter.
     */
    protected function formatMembership(UserTenantMembership $m): array
    {
        return [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'name' => $m->user?->name,
            'email' => $m->user?->email,
            'phone_number' => $m->user?->phone_number,
            'role' => [
                'id' => $m->role?->id,
                'name' => $m->role?->name,
                'slug' => $m->role?->slug,
            ],
            'status' => $m->status,
            'outlet_access' => $m->outlet_access,
            'assigned_outlet_ids' => $m->assigned_outlet_ids ?? [],
            'permitted_outlets' => $m->permittedOutlets->map(fn (Outlet $o) => [
                'id' => $o->id,
                'name' => $o->name,
            ]),
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
