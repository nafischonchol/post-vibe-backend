<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\AttributeService;
use Illuminate\Http\Request;

class AttributeController extends Controller
{
    public function __construct(public AttributeService $attrService) {}

    public function getAttributeValues(string $slug, Request $request)
    {
        try {
            return $this->attrService->getAttributeValues($slug, $request);
        } catch (\Throwable $th) {
            return responseError('Failed to fetch attribute values: '.$th->getMessage(), 500);
        }
    }
}
