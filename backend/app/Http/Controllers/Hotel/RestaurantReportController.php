<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Services\Hotel\RestaurantReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantReportController extends Controller
{
    public function __construct(private readonly RestaurantReportService $reports) {}

    public function menuPerformance(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeMenuPerformance($from, $to));
    }

    public function modifiers(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeModifierAttachRate($from, $to));
    }

    public function discountsVoids(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeDiscountsVoids($from, $to));
    }

    public function tableServer(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeTableServerPerformance($from, $to));
    }

    public function delivery(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeDeliveryPerformance($from, $to));
    }

    public function kitchenTicketTime(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeKitchenTicketTime($from, $to));
    }

    public function shiftSales(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeShiftSales($from, $to));
    }

    public function foodCost(): JsonResponse
    {
        return response()->json($this->reports->computeFoodCost());
    }
}
