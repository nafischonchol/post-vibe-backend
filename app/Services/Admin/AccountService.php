<?php

namespace App\Services\Admin;

use App\Enums\TransactionTypeEnum;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class AccountService
{
    public function __construct(public TransactionService $transactionService) {}

    public function index()
    {
        $accounts = Account::latest()->get();

        return responseSuccess(AccountResource::collection($accounts));
    }

    public function show(string $id)
    {
        $account = Account::findOrFail($id);

        return responseSuccess(AccountResource::make($account));
    }

    public function store($request)
    {
        try {
            $data = $request->validated();
            $data['is_active'] = $request->boolean('is_active', true);

            $account = Account::create($data);

            return responseSuccess(AccountResource::make($account), 'Account created successfully');
        } catch (\Exception $e) {
            return responseError('Failed to create account: '.$e->getMessage(), 500);
        }
    }

    public function update(Account $account, $request)
    {
        try {
            $data = $request->validated();
            if ($request->has('is_active')) {
                $data['is_active'] = $request->boolean('is_active');
            }

            $account->update($data);

            return responseSuccess(AccountResource::make($account), 'Account updated successfully');
        } catch (\Exception $e) {
            return responseError('Failed to update account: '.$e->getMessage(), 500);
        }
    }

    public function addFunds(Account $account, $request)
    {
        try {
            $amount = (float) $request->input('amount');
            $description = $request->input('description');

            $transaction = DB::transaction(function () use ($account, $amount, $description) {
                return $this->transactionService->deposit($account, $amount, $description);
            });

            return responseSuccess([
                'account' => AccountResource::make($account->refresh()),
                'transaction' => $transaction,
            ], 'Funds added successfully');
        } catch (\Exception $e) {
            return responseError('Failed to add funds: '.$e->getMessage(), 500);
        }
    }

    public function settleCourierPayout(Account $holdingAccount, $request)
    {
        try {
            $destAccountId = $request->input('destination_account_id');
            $disbursedAmount = (float) $request->input('disbursed_amount');
            $feeAmount = (float) ($request->input('fee_amount') ?? 0);
            $note = $request->input('note');

            $destinationAccount = Account::findOrFail($destAccountId);

            if ($holdingAccount->id === $destinationAccount->id) {
                return responseError('Destination account must be different from holding account.', 422);
            }

            $totalRequired = $disbursedAmount + $feeAmount;
            if ($holdingAccount->balance < $totalRequired) {
                return responseError('Insufficient funds in the courier holding account.', 422);
            }

            $result = DB::transaction(function () use ($holdingAccount, $destinationAccount, $disbursedAmount, $feeAmount, $note) {
                // 1. Transfer disbursed amount from Holding -> Destination Bank
                $transferResult = $this->transactionService->transfer(
                    $holdingAccount,
                    $destinationAccount,
                    $disbursedAmount,
                    $note ?: "Courier Payout Disbursement to {$destinationAccount->name}"
                );

                // 2. Record courier fee expense if any
                $feeTransaction = null;
                if ($feeAmount > 0) {
                    $feeTransaction = $this->transactionService->recordExpense(
                        $holdingAccount,
                        $feeAmount,
                        "Courier logistics fee & charges for {$holdingAccount->name}"
                    );
                }

                return [
                    'holding_account' => AccountResource::make($holdingAccount->refresh()),
                    'destination_account' => AccountResource::make($destinationAccount->refresh()),
                    'transfer' => $transferResult,
                    'fee_transaction' => $feeTransaction,
                ];
            });

            return responseSuccess($result, 'Courier payout settled successfully.');
        } catch (\Exception $e) {
            return responseError('Failed to settle courier payout: '.$e->getMessage(), 500);
        }
    }

    public function transactions($request = null)
    {
        $request = $request ?? request();
        $accountId = $request->input('account_id');
        $type = $request->input('type');
        $category = $request->input('category');
        $search = trim((string) $request->input('search', ''));
        $perPage = (int) $request->input('per_page', 15);
        if ($perPage < 1) {
            $perPage = 15;
        }

        // Subquery calculating balance_after using window function across accounts
        $sub = Transaction::query()
            ->select('transactions.*')
            ->selectRaw('accounts.balance - COALESCE(SUM(CASE WHEN transactions.type = "+" THEN transactions.amount ELSE -transactions.amount END) OVER (PARTITION BY transactions.account_id ORDER BY transactions.id ASC ROWS BETWEEN 1 FOLLOWING AND UNBOUNDED FOLLOWING), 0) as balance_after')
            ->join('accounts', 'accounts.id', '=', 'transactions.account_id');

        $query = Transaction::query()
            ->fromSub($sub, 'transactions')
            ->with([
                'account:id,name,type,account_number',
                'relatedAccount:id,name,type',
                'client:id,name,phone',
                'createdBy:id,name',
            ])
            ->latest('transactions.id');

        if ($accountId && $accountId !== 'all') {
            $query->where('transactions.account_id', $accountId);
        }

        if ($type && $type !== 'all') {
            if (in_array(strtolower($type), ['deposit', 'in', '+'])) {
                $query->where('transactions.type', '+');
            } elseif (in_array(strtolower($type), ['withdrawal', 'withdraw', 'out', '-'])) {
                $query->where('transactions.type', '-');
            }
        }

        if ($category && $category !== 'all') {
            $query->where('transactions.category', $category);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('transactions.description', 'like', "%{$search}%")
                    ->orWhere('transactions.category', 'like', "%{$search}%")
                    ->orWhere('transactions.amount', 'like', "%{$search}%")
                    ->orWhere('transactions.reference_id', 'like', "%{$search}%")
                    ->orWhereHas('account', function ($accQ) use ($search) {
                        $accQ->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('client', function ($clientQ) use ($search) {
                        $clientQ->where('name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    })
                    ->orWhereHas('relatedAccount', function ($relQ) use ($search) {
                        $relQ->where('name', 'like', "%{$search}%");
                    });
            });
        }

        $transactions = $query->paginate($perPage);

        // Summary metrics for the scope
        $summaryQuery = Transaction::query();
        if ($accountId && $accountId !== 'all') {
            $summaryQuery->where('account_id', $accountId);
        }
        $totalDeposits = (float) (clone $summaryQuery)->where('type', '+')->sum('amount');
        $totalWithdrawals = (float) (clone $summaryQuery)->where('type', '-')->sum('amount');
        $netFlow = $totalDeposits - $totalWithdrawals;
        $currentBalance = ($accountId && $accountId !== 'all')
            ? (float) (Account::where('id', $accountId)->value('balance') ?? 0)
            : (float) Account::where('is_active', true)->sum('balance');

        $items = collect($transactions->items())->map(function ($t) {
            $referenceNo = match (true) {
                $t->reference_type && str_contains($t->reference_type, 'Order') => 'ORD-'.($t->reference_id ?? $t->id),
                $t->reference_type && str_contains($t->reference_type, 'Purchase') => 'PUR-'.($t->reference_id ?? $t->id),
                $t->reference_type === 'payment' => 'PAY-'.($t->reference_id ?? $t->id),
                !empty($t->reference_id) => 'REF-'.$t->reference_id,
                default => 'TXN-'.str_pad($t->id, 5, '0', STR_PAD_LEFT),
            };

            $isDeposit = ($t->type === TransactionTypeEnum::IN || $t->type?->value === '+' || $t->type === '+');

            $description = $t->description;
            if (empty($description)) {
                $cat = $t->category?->value ?? (string) $t->category;
                if ($cat === 'deposit') {
                    $description = 'Deposit to '.($t->account?->name ?? 'account');
                } elseif ($cat === 'withdraw') {
                    $description = 'Withdrawal from '.($t->account?->name ?? 'account');
                } elseif ($cat === 'transfer') {
                    $description = 'Transfer '.($isDeposit ? 'from '.($t->relatedAccount?->name ?? 'account') : 'to '.($t->relatedAccount?->name ?? 'account'));
                } else {
                    $description = ucfirst($cat ?: 'Transaction');
                }
            }

            return [
                'id' => $t->id,
                'account_id' => (int) $t->account_id,
                'account_name' => $t->account?->name ?? 'N/A',
                'account_type' => $t->account?->type?->value ?? (string) ($t->account?->type ?? 'cash'),
                'related_account_id' => $t->related_account_id ? (int) $t->related_account_id : null,
                'related_account_name' => $t->relatedAccount?->name,
                'client_id' => $t->client_id ? (int) $t->client_id : null,
                'client_name' => $t->client?->name,
                'type' => $isDeposit ? 'deposit' : 'withdrawal',
                'raw_type' => $t->type?->value ?? (string) $t->type,
                'category' => $t->category?->value ?? (string) $t->category,
                'amount' => (float) $t->amount,
                'balance_after' => isset($t->balance_after) ? (float) $t->balance_after : (float) ($t->account?->balance ?? 0),
                'reference_no' => $referenceNo,
                'description' => $description,
                'date' => $t->date?->toISOString() ?? $t->created_at?->toISOString(),
                'created_at' => $t->created_at?->toISOString(),
                'created_by' => $t->createdBy?->name ?? 'System',
            ];
        });

        $data = [
            'items' => $items,
            'pagination' => pagination($transactions),
            'summary' => [
                'total_deposits' => $totalDeposits,
                'total_withdrawals' => $totalWithdrawals,
                'net_flow' => $netFlow,
                'current_balance' => $currentBalance,
            ],
        ];

        return responseSuccess($data, 'Account transactions retrieved successfully');
    }
}
