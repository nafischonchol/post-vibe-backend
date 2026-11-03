<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Account\AddFundsRequest;
use App\Http\Requests\Admin\Account\StoreAccountRequest;
use App\Http\Requests\Admin\Account\TransactionFilterRequest;
use App\Http\Requests\Admin\Account\UpdateAccountRequest;
use App\Models\Account;
use App\Services\Admin\AccountService;

class AccountController extends Controller
{
    public function __construct(public AccountService $accountService) {}

    public function index()
    {
        return $this->accountService->index();
    }

    public function transactions(TransactionFilterRequest $request)
    {
        return $this->accountService->transactions($request);
    }

    public function store(StoreAccountRequest $request)
    {
        return $this->accountService->store($request);
    }

    public function show(Account $account)
    {
        return $this->accountService->show($account->id);
    }

    public function update(UpdateAccountRequest $request, Account $account)
    {
        return $this->accountService->update($account, $request);
    }

    public function addFunds(AddFundsRequest $request, Account $account)
    {
        return $this->accountService->addFunds($account, $request);
    }

    public function settleCourierPayout(\App\Http\Requests\Admin\Account\SettleCourierPayoutRequest $request, Account $account)
    {
        return $this->accountService->settleCourierPayout($account, $request);
    }
}
