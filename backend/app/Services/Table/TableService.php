<?php

namespace App\Services\Table;

use App\Domain\Tenant\TenantManager;
use App\Exceptions\TableUnavailableException;
use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TableService
{
    public function __construct(
        protected TenantManager $tenantManager
    ) {}

    /**
     * List tables for an outlet or tenant with filtering.
     */
    public function listTables(string $tenantId, ?string $outletId = null, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $outletId, $filters, $perPage) {
            $query = Table::where('tenant_id', $tenantId)->with(['outlet', 'activeSession']);

            if ($outletId) {
                $query->where('outlet_id', $outletId);
            } elseif (! empty($filters['assigned_outlet_ids'])) {
                $query->whereIn('outlet_id', $filters['assigned_outlet_ids']);
            }

            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }

            if (isset($filters['is_active'])) {
                $query->where('is_active', (bool) $filters['is_active']);
            }

            if (! empty($filters['search'])) {
                $search = $filters['search'];
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('code', 'ilike', "%{$search}%");
                });
            }

            return $query->orderBy('code')->paginate($perPage);
        });
    }

    /**
     * Create a new physical table for an outlet.
     */
    public function createTable(Tenant|string $tenant, string $outletId, array $data, ?string $actorUserId = null): Table
    {
        $tenantId = $tenant instanceof Tenant ? (string) $tenant->id : $tenant;

        return $this->tenantManager->runForTenant($tenantId, function () use ($tenantId, $outletId, $data, $actorUserId) {
            return DB::transaction(function () use ($tenantId, $outletId, $data, $actorUserId) {
                // Ensure outlet belongs to tenant
                $outlet = Outlet::where('tenant_id', $tenantId)->findOrFail($outletId);

                // Check code uniqueness per outlet
                $code = trim($data['code']);
                $exists = Table::where('tenant_id', $tenantId)
                    ->where('outlet_id', $outlet->id)
                    ->where('code', $code)
                    ->exists();

                if ($exists) {
                    throw new \InvalidArgumentException("Table with code '{$code}' already exists in outlet '{$outlet->name}'.");
                }

                $rawToken = bin2hex(random_bytes(32));
                $table = Table::create([
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outlet->id,
                    'code' => $code,
                    'name' => trim($data['name']),
                    'capacity' => (int) ($data['capacity'] ?? 4),
                    'status' => $data['status'] ?? Table::STATUS_AVAILABLE,
                    'qr_token_hash' => Table::hashToken($rawToken),
                    'qr_token_generated_at' => now(),
                    'is_active' => $data['is_active'] ?? true,
                    'metadata' => $data['metadata'] ?? null,
                ]);
                $table->raw_token = $rawToken;

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $actorUserId,
                    'action' => 'table_created',
                    'entity_type' => Table::class,
                    'entity_id' => $table->id,
                    'after' => $table->toArray(),
                    'created_at' => now(),
                ]);

                return $table;
            });
        });
    }

    /**
     * Update table details and status.
     */
    public function updateTable(Table $table, array $data, ?string $actorUserId = null): Table
    {
        return $this->tenantManager->runForTenant($table->tenant_id, function () use ($table, $data, $actorUserId) {
            return DB::transaction(function () use ($table, $data, $actorUserId) {
                $before = $table->toArray();

                if (isset($data['code']) && $data['code'] !== $table->code) {
                    $exists = Table::where('tenant_id', $table->tenant_id)
                        ->where('outlet_id', $table->outlet_id)
                        ->where('code', $data['code'])
                        ->where('id', '!=', $table->id)
                        ->exists();

                    if ($exists) {
                        throw new \InvalidArgumentException("Table code '{$data['code']}' is already in use.");
                    }
                    $table->code = trim($data['code']);
                }

                if (isset($data['name'])) {
                    $table->name = trim($data['name']);
                }
                if (isset($data['capacity'])) {
                    $table->capacity = (int) $data['capacity'];
                }
                if (isset($data['status'])) {
                    $table->status = $data['status'];
                }
                if (isset($data['is_active'])) {
                    $table->is_active = (bool) $data['is_active'];
                }
                if (isset($data['metadata'])) {
                    $table->metadata = $data['metadata'];
                }

                $table->save();

                AuditLog::create([
                    'tenant_id' => $table->tenant_id,
                    'actor_user_id' => $actorUserId,
                    'action' => 'table_updated',
                    'entity_type' => Table::class,
                    'entity_id' => $table->id,
                    'before' => $before,
                    'after' => $table->toArray(),
                    'created_at' => now(),
                ]);

                return $table;
            });
        });
    }

    /**
     * Rotates table QR token securely.
     */
    public function rotateQrToken(Table $table, ?string $actorUserId = null): string
    {
        return $this->tenantManager->runForTenant($table->tenant_id, function () use ($table, $actorUserId) {
            $newToken = $table->generateQrToken();

            AuditLog::create([
                'tenant_id' => $table->tenant_id,
                'actor_user_id' => $actorUserId,
                'action' => 'table_qr_rotated',
                'entity_type' => Table::class,
                'entity_id' => $table->id,
                'created_at' => now(),
            ]);

            return $newToken;
        });
    }

    /**
     * Revokes table QR token.
     */
    public function revokeQrToken(Table $table, ?string $actorUserId = null): void
    {
        $this->tenantManager->runForTenant($table->tenant_id, function () use ($table, $actorUserId) {
            $table->revokeQrToken();

            AuditLog::create([
                'tenant_id' => $table->tenant_id,
                'actor_user_id' => $actorUserId,
                'action' => 'table_qr_revoked',
                'entity_type' => Table::class,
                'entity_id' => $table->id,
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Start a table session (walk-in or booking arrival).
     */
    public function startSession(Table $table, ?int $guestCount = null, ?string $bookingId = null, ?string $actorUserId = null): TableSession
    {
        return $this->tenantManager->runForTenant($table->tenant_id, function () use ($table, $guestCount, $bookingId, $actorUserId) {
            try {
                return DB::transaction(function () use ($table, $guestCount, $bookingId, $actorUserId) {
                    // Lock table for update
                    $lockedTable = Table::where('id', $table->id)->lockForUpdate()->firstOrFail();

                    if (! $lockedTable->is_active) {
                        throw new TableUnavailableException("Table [{$lockedTable->code}] is inactive.");
                    }

                    // Check if active session already exists
                    $existingActive = TableSession::where('tenant_id', $lockedTable->tenant_id)
                        ->where('table_id', $lockedTable->id)
                        ->where('status', TableSession::STATUS_ACTIVE)
                        ->first();

                    if ($existingActive) {
                        return $existingActive;
                    }

                    $sessionCode = 'SESS-'.now()->format('ymd').'-'.strtoupper(Str::random(4));

                    $session = TableSession::create([
                        'tenant_id' => $lockedTable->tenant_id,
                        'outlet_id' => $lockedTable->outlet_id,
                        'table_id' => $lockedTable->id,
                        'booking_id' => $bookingId,
                        'session_code' => $sessionCode,
                        'status' => TableSession::STATUS_ACTIVE,
                        'guest_count' => $guestCount ?? $lockedTable->capacity,
                        'opened_by_user_id' => $actorUserId,
                        'started_at' => now(),
                    ]);

                    $lockedTable->status = Table::STATUS_OCCUPIED;
                    $lockedTable->save();

                    AuditLog::create([
                        'tenant_id' => $lockedTable->tenant_id,
                        'actor_user_id' => $actorUserId,
                        'action' => 'table_session_started',
                        'entity_type' => TableSession::class,
                        'entity_id' => $session->id,
                        'after' => $session->toArray(),
                        'created_at' => now(),
                    ]);

                    return $session;
                });
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23505' || str_contains($e->getMessage(), 'uq_table_sessions_active_table')) {
                    $existingActive = TableSession::where('tenant_id', $table->tenant_id)
                        ->where('table_id', $table->id)
                        ->where('status', TableSession::STATUS_ACTIVE)
                        ->first();

                    if ($existingActive) {
                        return $existingActive;
                    }
                }

                throw $e;
            }
        });
    }

    /**
     * Release an occupied table and complete its active session.
     */
    public function releaseTable(Table $table, ?string $actorUserId = null): void
    {
        $this->tenantManager->runForTenant($table->tenant_id, function () use ($table, $actorUserId) {
            DB::transaction(function () use ($table, $actorUserId) {
                $lockedTable = Table::where('id', $table->id)->lockForUpdate()->firstOrFail();

                $activeSessions = TableSession::where('tenant_id', $lockedTable->tenant_id)
                    ->where('table_id', $lockedTable->id)
                    ->where('status', TableSession::STATUS_ACTIVE)
                    ->get();

                foreach ($activeSessions as $session) {
                    $session->status = TableSession::STATUS_COMPLETED;
                    $session->ended_at = now();
                    $session->save();
                }

                $lockedTable->status = Table::STATUS_AVAILABLE;
                $lockedTable->save();

                AuditLog::create([
                    'tenant_id' => $lockedTable->tenant_id,
                    'actor_user_id' => $actorUserId,
                    'action' => 'table_released',
                    'entity_type' => Table::class,
                    'entity_id' => $lockedTable->id,
                    'created_at' => now(),
                ]);
            });
        });
    }
}
