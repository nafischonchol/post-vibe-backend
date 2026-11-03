<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\StoreUserRequest;
use App\Http\Requests\Admin\User\UpdateUserRequest;
use App\Services\Admin\UserService;

class UserController extends Controller
{
    public function __construct(public UserService $userService) {}

    public function index()
    {
        return $this->userService->index();
    }

    public function show(string $id)
    {
        return $this->userService->show($id);
    }

    public function store(StoreUserRequest $request)
    {
        return $this->userService->store($request);
    }

    public function update(UpdateUserRequest $request, string $id)
    {
        return $this->userService->update($id, $request);
    }

    public function destroy(string $id)
    {
        return $this->userService->destroy($id);
    }
}
