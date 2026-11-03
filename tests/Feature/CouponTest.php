<?php

use App\Enums\ClientTypeEnum;
use App\Models\Admin;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = Admin::create([
        'name' => 'Admin User',
        'email' => 'admin@moima.com',
        'password' => bcrypt('password'),
        'phone' => '01700000000',
        'is_active' => true,
    ]);
});

test('admin can create flat and percentage coupons', function () {
    $payload = [
        'code' => 'FLAT100',
        'name' => 'Save 100 Taka',
        'type' => 'fixed',
        'value' => 100,
        'min_order_amount' => 500,
        'target_type' => 'all',
        'usage_limit_per_client' => 1,
        'is_active' => true,
    ];

    $response = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/admin/coupons', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('coupons', [
        'code' => 'FLAT100',
        'type' => 'fixed',
        'value' => 100.00,
        'min_order_amount' => 500.00,
    ]);
});

test('admin can generate unique random coupon code', function () {
    $response = $this->actingAs($this->admin, 'sanctum')
        ->getJson('/admin/coupons/generate-code?prefix=MOI');

    $response->assertOk()
        ->assertJsonStructure(['resources' => ['code']]);

    $code = $response->json('resources.code');
    expect($code)->toStartWith('MOI-');
});

test('validates fixed discount correctly via coupon service', function () {
    $coupon = Coupon::create([
        'code' => 'SAVE50',
        'type' => 'fixed',
        'value' => 50,
        'min_order_amount' => 200,
        'target_type' => 'all',
        'usage_limit_per_client' => 1,
        'is_active' => true,
    ]);

    $service = app(CouponService::class);

    // Below min spend
    expect(fn () => $service->validateAndCalculate('SAVE50', 150))
        ->toThrow(InvalidArgumentException::class, 'Minimum order amount of ৳200.00 is required');

    // Valid spend
    $res = $service->validateAndCalculate('SAVE50', 300);
    expect($res['discount_amount'])->toBe(50.0);
});

test('validates percentage discount with max cap correctly', function () {
    $coupon = Coupon::create([
        'code' => 'PERCENT20',
        'type' => 'percentage',
        'value' => 20,
        'max_discount_amount' => 150,
        'target_type' => 'all',
        'usage_limit_per_client' => 1,
        'is_active' => true,
    ]);

    $service = app(CouponService::class);

    // 20% of 500 = 100 (< 150 cap)
    $res1 = $service->validateAndCalculate('PERCENT20', 500);
    expect($res1['discount_amount'])->toBe(100.0);

    // 20% of 1000 = 200 (> 150 cap -> capped at 150)
    $res2 = $service->validateAndCalculate('PERCENT20', 1000);
    expect($res2['discount_amount'])->toBe(150.0);
});

test('enforces expiration and inactive states', function () {
    $expiredCoupon = Coupon::create([
        'code' => 'OLDCOUPON',
        'type' => 'fixed',
        'value' => 50,
        'target_type' => 'all',
        'usage_limit_per_client' => 1,
        'expires_at' => now()->subDay(),
        'is_active' => true,
    ]);

    $service = app(CouponService::class);
    expect(fn () => $service->validateAndCalculate('OLDCOUPON', 500))
        ->toThrow(InvalidArgumentException::class, 'This coupon expired on');

    $inactiveCoupon = Coupon::create([
        'code' => 'PAUSED',
        'type' => 'fixed',
        'value' => 50,
        'target_type' => 'all',
        'usage_limit_per_client' => 1,
        'is_active' => false,
    ]);

    expect(fn () => $service->validateAndCalculate('PAUSED', 500))
        ->toThrow(InvalidArgumentException::class, 'This coupon is currently inactive.');
});

test('enforces specific client targeting', function () {
    $clientA = Client::create([
        'name' => 'Client A',
        'phone' => '01711111111',
        'email' => 'clienta@test.com',
        'type' => ClientTypeEnum::CUSTOMER,
        'is_active' => true,
        'status' => 'approved',
    ]);
    $clientB = Client::create([
        'name' => 'Client B',
        'phone' => '01722222222',
        'email' => 'clientb@test.com',
        'type' => ClientTypeEnum::CUSTOMER,
        'is_active' => true,
        'status' => 'approved',
    ]);

    $coupon = Coupon::create([
        'code' => 'VIPONLY',
        'type' => 'fixed',
        'value' => 200,
        'target_type' => 'specific_clients',
        'usage_limit_per_client' => 1,
        'is_active' => true,
    ]);
    $coupon->clients()->attach($clientA->id);

    $service = app(CouponService::class);

    // Client B not eligible
    expect(fn () => $service->validateAndCalculate('VIPONLY', 500, $clientB->id))
        ->toThrow(InvalidArgumentException::class, 'You are not eligible to use this coupon.');

    // Client A eligible
    $res = $service->validateAndCalculate('VIPONLY', 500, $clientA->id);
    expect($res['discount_amount'])->toBe(200.0);
});

