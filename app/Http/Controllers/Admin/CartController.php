<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Client;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CartController extends Controller
{
    /**
     * Display a listing of customer and guest carts.
     */
    public function index(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $search = $request->input('search');
        $type = $request->input('type', 'all'); // 'all', 'registered', 'guest'

        // Overall stats
        $stats = [
            'total_carts' => DB::table('carts')->select(DB::raw('COALESCE(client_id, visitor_id) as sid'))->distinct()->count(),
            'registered_carts' => Cart::whereNotNull('client_id')->distinct('client_id')->count('client_id'),
            'guest_carts' => Cart::whereNull('client_id')->distinct('visitor_id')->count('visitor_id'),
            'total_items' => (int) Cart::sum('quantity'),
            'unique_products' => Cart::distinct('product_id')->count('product_id'),
        ];

        // Group carts by (client_id OR visitor_id)
        $sessionKey = DB::raw('COALESCE(CONCAT("c_", client_id), CONCAT("v_", visitor_id))');

        $query = Cart::query()
            ->select(
                DB::raw('COALESCE(CONCAT("c_", client_id), CONCAT("v_", visitor_id)) as session_id'),
                'client_id',
                'visitor_id',
                DB::raw('COUNT(id) as total_items'),
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('MAX(updated_at) as last_updated_at')
            )
            ->groupBy(DB::raw('COALESCE(CONCAT("c_", client_id), CONCAT("v_", visitor_id))'), 'client_id', 'visitor_id');

        if ($type === 'registered') {
            $query->whereNotNull('client_id');
        } elseif ($type === 'guest') {
            $query->whereNull('client_id');
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('client', function ($clientQ) use ($search) {
                    $clientQ->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })->orWhereHas('product', function ($prodQ) use ($search) {
                    $prodQ->where('name', 'like', "%{$search}%");
                });
            });
        }

        $paginated = $query->orderByDesc('last_updated_at')->paginate($perPage);

        // Hydrate clients and items
        $clientIds = collect($paginated->items())->pluck('client_id')->filter()->unique()->toArray();
        $clients = Client::whereIn('id', $clientIds)->get()->keyBy('id');

        $visitorIds = collect($paginated->items())->whereNull('client_id')->pluck('visitor_id')->filter()->unique()->toArray();

        $allCartItems = Cart::with(['product.category', 'variant.attributeValues'])
            ->where(function ($q) use ($clientIds, $visitorIds) {
                if (!empty($clientIds)) {
                    $q->whereIn('client_id', $clientIds);
                }
                if (!empty($visitorIds)) {
                    $q->orWhereIn('visitor_id', $visitorIds);
                }
            })
            ->get();

        $formattedItems = collect($paginated->items())->map(function ($row) use ($clients, $allCartItems) {
            $client = $row->client_id ? $clients->get($row->client_id) : null;

            $items = $allCartItems->filter(function ($item) use ($row) {
                if ($row->client_id) {
                    return $item->client_id === $row->client_id;
                }
                return $item->visitor_id === $row->visitor_id;
            })->values();

            $totalAmount = $items->sum(function ($item) {
                $price = $item->variant?->discount_price ?? $item->variant?->price ?? 0;
                return $price * $item->quantity;
            });

            return [
                'session_id' => $row->session_id,
                'is_guest' => empty($row->client_id),
                'client' => $client ? [
                    'id' => $client->id,
                    'name' => $client->name,
                    'phone' => $client->phone,
                    'email' => $client->email,
                    'avatar' => $client->avatar,
                ] : null,
                'visitor_id' => $row->visitor_id,
                'total_items' => (int) $row->total_items,
                'total_quantity' => (int) $row->total_quantity,
                'total_amount' => (float) $totalAmount,
                'last_updated_at' => $row->last_updated_at,
                'items' => $items->map(function ($item) {
                    $variant = $item->variant;
                    $product = $item->product;
                    $price = (float) ($variant?->discount_price ?? $variant?->price ?? 0);
                    $stock = (int) ($variant?->stock ?? 0);

                    return [
                        'id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $product?->name ?? 'Product Unavailable',
                        'product_slug' => $product?->slug ?? '',
                        'product_image' => $product?->image ? (str_starts_with($product->image, 'http') ? $product->image : asset('storage/' . ltrim($product->image, '/'))) : '',
                        'category_name' => $product?->category?->name ?? '',
                        'variant_id' => $item->product_variant_id,
                        'sku' => $variant?->sku ?? '',
                        'price' => $price,
                        'quantity' => (int) $item->quantity,
                        'subtotal' => $price * $item->quantity,
                        'stock' => $stock,
                        'is_in_stock' => $stock > 0,
                    ];
                }),
            ];
        });

        return responseSuccess([
            'summary' => $stats,
            'items' => $formattedItems,
            'pagination' => pagination($paginated),
        ]);
    }

    /**
     * Product demand in customer carts ("Admin bujte parbe kun product gula customer er dorkar").
     */
    public function demand(Request $request)
    {
        $perPage = (int) $request->input('per_page', 20);
        $search = $request->input('search');

        $query = Cart::query()
            ->select(
                'product_id',
                DB::raw('COUNT(DISTINCT COALESCE(CONCAT("c_", client_id), CONCAT("v_", visitor_id))) as cart_count'),
                DB::raw('SUM(quantity) as total_quantity_demanded'),
                DB::raw('MAX(updated_at) as last_added_at')
            )
            ->groupBy('product_id');

        if (!empty($search)) {
            $query->whereHas('product', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $paginated = $query->orderByDesc('cart_count')->paginate($perPage);

        $productIds = collect($paginated->items())->pluck('product_id')->toArray();
        $products = Product::with(['category', 'brand', 'variants'])
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $items = collect($paginated->items())->map(function ($row) use ($products) {
            $product = $products->get($row->product_id);
            $totalStock = $product ? (int) $product->variants->sum('stock') : 0;
            $minPrice = $product ? (float) $product->variants->min('price') : 0;

            return [
                'product_id' => $row->product_id,
                'name' => $product?->name ?? 'Unknown Product',
                'slug' => $product?->slug ?? '',
                'image' => $product?->image ? (str_starts_with($product->image, 'http') ? $product->image : asset('storage/' . ltrim($product->image, '/'))) : '',
                'category_name' => $product?->category?->name ?? '',
                'brand_name' => $product?->brand?->name ?? '',
                'cart_count' => (int) $row->cart_count,
                'total_quantity_demanded' => (int) $row->total_quantity_demanded,
                'current_stock' => $totalStock,
                'unit_price' => $minPrice,
                'stock_status' => $totalStock <= 0 ? 'out_of_stock' : ($totalStock < $row->total_quantity_demanded ? 'demand_exceeds_stock' : 'in_stock'),
                'last_added_at' => $row->last_added_at,
            ];
        });

        return responseSuccess([
            'items' => $items,
            'pagination' => pagination($paginated),
        ]);
    }

    /**
     * Delete a cart group by session_id (e.g. c_1 or v_ULID).
     */
    public function destroy($sessionId)
    {
        if (str_starts_with($sessionId, 'c_')) {
            $clientId = substr($sessionId, 2);
            Cart::where('client_id', $clientId)->delete();
        } elseif (str_starts_with($sessionId, 'v_')) {
            $visitorId = substr($sessionId, 2);
            Cart::where('visitor_id', $visitorId)->delete();
        } else {
            Cart::where('id', $sessionId)->delete();
        }

        return responseSuccess(null, 'Cart cleared successfully');
    }
}
