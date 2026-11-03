<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\PublicComboService;
use Illuminate\Http\Request;

class PublicComboController extends Controller
{
    public function __construct(
        protected PublicComboService $comboService
    ) {}

    public function index(Request $request)
    {
        try {
            return $this->comboService->index($request);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch combos: '.$th->getMessage(), 500, $th);
        }
    }

    public function show($slug_url)
    {
        try {
            return $this->comboService->show($slug_url);
        } catch (\Throwable $th) {
            return responseError('Combo not found.', 404, $th);
        }
    }
}
