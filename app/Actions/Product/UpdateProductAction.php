<?php

namespace App\Actions\Product;

use App\Http\Requests\Admin\Product\UpdateProductRequest;
use App\Jobs\ProcessProductThumbnailJob;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantAttributeValue;
use App\Traits\UploadAble;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UpdateProductAction
{
    use UploadAble;

    public function execute(Product $product, UpdateProductRequest $request): Product
    {
        return DB::transaction(function () use ($product, $request) {
            // 1. Update thumbnail file if uploaded
            if ($request->hasFile('thumbnail')) {
                if ($product->image) {
                    $this->deleteFile($product->image);
                }

                $oldPrimaryImage = $product->images()->where('is_main', true)->first();
                if ($oldPrimaryImage) {
                    $this->deleteFile($oldPrimaryImage->image_path);
                }

                $thumbnailPath = $this->uploadFile($request->file('thumbnail'), 'products/thumbnails', null, false);
                $product->image = $thumbnailPath;

                if ($oldPrimaryImage) {
                    $oldPrimaryImage->update([
                        'image_path' => $thumbnailPath,
                        'is_main' => true,
                    ]);
                } else {
                    $product->images()->create([
                        'image_path' => $thumbnailPath,
                        'is_main' => true,
                    ]);
                }

                ProcessProductThumbnailJob::dispatch($product->id, $thumbnailPath)->afterCommit();
            }

            // 2. Update SEO image file if uploaded
            if ($request->hasFile('meta_image')) {
                if ($product->meta_image) {
                    $this->deleteFile($product->meta_image);
                }
                $seoImagePath = $this->uploadFile($request->file('meta_image'), 'products/seo');
                $product->meta_image = $seoImagePath;
            }

            // 3. Update base product fields
            $youtubeUrls = $request->input('youtube_urls', []);
            $product->update([
                'name' => $request->name,
                'bangla_name' => $request->bangla_name,
                'slug' => Str::slug($request->name),
                'short_description' => $request->short_description,
                'description' => $request->description,
                'bangla_description' => $request->bangla_description,
                'category_id' => $request->category_id,
                'brand_id' => $request->brand_id,
                'unit_id' => $request->unit_id ?: null,
                'youtube_video_urls' => $youtubeUrls,
                'authenticity_video_url' => $request->authenticity_video_url,
                'weight' => $request->weight,
                'meta_title' => $request->meta_title,
                'meta_description' => $request->meta_description,
                'meta_keywords' => $request->input('meta_keywords', []),
                'status' => $request->status,
                'updated_by_id' => Auth::id(),
            ]);

            // Save the thumbnail / SEO image paths changes
            $product->save();

            // 4. Delete specified gallery images
            if ($request->has('removed_gallery_images') && is_array($request->input('removed_gallery_images'))) {
                foreach ($request->input('removed_gallery_images') as $imgId) {
                    $img = $product->images()->find($imgId);
                    if ($img && ! $img->is_main) {
                        $this->deleteFile($img->image_path);
                        $img->delete();
                    }
                }
            }

            // 5. Save new gallery images
            if ($request->hasFile('gallery_images')) {
                $this->saveGalleryImages($product, $request->file('gallery_images'));
            }

            // 6. Delete specified color variant images
            if ($request->has('removed_color_images') && is_array($request->input('removed_color_images'))) {
                foreach ($request->input('removed_color_images') as $colorName) {
                    $img = $product->images()->where('attribute_value', $colorName)->first();
                    if ($img) {
                        $this->deleteFile($img->image_path);
                        $img->delete();
                    }
                }
            }

            // 7. Save new color-specific images
            if ($request->hasFile('color_images')) {
                $this->saveColorImages($product, $request->file('color_images'));
            }

            // 8. Sync specifications (delete all and recreate)
            $product->specifications()->delete();
            if ($request->has('specifications') && is_array($request->input('specifications'))) {
                $this->saveSpecifications($product, $request->input('specifications'));
            }

            // 9. Sync variants
            $this->syncVariants($product, $request);

            return $product;
        });
    }

    /**
     * Save gallery files.
     */
    private function saveGalleryImages(Product $product, array $galleryFiles): void
    {
        foreach ($galleryFiles as $galleryFile) {
            $path = $this->uploadFile($galleryFile, 'products/gallery');
            $product->images()->create([
                'image_path' => $path,
            ]);
        }
    }

    /**
     * Save color variant images.
     */
    private function saveColorImages(Product $product, array $colorFiles): void
    {
        foreach ($colorFiles as $colorName => $colorFile) {
            // Delete old one if exists
            $oldImg = $product->images()->where('attribute_value', $colorName)->first();
            if ($oldImg) {
                $this->deleteFile($oldImg->image_path);
                $oldImg->delete();
            }

            $path = $this->uploadFile($colorFile, 'products/variants');
            $product->images()->create([
                'image_path' => $path,
                'attribute_value' => $colorName,
            ]);
        }
    }

    /**
     * Save descriptive specifications (attribute values).
     */
    private function saveSpecifications(Product $product, array $specifications): void
    {
        foreach ($specifications as $attrInput) {
            $this->saveSpecification($product, $attrInput);
        }
    }

    /**
     * Save a single descriptive specification.
     */
    private function saveSpecification(Product $product, array $attrInput): void
    {
        if (! isset($attrInput['id']) || ! isset($attrInput['value'])) {
            return;
        }

        $attributeId = $attrInput['id'];
        $value = $attrInput['value'];

        $attributeObj = Attribute::find($attributeId);
        if (! $attributeObj) {
            return;
        }

        if ($attributeObj->type === Attribute::TYPE['TEXT'] || $attributeObj->type === Attribute::TYPE['RICH_TEXT']) {
            $this->saveTextSpecification($product, $attributeId, $value);
        } else {
            $this->savePredefinedSpecification($product, $attributeId, $value);
        }
    }

    private function saveTextSpecification(Product $product, int $attributeId, mixed $value): void
    {
        $product->specifications()->create([
            'attribute_id' => $attributeId,
            'custom_value' => is_array($value) ? implode(', ', $value) : $value,
        ]);
    }

    private function savePredefinedSpecification(Product $product, int $attributeId, mixed $value): void
    {
        $specification = $product->specifications()->create([
            'attribute_id' => $attributeId,
            'custom_value' => null,
        ]);

        $valuesArray = is_array($value) ? $value : [$value];
        foreach ($valuesArray as $valString) {
            if (is_null($valString) || $valString === '') {
                continue;
            }

            $attrVal = AttributeValue::withTrashed()->firstOrCreate([
                'attribute_id' => $attributeId,
                'value' => $valString,
            ]);
            if ($attrVal->trashed()) {
                $attrVal->restore();
            }

            $specification->predefinedValues()->attach($attrVal->id);
        }
    }

    private function syncVariants(Product $product, UpdateProductRequest $request): void
    {
        $variantsInput = $request->input('variants', []);
        $existingVariants = $product->variants;
        $submittedIds = [];

        // Single variant shortcut: update in-place (SKU change handled too)
        if ($existingVariants->count() === 1 && count($variantsInput) === 1) {
            $vInput = $variantsInput[0];
            $variant = $existingVariants->first();
            $barcode = (! isset($vInput['barcode']) || is_null($vInput['barcode']) || trim((string) $vInput['barcode']) === '')
                ? ($variant->barcode ?: $this->generateUniqueBarcode())
                : trim((string) $vInput['barcode']);

            $variant->update([
                'sku' => $vInput['sku'] ?? $variant->sku,
                'price' => $vInput['price'] ?? 0.00,
                'discount_price' => $vInput['discount_price'] ?? null,
                'purchase_price' => $vInput['purchase_price'] ?? null,
                'barcode' => $barcode,
            ]);

            return;
        }

        // Multi-variant: match by ID only (no SKU fallback)
        foreach ($variantsInput as $vInput) {
            $isActive = isset($vInput['is_active'])
                ? filter_var($vInput['is_active'], FILTER_VALIDATE_BOOLEAN)
                : ((isset($vInput['status']) && $vInput['status'] === 'inactive') ? false : true);

            if (isset($vInput['id'])) {
                $variant = $product->variants()->withTrashed()->find($vInput['id']);
                if ($variant) {
                    if ($variant->trashed()) {
                        $variant->restore();
                    }
                    $barcode = (! isset($vInput['barcode']) || is_null($vInput['barcode']) || trim((string) $vInput['barcode']) === '')
                        ? ($variant->barcode ?: $this->generateUniqueBarcode())
                        : trim((string) $vInput['barcode']);

                    $variant->update([
                        'sku' => $vInput['sku'] ?? $variant->sku,
                        'price' => $vInput['price'] ?? 0.00,
                        'discount_price' => $vInput['discount_price'] ?? null,
                        'purchase_price' => $vInput['purchase_price'] ?? null,
                        'barcode' => $barcode,
                        'is_active' => $isActive,
                    ]);
                    $submittedIds[] = $variant->id;

                    continue;
                }
            }

            // No ID or ID not found → create new
            $barcode = (! isset($vInput['barcode']) || is_null($vInput['barcode']) || trim((string) $vInput['barcode']) === '')
                ? $this->generateUniqueBarcode()
                : trim((string) $vInput['barcode']);

            $variant = $product->variants()->create([
                'sku' => $vInput['sku'] ?? $this->generateVariantSku($product),
                'price' => $vInput['price'] ?? 0.00,
                'discount_price' => $vInput['discount_price'] ?? null,
                'purchase_price' => $vInput['purchase_price'] ?? null,
                'barcode' => $barcode,
                'is_active' => $isActive,
                'stock' => 0,
            ]);
            $submittedIds[] = $variant->id;

            if (isset($vInput['options']) && is_array($vInput['options']) && count($vInput['options']) > 0) {
                $this->linkVariantOptions($variant, $vInput['options']);
            }
        }

        // Soft-delete variants not in the submitted list
        $product->variants()->whereNotIn('id', $submittedIds)->each(fn ($v) => $v->delete());
    }

    /**
     * Generate a unique SKU for a variant.
     */
    private function generateVariantSku(Product $product, string $suffix = ''): string
    {
        $suffix = $suffix ?: uniqid();

        return Str::slug($product->name).'-'.$suffix.'-'.uniqid();
    }

    /**
     * Generate a unique barcode for a variant.
     */
    private function generateUniqueBarcode(): string
    {
        do {
            $barcode = (string) mt_rand(100000000000, 999999999999);
        } while (ProductVariant::where('barcode', $barcode)->exists());

        return $barcode;
    }

    /**
     * Link attribute value options to a product variant.
     */
    private function linkVariantOptions(ProductVariant $variant, array $options): void
    {
        foreach ($options as $option) {
            $attributeId = $option['attribute_id'];
            $valString = trim($option['value']);

            if ($valString === '') {
                continue;
            }

            $attrVal = AttributeValue::withTrashed()->firstOrCreate([
                'attribute_id' => $attributeId,
                'value' => $valString,
            ]);
            if ($attrVal->trashed()) {
                $attrVal->restore();
            }

            ProductVariantAttributeValue::create([
                'product_variant_id' => $variant->id,
                'attribute_id' => $attributeId,
                'attribute_value_id' => $attrVal->id,
            ]);
        }
    }
}
