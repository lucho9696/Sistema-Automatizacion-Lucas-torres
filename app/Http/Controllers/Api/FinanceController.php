<?php

namespace App\Http\Controllers\Api;

use App\Domain\Finance\CashService;
use App\Domain\Finance\ExpenseService;
use App\Domain\Reports\DashboardService;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    public function __construct(private ExpenseService $expenses, private CashService $cash) {}

    public function dashboard(Request $request, DashboardService $dashboard)
    {
        Gate::authorize('reports.view');
        [$from, $to] = $this->period($request);

        return $dashboard->summary($from, $to);
    }

    public function expenseCategories()
    {
        Gate::authorize('finance.manage');

        return ExpenseCategory::orderBy('name')->get();
    }

    public function expenses(Request $request)
    {
        Gate::authorize('finance.manage');

        return Expense::with('category:id,slug,name')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('from'), fn ($q, $d) => $q->where('issued_at', '>=', $d))
            ->when($request->query('to'), fn ($q, $d) => $q->where('issued_at', '<=', $d))
            ->orderByDesc('issued_at')->orderByDesc('id')
            ->paginate(50);
    }

    public function storeExpense(Request $request)
    {
        Gate::authorize('finance.manage');
        $data = $request->validate([
            'expense_category_id' => ['required', 'exists:expense_categories,id'],
            'vendor' => ['nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['required', Rule::in(config('business.currencies'))],
            'exchange_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'issued_at' => ['required', 'date'],
            'paid_at' => ['nullable', 'date'],
            'payment_method' => ['nullable', Rule::in(config('business.payment_methods'))],
            'external_key' => ['nullable', 'string', 'max:190'],
            'notes' => ['nullable', 'string'],
        ]);

        return response()->json($this->expenses->create($data, $request->user()->id), 201);
    }

    public function payExpense(Request $request, Expense $expense)
    {
        Gate::authorize('finance.manage');
        $data = $request->validate([
            'paid_at' => ['required', 'date'],
            'payment_method' => ['nullable', Rule::in(config('business.payment_methods'))],
        ]);

        return $this->expenses->markPaid($expense, $data['paid_at'], $data['payment_method'] ?? null);
    }

    public function voidExpense(Request $request, Expense $expense)
    {
        Gate::authorize('finance.manage');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return $this->expenses->void($expense, $data['reason']);
    }

    public function cashMovements(Request $request)
    {
        Gate::authorize('finance.manage');
        [$from, $to] = $this->period($request);

        return [
            'balances' => $this->cash->balances($from, $to),
            'movements' => CashMovement::query()
                ->whereBetween('occurred_at', [$from, $to])
                ->when($request->query('currency'), fn ($q, $c) => $q->where('currency', $c))
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->paginate(100),
        ];
    }

    public function storeCashMovement(Request $request)
    {
        Gate::authorize('finance.manage');
        $data = $request->validate([
            'type' => ['required', Rule::in(CashService::MANUAL_TYPES)],
            'amount' => ['required', 'decimal:0,2', 'gt:0'],
            'currency' => ['required', Rule::in(config('business.currencies'))],
            'exchange_rate' => ['nullable', 'decimal:0,6', 'gt:0'],
            'occurred_at' => ['required', 'date'],
            'method' => ['nullable', Rule::in(config('business.payment_methods'))],
            'description' => ['required', 'string', 'max:255'],
        ]);

        return response()->json($this->cash->manual($data), 201);
    }

    public function auditLogs(Request $request)
    {
        Gate::authorize('audit.view');

        return AuditLog::with('user:id,name')
            ->when($request->query('event'), fn ($q, $e) => $q->where('event', 'like', "{$e}%"))
            ->orderByDesc('id')
            ->paginate(100);
    }

    /** @return array{0:string,1:string} */
    private function period(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $today = BusinessDate::today();
        $from = $request->query('from', Carbon::parse($today)->startOfMonth()->toDateString());
        $to = $request->query('to', $today);

        return [Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString()];
    }
}
