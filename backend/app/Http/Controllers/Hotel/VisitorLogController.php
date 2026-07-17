<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreVisitorLogRequest;
use App\Models\Hotel\VisitorLog;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VisitorLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = VisitorLog::query()->with('loggedBy:id,name')->latest('time_in');

        if ($request->has('page')) {
            return response()->json(['visitors' => $query->paginate($request->integer('page_size', 25))->withQueryString()]);
        }

        return response()->json(['visitors' => $query->limit(200)->get()]);
    }

    public function store(StoreVisitorLogRequest $request): JsonResponse
    {
        $log = VisitorLog::create(array_merge($request->validated(), [
            'time_in' => now(),
            'logged_by_id' => $request->user()->id,
        ]));

        AuditLog::record('visitor.signed_in', $log, ['name' => $log->name, 'vehicle_no' => $log->vehicle_no]);

        return response()->json(['visitor' => $log], 201);
    }

    public function signOut(Request $request, VisitorLog $visitor): JsonResponse
    {
        if ($visitor->time_out) {
            throw ValidationException::withMessages(['visitor' => 'Already signed out.']);
        }

        $visitor->update(['time_out' => now()]);

        AuditLog::record('visitor.signed_out', $visitor, ['name' => $visitor->name]);

        return response()->json(['visitor' => $visitor]);
    }
}
