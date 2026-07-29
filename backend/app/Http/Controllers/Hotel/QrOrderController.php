<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\PlaceQrOrderRequest;
use App\Models\Hotel\Order;
use App\Services\Hotel\QrOrderingService;
use Illuminate\Http\JsonResponse;

/**
 * Unauthenticated guest-facing endpoints for QR ordering — reached by
 * scanning the QR code at a room desk or restaurant table. Sits entirely
 * outside the auth/permission system, matching PublicController.
 */
class QrOrderController extends Controller
{
    public function __construct(private readonly QrOrderingService $ordering) {}

    public function menu(string $token): JsonResponse
    {
        return response()->json($this->ordering->menuFor($token));
    }

    public function placeOrder(PlaceQrOrderRequest $request, string $token): JsonResponse
    {
        $order = $this->ordering->placeOrder($token, $request->validated());

        return response()->json(['order' => $this->ordering->orderStatus($token, $order)], 201);
    }

    public function orderStatus(string $token, Order $order): JsonResponse
    {
        return response()->json(['order' => $this->ordering->orderStatus($token, $order)]);
    }
}
