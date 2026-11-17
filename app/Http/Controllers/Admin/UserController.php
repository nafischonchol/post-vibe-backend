<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\User\StoreUserRequest;
use App\Http\Requests\Admin\User\UpdateUserRequest;
use App\Http\Resources\AdminUserResource;
use App\Services\Admin\UserService;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function __construct(public UserService $userService) {}

    public function index(): JsonResponse
    {
        $users = $this->userService->listUsers();
        return responseSuccess(AdminUserResource::collection($users), 'Users fetched successfully');
    }

    public function show(string $id): JsonResponse
    {
        $user = $this->userService->findUser($id);
        return responseSuccess(AdminUserResource::make($user), 'User details fetched successfully');
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->createUser(
            $request->validated(),
            $request->boolean('is_active', true)
        );
        return responseSuccess(AdminUserResource::make($user), 'User created successfully', 201);
    }

    public function update(UpdateUserRequest $request, string $id): JsonResponse
    {
        $user = $this->userService->findUser($id);
        $updatedUser = $this->userService->updateUser(
            $user,
            $request->validated(),
            $request->has('is_active') ? $request->boolean('is_active') : null
        );
        return responseSuccess(AdminUserResource::make($updatedUser), 'User updated successfully');
    }

    public function destroy(string $id): JsonResponse
    {
        $user = $this->userService->findUser($id);
        $this->userService->deleteUser($user);
        return responseSuccess(null, 'User deleted successfully');
    }
}
