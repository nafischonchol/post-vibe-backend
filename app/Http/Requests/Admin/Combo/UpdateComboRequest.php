<?php

namespace App\Http\Requests\Admin\Combo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateComboRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $comboId = $this->route('combo');
        if (is_object($comboId)) {
            $comboId = $comboId->id;
        }

        return [
            'name' => 'required|string|max:255',
            'bangla_name' => 'nullable|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('combos', 'slug')->ignore($comboId),
            ],
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'bangla_description' => 'nullable|string',
            'combo_price' => 'required|numeric|min:0',
            'video_url' => 'nullable|string|max:500',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:5120',
            'is_active' => 'nullable|boolean',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
        ];
    }
}
