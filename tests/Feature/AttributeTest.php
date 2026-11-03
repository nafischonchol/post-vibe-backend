<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Attribute;
use App\Models\AttributeValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttributeTest extends TestCase
{
    use RefreshDatabase;

    private function authenticateAdmin()
    {
        $adminUser = Admin::create([
            'name' => 'John Admin',
            'email' => 'john@admin.com',
            'password' => bcrypt('password'),
            'phone' => '1234567891',
            'is_active' => true,
        ]);

        $this->actingAs($adminUser, 'sanctum');

        return $adminUser;
    }

    public function test_creating_attribute_creates_predefined_values()
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/admin/attributes', [
            'name' => 'Size',
            'type' => 'select',
            'values' => ['L', 'XL'],
            'is_active' => true,
        ]);

        $response->assertStatus(200);

        $attribute = Attribute::first();
        $this->assertCount(2, $attribute->values);

        // Check attribute_values table
        $this->assertDatabaseHas('attribute_values', [
            'attribute_id' => $attribute->id,
            'value' => 'L',
        ]);
        $this->assertDatabaseHas('attribute_values', [
            'attribute_id' => $attribute->id,
            'value' => 'XL',
        ]);
    }

    public function test_creating_attribute_with_option_image()
    {
        $disk = config('filesystems.default', 'public');
        Storage::fake($disk);
        $this->authenticateAdmin();

        $file = UploadedFile::fake()->image('swatch_red.jpg');

        $response = $this->postJson('/admin/attributes', [
            'name' => 'Color',
            'type' => 'select',
            'values' => [
                ['value' => 'Red'],
                ['value' => 'Blue'],
            ],
            'value_image_0' => $file,
            'is_active' => true,
        ]);

        $response->assertStatus(200);

        $attribute = Attribute::first();
        $redVal = AttributeValue::where('attribute_id', $attribute->id)->where('value', 'Red')->first();

        $this->assertNotNull($redVal);
        $this->assertNotNull($redVal->image);
        Storage::disk($disk)->assertExists($redVal->image);
    }

    public function test_updating_attribute_soft_deletes_removed_values_and_adds_new_ones()
    {
        $this->authenticateAdmin();

        $attribute = Attribute::create([
            'name' => 'Size',
            'type' => 'select',
            'values' => null,
            'is_active' => true,
        ]);

        $val1 = AttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'L']);
        $val2 = AttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'XL']);

        // Update to L and M
        $response = $this->putJson("/admin/attributes/{$attribute->id}", [
            'name' => 'Size',
            'type' => 'select',
            'values' => ['L', 'M'],
            'is_active' => true,
        ]);

        $response->assertStatus(200);

        // L should remain active
        $this->assertDatabaseHas('attribute_values', [
            'attribute_id' => $attribute->id,
            'value' => 'L',
            'deleted_at' => null,
        ]);

        // XL should be soft deleted
        $this->assertSoftDeleted('attribute_values', [
            'attribute_id' => $attribute->id,
            'value' => 'XL',
        ]);

        // M should be created
        $this->assertDatabaseHas('attribute_values', [
            'attribute_id' => $attribute->id,
            'value' => 'M',
            'deleted_at' => null,
        ]);
    }

    public function test_readding_soft_deleted_value_restores_it_without_duplication()
    {
        $this->authenticateAdmin();

        $attribute = Attribute::create([
            'name' => 'Size',
            'type' => 'select',
            'values' => null,
            'is_active' => true,
        ]);

        $val1 = AttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'L']);
        $val2 = AttributeValue::create(['attribute_id' => $attribute->id, 'value' => 'XL']);

        // First update: remove XL
        $this->putJson("/admin/attributes/{$attribute->id}", [
            'name' => 'Size',
            'type' => 'select',
            'values' => ['L'],
            'is_active' => true,
        ])->assertStatus(200);

        $this->assertSoftDeleted('attribute_values', [
            'id' => $val2->id,
        ]);

        // Second update: re-add XL
        $this->putJson("/admin/attributes/{$attribute->id}", [
            'name' => 'Size',
            'type' => 'select',
            'values' => ['L', 'XL'],
            'is_active' => true,
        ])->assertStatus(200);

        // XL should be restored
        $this->assertDatabaseHas('attribute_values', [
            'id' => $val2->id,
            'deleted_at' => null,
        ]);

        $this->assertEquals(2, AttributeValue::withTrashed()->where('attribute_id', $attribute->id)->count());
        $this->assertEquals(2, AttributeValue::where('attribute_id', $attribute->id)->count());
    }

    public function test_creating_rich_text_attribute()
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/admin/attributes', [
            'name' => 'Full Overview',
            'type' => 'rich_text',
            'values' => null,
            'is_active' => true,
        ]);

        $response->assertStatus(200);

        $attribute = Attribute::where('name', 'Full Overview')->first();
        $this->assertNotNull($attribute);
        $this->assertEquals('rich_text', $attribute->type);
        $this->assertNull($attribute->values);
    }

    public function test_creating_attribute_with_is_default_specification()
    {
        $this->authenticateAdmin();

        $response = $this->postJson('/admin/attributes', [
            'name' => 'Key Ingredients',
            'type' => 'text',
            'values' => null,
            'is_active' => true,
            'is_default_specification' => true,
        ]);

        $response->assertStatus(200);

        $attribute = Attribute::where('name', 'Key Ingredients')->first();
        $this->assertNotNull($attribute);
        $this->assertTrue((bool) $attribute->is_default_specification);
        $this->assertTrue($response->json('resources.is_default_specification'));
    }
}
