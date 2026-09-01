<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ordering\Actions\CreateOrder;
use App\Domain\Ordering\Models\Order;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function store(CreateOrderRequest $request, CreateOrder $createOrder): JsonResponse
    {
        $order = $createOrder->handle(
            sku: $request->string('sku')->toString(),
            customerEmail: $request->string('email')->toString() ?: null,
        );

        return OrderResource::make($order->load('delivery'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(string $publicId): OrderResource
    {
        $order = Order::query()
            ->with('delivery')
            ->where('public_id', $publicId)
            ->firstOrFail();

        return OrderResource::make($order);
    }
}
