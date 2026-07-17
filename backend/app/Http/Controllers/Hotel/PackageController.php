<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\UpdatePackageRequest;
use App\Models\Hotel\Package;
use App\Services\AuditLog;
use Illuminate\Http\JsonResponse;

class PackageController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'packages' => Package::query()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdatePackageRequest $request, Package $package): JsonResponse
    {
        $package->update($request->validated());

        AuditLog::record('package.updated', $package, ['name' => $package->name]);

        return response()->json(['message' => 'Package updated.', 'package' => $package]);
    }
}
