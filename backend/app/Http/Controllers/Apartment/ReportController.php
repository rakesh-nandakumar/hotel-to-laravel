<?php

namespace App\Http\Controllers\Apartment;

use App\Http\Controllers\Controller;
use App\Services\Apartment\ApartmentReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    public function __construct(private readonly ApartmentReportService $reports) {}

    public function dashboard(): JsonResponse
    {
        return response()->json($this->reports->dashboard());
    }
}
