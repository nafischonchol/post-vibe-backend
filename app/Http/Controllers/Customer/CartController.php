<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Cart\AddToCartRequest;
use App\Http\Requests\Customer\Cart\UpdateCartRequest;
use App\Services\Customer\CartService;
use Illuminate\Http\Request;

class CartController extends Controller
{
    protected $cartService;

    public function __construct(CartService $cartService)
    {
        $this->cartService = $cartService;
    }

    public function index(Request $request)
    {
        return $this->cartService->getCartItems($request);
    }

    public function store(AddToCartRequest $request)
    {
        return $this->cartService->addToCart($request->validated(), $request);
    }

    public function update(UpdateCartRequest $request, $id)
    {
        return $this->cartService->updateCart((int) $id, (int) $request->validated()['quantity'], $request);
    }

    public function destroy(Request $request, $id)
    {
        return $this->cartService->removeFromCart((int) $id, $request);
    }

    public function clear(Request $request)
    {
        return $this->cartService->clearCart($request);
    }
}
