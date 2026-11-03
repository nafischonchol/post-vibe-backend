<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Profile\UpdateProfileRequest;
use App\Services\Admin\ProfileService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(public ProfileService $profileService) {}

    public function show(Request $request)
    {
        return $this->profileService->show($request);
    }

    public function update(UpdateProfileRequest $request)
    {
        return $this->profileService->update($request);
    }
}
