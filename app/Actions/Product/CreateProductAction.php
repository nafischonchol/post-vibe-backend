<?php

namespace App\Actions\Product;

use App\Http\Requests\Admin\Product\StoreProductRequest;
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

class CreateProductAction
{
    use UploadAble;

    /**
     * Execute the product creation process.
     */
    public function execute(StoreProductRequest $request): Product
    {
        return DB::transaction(function () use ($request) {
            // 1. Upload thumbnail and SEO image files
            $thumbnailPath = $request->hasFile('thumbnail')
                ? $this->uploadFile($request->file('thumbnail'), 'products/thumbnails', null, false)
                : null;

            $seoImagePath = $request->hasFile('meta_image')
                ? $this->uploadFile($request->file('meta_image'), 'products/seo')
                : null;

            $product = $this->saveProduct($request, $thumbnailPath, $seoImagePath);

            // 2. Automatically create first ProductImage record for main image and dispatch thumbnail job
            if ($thumbnailPath) {
                $product->images()->create([
                    'image_path' => $thumbnailPath,
                    'is_main' => true,
                ]);

                ProcessProductThumbnailJob::dispatch($product->id, $thumbnailPath)->afterCommit();
            }

            // 4. Save gallery images
            if ($request->hasFile('gallery_images')) {
                $this->saveGalleryImages($product, $request->file('gallery_images'));
            }

            // 5. Save specifications
            if ($request->has('specifications') && is_array($request->input('specifications'))) {
                $this->saveSpecifications($product, $request->input('specifications'));
            }

            // 6. Handle variants (simple or multi-variant)
            $this->saveVariants($product, $request);

            // 7. Save color-specific images for variant options
            $hasVariantOptions = false;
            foreach ($request->input('variants', []) as $v) {
                if (isset($v['options']) && is_array($v['options']) && count($v['options']) > 0) {
                    $hasVariantOptions = true;
                    break;
                }
            }

            if ($hasVariantOptions && $request->hasFile('color_images')) {
                $this->saveColorImages($product, $request->file('color_images'));
            }

            return $product;
        });
    }

    /**
     * Save the base product record.
     */
    private function saveProduct(
        StoreProductRequest $request,
        ?string $thumbnailPath,
        ?string $seoImagePath,
    ): Product {

        $youtubeUrls = $request->input('youtube_urls', []);

        return Product::create([
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
            'meta_keywords' => $request->meta_keywords,
            'meta_image' => $seoImagePath,
            'image' => $thumbnailPath,
            'status' => $request->status,
            'created_by_id' => Auth::id(),
            'updated_by_id' => Auth::id(),
        ]);
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

    /**
     * Save a text attribute specification.
     */
    private function saveTextSpecification(Product $product, int $attributeId, mixed $value): void
    {
        $product->specifications()->create([
            'attribute_id' => $attributeId,
            'custom_value' => is_array($value) ? implode(', ', $value) : $value,
        ]);
    }

    /**
     * Save a predefined/select attribute specification.
     */
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

    /**
     * Save product variants (simple default variant or multi-variants combinations).
     */
    private function saveVariants(Product $product, StoreProductRequest $request): void
    {
        foreach ($request->input('variants', []) as $vInput) {
            $this->saveVariant($product, $vInput);
        }
    }

    /**
     * Save an individual variant and link its attribute values.
     */
    private function saveVariant(Product $product, array $vInput): void
    {
        $isActive = isset($vInput['is_active'])
            ? filter_var($vInput['is_active'], FILTER_VALIDATE_BOOLEAN)
            : ((isset($vInput['status']) && $vInput['status'] === 'inactive') ? false : true);

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

        if (isset($vInput['options']) && is_array($vInput['options']) && count($vInput['options']) > 0) {
            $this->linkVariantOptions($variant, $vInput['options']);
        }
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
     * Save color variant images.
     */
    private function saveColorImages(Product $product, array $colorFiles): void
    {
        foreach ($colorFiles as $colorName => $colorFile) {
            $path = $this->uploadFile($colorFile, 'products/variants');
            $product->images()->create([
                'image_path' => $path,
                'attribute_value' => $colorName,
            ]);
        }
    }
}
