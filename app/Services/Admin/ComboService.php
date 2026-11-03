<?php

namespace App\Services\Admin;

use App\Jobs\GenerateComboSlugWithAiJob;
use App\Models\Combo;
use App\Models\ComboItem;
use App\Traits\UploadAble;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ComboService
{
    use UploadAble;

    public function index(Request $request)
    {
        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));
        $query = Combo::with(['items.product', 'items.variant'])
            ->latest('id');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('bangla_name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->has('is_active') && $request->input('is_active') !== '') {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $combos = $query->paginate($perPage);

        $items = $combos->map(function ($combo) {
            return [
                'id' => $combo->id,
                'name' => $combo->name,
                'bangla_name' => $combo->bangla_name,
                'slug' => $combo->slug,
                'slug_url' => $combo->slug_url,
                'combo_price' => (float) $combo->combo_price,
                'regular_price' => (float) $combo->regular_price,
                'save_amount' => (float) $combo->save_amount,
                'save_percentage' => (int) $combo->save_percentage,
                'stock' => (int) $combo->stock,
                'image' => $combo->image ? Storage::url($combo->image) : null,
                'video_url' => $combo->video_url,
                'is_active' => (bool) $combo->is_active,
                'items_count' => $combo->items->count(),
                'created_at' => $combo->created_at?->toISOString(),
            ];
        });

        return responseSuccess([
            'items' => $items,
            'pagination' => pagination($combos),
        ]);
    }

    public function store($request)
    {
        return DB::transaction(function () use ($request) {
            $data = $request->validated();
            $data['is_active'] = $request->boolean('is_active', true);
            $data['created_by_id'] = Auth::id();

            // Handle slug (user does not input slug; left null until AI job generates it)
            if (!empty($data['slug'])) {
                $data['slug'] = Str::slug($data['slug']);
            } else {
                $data['slug'] = null;
            }

            // Image upload
            if ($request->hasFile('image')) {
                $data['image'] = $this->uploadFile($request->file('image'), 'combos');
            }

            $itemsData = $data['items'] ?? [];
            unset($data['items']);

            $combo = Combo::create($data);

            foreach ($itemsData as $item) {
                $combo->items()->create([
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ]);
            }

            // Dispatch background AI slug generation
            GenerateComboSlugWithAiJob::dispatch($combo->id);

            return $combo->fresh(['items.product', 'items.variant']);
        });
    }

    public function show(Combo $combo)
    {
        $combo->load([
            'items.product.brand',
            'items.variant.attributeValues.attribute',
        ]);

        return responseSuccess($this->formatComboDetail($combo));
    }

    public function editPayload(Combo $combo)
    {
        $combo->load([
            'items.product.brand',
            'items.variant.attributeValues.attribute',
        ]);

        return responseSuccess($this->formatComboDetail($combo));
    }

    public function update($request, Combo $combo)
    {
        return DB::transaction(function () use ($request, $combo) {
            $data = $request->validated();
            $data['is_active'] = $request->boolean('is_active', true);
            $data['updated_by_id'] = Auth::id();

            if (! empty($data['slug'])) {
                $data['slug'] = Str::slug($data['slug']);
            }

            if ($request->hasFile('image')) {
                if ($combo->image) {
                    $this->deleteFile($combo->image);
                }
                $data['image'] = $this->uploadFile($request->file('image'), 'combos');
            }

            $itemsData = $data['items'] ?? [];
            unset($data['items']);

            $combo->update($data);

            // Re-sync combo items
            $combo->items()->delete();
            foreach ($itemsData as $item) {
                $combo->items()->create([
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                ]);
            }

            if (empty($combo->slug)) {
                GenerateComboSlugWithAiJob::dispatch($combo->id);
            }

            return $combo->fresh(['items.product', 'items.variant']);
        });
    }

    public function toggleStatus(Combo $combo)
    {
        $combo->is_active = ! $combo->is_active;
        $combo->updated_by_id = Auth::id();
        $combo->save();

        return responseSuccess([
            'id' => $combo->id,
            'is_active' => (bool) $combo->is_active,
        ], 'Combo status updated successfully');
    }

    public function destroy(Combo $combo)
    {
        if ($combo->image) {
            $this->deleteFile($combo->image);
        }

        $combo->delete();

        return responseSuccess(null, 'Combo deleted successfully');
    }

    private function formatComboDetail(Combo $combo): array
    {
        return [
            'id' => $combo->id,
            'name' => $combo->name,
            'bangla_name' => $combo->bangla_name,
            'slug' => $combo->slug,
            'slug_url' => $combo->slug_url,
            'short_description' => $combo->short_description,
            'description' => $combo->description,
            'bangla_description' => $combo->bangla_description,
            'combo_price' => (float) $combo->combo_price,
            'regular_price' => (float) $combo->regular_price,
            'save_amount' => (float) $combo->save_amount,
            'save_percentage' => (int) $combo->save_percentage,
            'stock' => (int) $combo->stock,
            'image' => $combo->image ? Storage::url($combo->image) : null,
            'video_url' => $combo->video_url,
            'is_active' => (bool) $combo->is_active,
            'products' => $combo->items->map(function ($item) {
                $variant = $item->variant;
                $product = $item->product;
                $attributes = $variant && $variant->relationLoaded('attributeValues')
                    ? $variant->attributeValues->map(fn($av) => ($av->attribute?->name ? "{$av->attribute->name}: " : '') . $av->value)->implode(', ')
                    : '';

                return [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $product?->name ?? '',
                    'brand_name' => $product?->brand?->name ?? '',
                    'product_variant_id' => $item->product_variant_id,
                    'sku' => $variant?->sku ?? '',
                    'variant_title' => $attributes ?: ($variant?->sku ?? 'Default Variant'),
                    'unit_price' => (float) ($variant?->discount_price ?? $variant?->price ?? 0),
                    'quantity' => (int) $item->quantity,
                    'line_total' => (float) (($variant?->discount_price ?? $variant?->price ?? 0) * $item->quantity),
                    'stock' => (int) ($variant?->stock ?? 0),
                    'image' => $variant?->image ? Storage::url($variant->image) : ($product?->image ? Storage::url($product->image) : null),
                ];
            }),
            'created_at' => $combo->created_at?->toISOString(),
            'updated_at' => $combo->updated_at?->toISOString(),
        ];
    }
}
