<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\ChargeLaundryRequest;
use App\Http\Requests\Hotel\StoreLaundryItemRequest;
use App\Http\Requests\Hotel\UpdateLaundryItemRequest;
use App\Models\Hotel\LaundryItem;
use App\Services\AuditLog;
use App\Services\Hotel\LaundryService;
use Illuminate\Http\JsonResponse;

class LaundryController extends Controller
{
    public function __construct(private readonly LaundryService $laundry) {}

    public function index(): JsonResponse
    {
        return response()->json(['laundry_items' => LaundryItem::query()->orderBy('name')->get()]);
    }

    public function store(StoreLaundryItemRequest $request): JsonResponse
    {
        $item = LaundryItem::create($request->validated());

        AuditLog::record('laundry_item.created', $item, ['name' => $item->name]);

        return response()->json(['message' => "\"{$item->name}\" created.", 'laundry_item' => $item], 201);
    }

    public function update(UpdateLaundryItemRequest $request, LaundryItem $laundryItem): JsonResponse
    {
        $laundryItem->update($request->validated());

        AuditLog::record('laundry_item.updated', $laundryItem, ['name' => $laundryItem->name]);

        return response()->json(['message' => 'Laundry item updated.', 'laundry_item' => $laundryItem]);
    }

    public function charge(ChargeLaundryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $result = $this->laundry->chargeToRoom($data['room_id'], $data['items'], $data['note'] ?? null, $request->user()->id);

        return response()->json($result, 201);
    }
}
