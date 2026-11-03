<?php

namespace App\Services\Customer;

use App\Http\Resources\Customer\ComboDetailResource;
use App\Http\Resources\Customer\ComboResource;
use App\Models\Combo;
use Illuminate\Http\Request;

class PublicComboService
{
    public function index(Request $request)
    {
        $perPage = min(50, max(1, (int) $request->input('per_page', 20)));

        $combos = Combo::where('is_active', true)
            ->with(['items.product.brand', 'items.variant'])
            ->latest('id')
            ->paginate($perPage);

        return responseSuccess([
            'items' => ComboResource::collection($combos),
            'pagination' => pagination($combos),
        ]);
    }

    public function show(string $slug_url)
    {
        $id = extractIdFromSlug($slug_url);

        $combo = Combo::where('is_active', true)
            ->with([
                'items.product.brand',
                'items.variant.attributeValues.attribute',
            ])
            ->findOrFail($id);

        return responseSuccess(ComboDetailResource::make($combo));
    }
}
