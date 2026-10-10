<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Expense;
use App\Services\Finance\FinanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExpenseController extends Controller
{
    public function __construct(
        protected FinanceService $financeService,
    ) {}

    /**
     * List expenses for the active tenant with filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $query = Expense::with(['account', 'outlet', 'recorder'])
            ->where('tenant_id', $tenantId);

        if ($request->filled('outlet_id')) {
            $outletId = $request->query('outlet_id');
            if (! $tenantContext->hasOutletAccess($outletId)) {
                return ApiResponse::error(
                    message: 'User is not authorized to access this outlet.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: Response::HTTP_FORBIDDEN
                );
            }
            $query->where('outlet_id', $outletId);
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assigned = $tenantContext->membership()->assigned_outlet_ids ?? [];
            $query->whereIn('outlet_id', $assigned);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('start_date')) {
            $query->whereDate('expense_date', '>=', $request->query('start_date'));
        }

        if ($request->filled('end_date')) {
            $query->whereDate('expense_date', '<=', $request->query('end_date'));
        }

        $query->orderBy('expense_date', 'desc')->orderBy('created_at', 'desc');

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginator = $query->paginate($perPage);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->getCollection()->map(fn (Expense $e) => $this->formatExpense($e))->all()
        );
    }

    /**
     * Create an operational expense record.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'outlet_id' => ['nullable', 'uuid', 'exists:outlets,id'],
            'account_id' => ['nullable', 'uuid', 'exists:accounts,id'],
            'category' => ['required', 'string', 'max:100'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'expense_date' => ['required', 'date', 'before_or_equal:today'],
            'payment_method' => ['required', 'string', 'in:cash,bank'],
            'description' => ['nullable', 'string', 'max:500'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:draft,posted'],
        ]);

        if (! empty($validated['outlet_id'])) {
            if (! $tenantContext->hasOutletAccess($validated['outlet_id'])) {
                return ApiResponse::error(
                    message: 'User is not authorized to access this outlet.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: Response::HTTP_FORBIDDEN
                );
            }
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assigned = $tenantContext->membership()->assigned_outlet_ids ?? [];
            if (empty($assigned)) {
                return ApiResponse::error(
                    message: 'User is not authorized to create expenses without assigned outlet.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: Response::HTTP_FORBIDDEN
                );
            }
            $validated['outlet_id'] = $assigned[0];
        }

        $userId = auth()->id();
        $expense = $this->financeService->createExpense($tenantId, $validated, $userId);

        return ApiResponse::success(
            data: $this->formatExpense($expense),
            message: 'Expense created successfully.',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * Get expense detail.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $expense = Expense::with(['account', 'outlet', 'recorder'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        if ($expense->outlet_id && ! $tenantContext->hasOutletAccess($expense->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        return ApiResponse::success(
            data: $this->formatExpense($expense)
        );
    }

    /**
     * Post a draft expense to the ledger.
     */
    public function post(Request $request, string $id): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $expense = Expense::where('tenant_id', $tenantId)->findOrFail($id);

        if ($expense->outlet_id && ! $tenantContext->hasOutletAccess($expense->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $posted = $this->financeService->postExpense($expense, auth()->id());

        return ApiResponse::success(
            data: $this->formatExpense($posted),
            message: 'Expense posted successfully.'
        );
    }

    /**
     * Reverse a posted expense.
     */
    public function reverse(Request $request, string $id): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $expense = Expense::where('tenant_id', $tenantId)->findOrFail($id);

        if ($expense->outlet_id && ! $tenantContext->hasOutletAccess($expense->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $reversed = $this->financeService->reverseExpense($expense, $validated['reason'], auth()->id());

        return ApiResponse::success(
            data: $this->formatExpense($reversed),
            message: 'Expense reversed successfully.'
        );
    }

    /**
     * Format Expense resource.
     *
     * @return array<string, mixed>
     */
    protected function formatExpense(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'tenant_id' => $expense->tenant_id,
            'outlet_id' => $expense->outlet_id,
            'outlet' => $expense->outlet ? [
                'id' => $expense->outlet->id,
                'name' => $expense->outlet->name,
            ] : null,
            'account_id' => $expense->account_id,
            'account' => $expense->account ? [
                'id' => $expense->account->id,
                'code' => $expense->account->code,
                'name' => $expense->account->name,
            ] : null,
            'category' => $expense->category,
            'amount_minor' => (int) $expense->amount_minor,
            'expense_date' => $expense->expense_date instanceof Carbon ? $expense->expense_date->toDateString() : (string) $expense->expense_date,
            'payment_method' => $expense->payment_method,
            'status' => $expense->status,
            'description' => $expense->description,
            'reference_number' => $expense->reference_number,
            'recorded_by' => $expense->recorded_by,
            'posted_at' => $expense->posted_at?->toIso8601String(),
            'reversed_at' => $expense->reversed_at?->toIso8601String(),
            'created_at' => $expense->created_at?->toIso8601String(),
            'updated_at' => $expense->updated_at?->toIso8601String(),
        ];
    }
}