test('enforces per-client and global usage limits', function () {
    $client = Client::create([
        'name' => 'Client Test',
        'phone' => '01733333333',
        'email' => 'clienttest@test.com',
        'type' => ClientTypeEnum::CUSTOMER,
        'is_active' => true,
        'status' => 'approved',
    ]);

    $coupon = Coupon::create([
        'code' => 'ONCEONLY',
        'type' => 'fixed',
        'value' => 100,
        'target_type' => 'all',
        'usage_limit_total' => 1,
        'usage_limit_per_client' => 1,
        'used_count' => 0,
        'is_active' => true,
    ]);

    $service = app(CouponService::class);

    // Initial check should pass
    $res = $service->validateAndCalculate('ONCEONLY', 500, $client->id);
    expect($res['discount_amount'])->toBe(100.0);

    // Fake an order and record usage
    $order = Order::create([
        'invoice_no' => 'INV-TEST-01',
        'total_amount' => 500,
        'grand_total' => 400,
        'paid_amount' => 0,
        'change_amount' => 0,
        'discount_amount' => 100,
        'client_id' => $client->id,
    ]);

    $service->recordUsage($coupon, $order, $client->id, 100);

    // Coupon used_count incremented
    expect($coupon->fresh()->used_count)->toBe(1);

    // Subsequent check by client should fail
    expect(fn () => $service->validateAndCalculate('ONCEONLY', 500, $client->id))
        ->toThrow(InvalidArgumentException::class, 'total usage limit');
});

test('public customer coupon validation endpoint works', function () {
    Coupon::create([
        'code' => 'SAVE10',
        'type' => 'fixed',
        'value' => 10,
        'target_type' => 'all',
        'usage_limit_per_client' => 1,
        'is_active' => true,
    ]);

    $response = $this->postJson('/customer/coupons/validate', [
        'code' => 'SAVE10',
        'subtotal' => 100,
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'resources' => [
                'code' => 'SAVE10',
                'discount_amount' => 10,
            ],
        ]);
});

test('order placement with coupon applies discount and records usage', function () {
    $category = Category::create(['name' => 'Dress', 'slug' => 'dress', 'is_active' => true]);
    $unit = Unit::create(['name' => 'Piece', 'short_name' => 'pc', 'is_active' => true]);

    $product = Product::create([
        'name' => 'Silk Saree',
        'slug' => 'silk-saree',
        'category_id' => $category->id,
        'unit_id' => $unit->id,
        'is_active' => true,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'sku' => 'SKU-SAR-01',
        'price' => 1000,
        'stock' => 10,
        'is_active' => true,
    ]);

    $cart = Cart::create([
        'product_variant_id' => $variant->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'price' => 1000,
    ]);

    $coupon = Coupon::create([
        'code' => 'EID100',
        'type' => 'fixed',
        'value' => 100,
        'min_order_amount' => 500,
        'target_type' => 'all',
        'usage_limit_per_client' => 1,
        'is_active' => true,
    ]);

    $orderPayload = [
        'name' => 'Rahim Ahmed',
        'phone' => '01799999999',
        'address' => 'House 1, Road 2, Dhanmondi',
        'city' => 'Dhaka',
        'district' => 'Dhaka',
        'cart_ids' => [$cart->id],
        'coupon_code' => 'EID100',
        'delivery_charge' => 60,
    ];

    $response = $this->postJson('/customer/orders', $orderPayload);

    $response->assertOk();
    $data = $response->json('resources');

    // Grand total = 1000 subtotal - 100 discount + 60 delivery = 960
    expect((float) $data['grand_total'])->toBe(960.0);

    $order = Order::find($data['order_id']);
    expect($order->coupon_code)->toBe('EID100')
        ->and((float) $order->discount_amount)->toBe(100.0)
        ->and((float) $order->grand_total)->toBe(960.0);

    // Verify coupon usage was recorded and count incremented
    $this->assertDatabaseHas('coupon_usages', [
        'coupon_id' => $coupon->id,
        'order_id' => $order->id,
        'discount_amount' => 100.00,
    ]);

    expect($coupon->fresh()->used_count)->toBe(1);
});
