<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Services\Apartment\ApartmentReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ApartmentReportService $reports) {}

    public function dashboard(): JsonResponse
    {
        return response()->json($this->reports->dashboard());
    }

    public function occupancyTrend(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeOccupancyTrend($from, $to));
    }

    public function revenueChannel(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeRentalRevenueChannelMix($from, $to));
    }

    public function rentRoll(): JsonResponse
    {
        return response()->json($this->reports->computeRentRoll());
    }

    public function salesPipeline(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeSalesPipeline($from, $to));
    }

    public function utilities(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeUtilities($from, $to));
    }

    public function opsSla(Request $request): JsonResponse
    {
        $from = $request->query('from', today()->subDays(29)->toDateString());
        $to = $request->query('to', today()->toDateString());

        return response()->json($this->reports->computeOpsSla($from, $to));
    }
}
