<?php

namespace Tests\Feature;

use App\Enums\AccountTypeEnum;
use App\Enums\ClientTypeEnum;
use App\Models\Account;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Services\Admin\PurchaseService;
use App\Services\Admin\SupplierPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseFeatureTest extends TestCase
{
    use RefreshDatabase;

    private Admin $adminUser;
    private Client $supplier;
    private Account $account;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminUser = Admin::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'phone' => '01700000000',
            'is_active' => true,
        ]);

        $this->actingAs($this->adminUser, 'sanctum');

        $this->supplier = Client::create([
            'name' => 'K-Beauty Wholesaler Inc.',
            'phone' => '01800000000',
            'email' => 'wholesaler@kbeauty.com',
            'type' => ClientTypeEnum::SUPPLIER,
            'is_active' => true,
        ]);

        $this->account = Account::create([
            'name' => 'Main Cash Account',
            'type' => AccountTypeEnum::CASH,
            'balance' => 100000.00,
            'is_active' => true,
        ]);

        $category = Category::create([
            'name' => 'Skincare',
            'slug' => 'skincare',
            'is_active' => true,
        ]);

        $product = Product::create([
            'name' => 'Snail Mucin Essence',
            'slug' => 'snail-mucin-essence',
            'category_id' => $category->id,
            'is_active' => true,
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $product->id,
            'title' => '100ml Bottle',
            'sku' => 'SNAIL-100ML',
            'price' => 1500.00,
            'purchase_price' => 800.00,
            'stock' => 10,
            'is_active' => true,
        ]);
    }

    public function test_can_create_purchase_unpaid_and_updates_stock()
    {
        $payload = [
            'client_id' => $this->supplier->id,
            'status' => 'completed',
            'note' => 'Unpaid stock import',
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'quantity' => 20,
                    'cost_price' => 750.00,
                ],
            ],
        ];

        $response = $this->postJson('/admin/purchases', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'success');

        $purchaseId = $response->json('data.id');

        $this->assertDatabaseHas('purchases', [
            'id' => $purchaseId,
            'client_id' => $this->supplier->id,
            'total_amount' => 15000.00,
            'paid_amount' => 0.00,
            'due_amount' => 15000.00,
            'payment_status' => 'unpaid',
            'status' => 'completed',
        ]);

        // Verify stock increased from 10 -> 30 (10 + 20)
        $this->assertDatabaseHas('product_variants', [
            'id' => $this->variant->id,
            'stock' => 30,
            'purchase_price' => 750.00,
        ]);

        // Verify stock movement
        $this->assertDatabaseHas('stock_movements', [
            'product_variant_id' => $this->variant->id,
            'quantity' => 20,
            'type' => 'purchase',
        ]);
    }

    public function test_can_create_purchase_with_initial_partial_payment()
    {
        $payload = [
            'client_id' => $this->supplier->id,
            'paid_amount' => 5000.00,
            'account_id' => $this->account->id,
            'payment_method' => 'cash',
            'status' => 'completed',
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'quantity' => 10,
                    'cost_price' => 1000.00, // Total = 10,000
                ],
            ],
        ];

        $response = $this->postJson('/admin/purchases', $payload);

        $response->assertStatus(201);
        $purchaseId = $response->json('data.id');

        $this->assertDatabaseHas('purchases', [
            'id' => $purchaseId,
            'total_amount' => 10000.00,
            'paid_amount' => 5000.00,
            'due_amount' => 5000.00,
            'payment_status' => 'partially_paid',
        ]);

        // Account balance decreased from 100,000 -> 95,000
        $this->assertDatabaseHas('accounts', [
            'id' => $this->account->id,
            'balance' => 95000.00,
        ]);

        // Transaction recorded
        $this->assertDatabaseHas('transactions', [
            'account_id' => $this->account->id,
            'client_id' => $this->supplier->id,
            'amount' => 5000.00,
            'type' => '-',
            'category' => 'purchase',
        ]);
    }

    public function test_fifo_bulk_due_settlement_clears_oldest_invoices_first()
    {
        $purchaseService = app(PurchaseService::class);

        // Create 10 due purchase invoices for the supplier (each 3,000 TK -> Total Due = 30,000 TK)
        $purchases = [];
        for ($i = 1; $i <= 10; $i++) {
            $purchases[] = $purchaseService->createPurchase([
                'client_id' => $this->supplier->id,
                'purchase_date' => now()->subDays(11 - $i), // Oldest to newest date
                'paid_amount' => 0,
                'status' => 'completed',
                'items' => [
                    [
                        'product_variant_id' => $this->variant->id,
                        'quantity' => 3,
                        'cost_price' => 1000.00, // Total = 3,000 per invoice
                    ],
                ],
            ], $this->adminUser->id);
        }

        // Verify total due for supplier via API endpoint
        $dueResponse = $this->getJson("/admin/suppliers/{$this->supplier->id}/due-invoices");
        $dueResponse->assertStatus(200);
        $dueResponse->assertJsonPath('data.total_due', 30000);
        $dueResponse->assertJsonPath('data.due_invoices_count', 10);

        // Pay 20,000 TK in bulk via FIFO due payment
        $paymentPayload = [
            'account_id' => $this->account->id,
            'amount' => 20000.00,
            'payment_method' => 'bank_transfer',
            'note' => 'Bulk due payment of 20,000 TK',
        ];

        $payResponse = $this->postJson("/admin/suppliers/{$this->supplier->id}/pay-due", $paymentPayload);

        $payResponse->assertStatus(200);
        $payResponse->assertJsonPath('status', 'success');

        // FIFO verification:
        // Invoices 1 to 6 (18,000 TK): Fully Paid (due_amount = 0, payment_status = paid)
        for ($i = 0; $i < 6; $i++) {
            $this->assertDatabaseHas('purchases', [
                'id' => $purchases[$i]->id,
                'paid_amount' => 3000.00,
                'due_amount' => 0.00,
                'payment_status' => 'paid',
            ]);
        }

        // Invoice 7 (3,000 TK): 2,000 TK Paid, 1,000 TK Due (partially_paid)
        $this->assertDatabaseHas('purchases', [
            'id' => $purchases[6]->id,
            'paid_amount' => 2000.00,
            'due_amount' => 1000.00,
            'payment_status' => 'partially_paid',
        ]);

        // Invoices 8 to 10 (9,000 TK): Unpaid (0 paid, 3,000 due, unpaid)
        for ($i = 7; $i < 10; $i++) {
            $this->assertDatabaseHas('purchases', [
                'id' => $purchases[$i]->id,
                'paid_amount' => 0.00,
                'due_amount' => 3000.00,
                'payment_status' => 'unpaid',
            ]);
        }

        // Account balance decreased from 100,000 -> 80,000
        $this->assertDatabaseHas('accounts', [
            'id' => $this->account->id,
            'balance' => 80000.00,
        ]);

        // Check remaining total due via API
        $dueResponse2 = $this->getJson("/admin/suppliers/{$this->supplier->id}/due-invoices");
        $dueResponse2->assertStatus(200);
        $dueResponse2->assertJsonPath('data.total_due', 10000);
        $dueResponse2->assertJsonPath('data.due_invoices_count', 4); // Invoice 7 (partial) + 8,9,10 (unpaid)
    }

    public function test_can_create_purchase_with_excess_payment_clearing_previous_due()
    {
        // 1. Create a previous unpaid purchase for 5,000 TK
        $prevPurchase = Purchase::create([
            'invoice_no' => 'PUR-PREV-001',
            'client_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'purchase_date' => now()->subDay(),
            'total_amount' => 5000.00,
            'paid_amount' => 0.00,
            'due_amount' => 5000.00,
            'payment_status' => 'unpaid',
            'status' => 'completed',
        ]);

        // 2. Create new purchase for 10,000 TK with 15,000 TK paid amount
        $payload = [
            'client_id' => $this->supplier->id,
            'paid_amount' => 15000.00,
            'account_id' => $this->account->id,
            'payment_method' => 'cash',
            'status' => 'completed',
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'quantity' => 10,
                    'cost_price' => 1000.00, // Total = 10,000
                ],
            ],
        ];

        $response = $this->postJson('/admin/purchases', $payload);
        $response->assertStatus(201);

        $newPurchaseId = $response->json('data.id');

        // New purchase is fully paid (10,000 paid, 0 due)
        $this->assertDatabaseHas('purchases', [
            'id' => $newPurchaseId,
            'total_amount' => 10000.00,
            'paid_amount' => 10000.00,
            'due_amount' => 0.00,
            'payment_status' => 'paid',
        ]);

        // Previous purchase of 5,000 TK is now fully cleared via excess 5,000 TK
        $this->assertDatabaseHas('purchases', [
            'id' => $prevPurchase->id,
            'paid_amount' => 5000.00,
            'due_amount' => 0.00,
            'payment_status' => 'paid',
        ]);
    }

    public function test_purchase_from_customer_client_updates_client_type_to_both()
    {
        $customerClient = Client::create([
            'name' => 'Regular Customer John',
            'phone' => '01711112222',
            'email' => 'john@customer.com',
            'type' => ClientTypeEnum::CUSTOMER,
            'is_active' => true,
        ]);

        $this->assertEquals(ClientTypeEnum::CUSTOMER, $customerClient->type);

        $payload = [
            'client_id' => $customerClient->id,
            'status' => 'completed',
            'note' => 'Purchase from customer turning them into supplier/both',
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'quantity' => 5,
                    'cost_price' => 500.00,
                ],
            ],
        ];

        $response = $this->postJson('/admin/purchases', $payload);

        $response->assertStatus(201);

        $customerClient->refresh();
        $this->assertEquals(ClientTypeEnum::BOTH, $customerClient->type);
        $this->assertDatabaseHas('clients', [
            'id' => $customerClient->id,
            'type' => 'both',
        ]);
    }

    public function test_due_payment_with_purchase_id_clears_target_purchase_first_then_fifo()
    {
        // Create 3 purchase invoices for supplier:
        // Purchase 1 (oldest): 3000 TK due
        $p1 = Purchase::create([
            'invoice_no' => 'PUR-001',
            'client_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'purchase_date' => now()->subDays(5),
            'total_amount' => 3000.00,
            'paid_amount' => 0.00,
            'due_amount' => 3000.00,
            'payment_status' => 'unpaid',
            'status' => 'completed',
        ]);

        // Purchase 2 (newer): 4000 TK due
        $p2 = Purchase::create([
            'invoice_no' => 'PUR-002',
            'client_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'purchase_date' => now()->subDays(3),
            'total_amount' => 4000.00,
            'paid_amount' => 0.00,
            'due_amount' => 4000.00,
            'payment_status' => 'unpaid',
            'status' => 'completed',
        ]);

        // Purchase 3 (newest): 5000 TK due
        $p3 = Purchase::create([
            'invoice_no' => 'PUR-003',
            'client_id' => $this->supplier->id,
            'supplier_name' => $this->supplier->name,
            'purchase_date' => now()->subDays(1),
            'total_amount' => 5000.00,
            'paid_amount' => 0.00,
            'due_amount' => 5000.00,
            'payment_status' => 'unpaid',
            'status' => 'completed',
        ]);

        // Pay 6000 TK with target purchase_id = p2->id
        // Target purchase p2 requires 4000 TK -> p2 becomes fully paid.
        // Remaining 2000 TK goes to oldest purchase p1 (FIFO) -> p1 has 1000 TK due remaining.
        // p3 remains untouched with 5000 TK due.
        $paymentPayload = [
            'account_id' => $this->account->id,
            'amount' => 6000.00,
            'payment_method' => 'cash',
            'purchase_id' => $p2->id,
            'note' => 'Targeted payment for purchase 2',
        ];

        $response = $this->postJson("/admin/suppliers/{$this->supplier->id}/pay-due", $paymentPayload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        // Target P2: Fully paid
        $this->assertDatabaseHas('purchases', [
            'id' => $p2->id,
            'paid_amount' => 4000.00,
            'due_amount' => 0.00,
            'payment_status' => 'paid',
        ]);

        // Oldest P1: Partially paid (2000 paid, 1000 due)
        $this->assertDatabaseHas('purchases', [
            'id' => $p1->id,
            'paid_amount' => 2000.00,
            'due_amount' => 1000.00,
            'payment_status' => 'partially_paid',
        ]);

        // Newest P3: Unpaid (0 paid, 5000 due)
        $this->assertDatabaseHas('purchases', [
            'id' => $p3->id,
            'paid_amount' => 0.00,
            'due_amount' => 5000.00,
            'payment_status' => 'unpaid',
        ]);
    }

    public function test_purchase_creation_updates_variant_cost_and_sale_price(): void
    {
        $payload = [
            'client_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'paid_amount' => 0,
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'quantity' => 5,
                    'cost_price' => 820.00,
                    'selling_price' => 1250.00,
                ],
            ],
        ];

        $response = $this->postJson('/admin/purchases', $payload);
        $response->assertStatus(201);

        $this->assertDatabaseHas('product_variants', [
            'id' => $this->variant->id,
            'purchase_price' => 820.00,
            'price' => 1250.00,
        ]);

        $this->assertDatabaseHas('purchase_items', [
            'product_variant_id' => $this->variant->id,
            'cost_price' => 820.00,
            'selling_price' => 1250.00,
        ]);

        // Assert initial challan created
        $this->assertDatabaseHas('purchase_challans', [
            'total_quantity' => 5,
        ]);
    }

    public function test_receive_items_endpoint_creates_challan_and_updates_stock(): void
    {
        // 1. Create a purchase with received_quantity = 0 (pending)
        $payload = [
            'client_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'paid_amount' => 0,
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'quantity' => 10,
                    'received_quantity' => 0,
                    'cost_price' => 500.00,
                ],
            ],
        ];

        $res = $this->postJson('/admin/purchases', $payload);
        $res->assertStatus(201);
        $purchaseId = $res->json('data.id');
        $purchaseItemId = $res->json('data.items.0.id');

        // Stock initial is 10
        $this->assertEquals(10, $this->variant->fresh()->stock);

        // 2. Receive 4 items via receive-items endpoint
        $receivePayload = [
            'received_date' => now()->toDateString(),
            'challan_no' => 'TEST-CHAL-001',
            'note' => 'Partial shipment from port',
            'items' => [
                [
                    'purchase_item_id' => $purchaseItemId,
                    'receive_quantity' => 4,
                ],
            ],
        ];

        $recResponse = $this->postJson("/admin/purchases/{$purchaseId}/receive-items", $receivePayload);
        $recResponse->assertStatus(200);

        // Stock should now be 10 + 4 = 14
        $this->assertEquals(14, $this->variant->fresh()->stock);

        // Purchase status should be partial
        $this->assertDatabaseHas('purchases', [
            'id' => $purchaseId,
            'status' => 'partial',
        ]);

        // Challan record must exist
        $this->assertDatabaseHas('purchase_challans', [
            'purchase_id' => $purchaseId,
            'challan_no' => 'TEST-CHAL-001',
            'total_quantity' => 4,
            'note' => 'Partial shipment from port',
        ]);

        $this->assertDatabaseHas('purchase_challan_items', [
            'purchase_item_id' => $purchaseItemId,
            'quantity' => 4,
        ]);
    }

    public function test_purchase_creation_automatically_settles_from_supplier_advance_payment(): void
    {
        // 1. Pay 5000 advance to supplier when no purchase invoices exist
        $advanceResponse = $this->postJson("/admin/suppliers/{$this->supplier->id}/pay-due", [
            'account_id' => $this->account->id,
            'amount' => 5000.00,
            'payment_method' => 'bank_transfer',
            'note' => 'Advance deposit to supplier',
        ]);
        $advanceResponse->assertStatus(200);

        // Supplier balance is now -5000 (meaning we have 5000 advance with supplier)
        $this->assertEquals(-5000.00, (float) $this->supplier->fresh()->balance);

        // 2. Create Purchase #1 for 3000 (quantity: 3 * cost: 1000) with 0 paid_amount
        $payload1 = [
            'client_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'paid_amount' => 0,
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'quantity' => 3,
                    'cost_price' => 1000.00,
                    'selling_price' => 1500.00,
                ],
            ],
        ];

        $res1 = $this->postJson('/admin/purchases', $payload1);
        $res1->assertStatus(201);
        $purchase1Id = $res1->json('data.id');

        // Assert Purchase #1 is fully paid from advance
        $this->assertDatabaseHas('purchases', [
            'id' => $purchase1Id,
            'total_amount' => 3000.00,
            'paid_amount' => 3000.00,
            'due_amount' => 0.00,
            'payment_status' => 'paid',
        ]);

        // Allocation record must exist linking advance payment to Purchase #1
        $this->assertDatabaseHas('payment_allocations', [
            'allocatable_type' => 'purchase',
            'allocatable_id' => $purchase1Id,
            'allocated_amount' => 3000.00,
        ]);

        // Supplier balance is now -5000 + 3000 = -2000
        $this->assertEquals(-2000.00, (float) $this->supplier->fresh()->balance);

        // 3. Create Purchase #2 for 4000 (quantity: 4 * cost: 1000) with 0 paid_amount
        $payload2 = [
            'client_id' => $this->supplier->id,
            'purchase_date' => now()->toDateString(),
            'paid_amount' => 0,
            'items' => [
                [
                    'product_variant_id' => $this->variant->id,
                    'quantity' => 4,
                    'cost_price' => 1000.00,
                    'selling_price' => 1500.00,
                ],
            ],
        ];

        $res2 = $this->postJson('/admin/purchases', $payload2);
        $res2->assertStatus(201);
        $purchase2Id = $res2->json('data.id');

        // Assert Purchase #2 is partially paid (2000 from remaining advance, 2000 due)
        $this->assertDatabaseHas('purchases', [
            'id' => $purchase2Id,
            'total_amount' => 4000.00,
            'paid_amount' => 2000.00,
            'due_amount' => 2000.00,
            'payment_status' => 'partially_paid',
        ]);

        // Allocation record must exist for Purchase #2
        $this->assertDatabaseHas('payment_allocations', [
            'allocatable_type' => 'purchase',
            'allocatable_id' => $purchase2Id,
            'allocated_amount' => 2000.00,
        ]);

        // Supplier balance is now -2000 + 4000 = +2000 (we now owe 2000)
        $this->assertEquals(2000.00, (float) $this->supplier->fresh()->balance);
    }
}
