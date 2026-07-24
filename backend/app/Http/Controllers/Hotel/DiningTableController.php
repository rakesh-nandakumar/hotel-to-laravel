<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreDiningTableRequest;
use App\Http\Requests\Hotel\UpdateDiningTableRequest;
use App\Http\Requests\Hotel\UpdateDiningTableStatusRequest;
use App\Models\Hotel\DiningTable;
use App\Models\Hotel\Order;
use App\Models\Lookup;
use App\Services\AuditLog;
use App\Support\Lookups\LookupType;
use App\Support\Lookups\OrderStatus;
use App\Support\Lookups\TableStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class DiningTableController extends Controller
{
    /** Direct status edits can't set OCCUPIED — that is only ever the result of a walk-in dine-in order being opened against the table. */
    private const WORKFLOW_ONLY_STATUSES = [TableStatus::OCCUPIED];

    /** The floor-plan board — every table plus whichever open order currently holds it, if any. */
    public function index(): JsonResponse
    {
        $tables = DiningTable::query()->with(['area:id,name', 'status'])->orderBy('table_no')->get();

        $openOrdersByTable = Order::query()
            ->whereNotNull('dining_table_id')
            ->statusIn([OrderStatus::OPEN, OrderStatus::PARKED])
            ->get(['id', 'dining_table_id', 'customer_name', 'total'])
            ->keyBy('dining_table_id');

        $tables->each(function (DiningTable $table) use ($openOrdersByTable) {
            $order = $openOrdersByTable->get($table->id);
            $table->open_order = $order ? ['id' => $order->id, 'customer_name' => $order->customer_name, 'total' => $order->total] : null;
        });

        return response()->json(['dining_tables' => $tables]);
    }

    public function store(StoreDiningTableRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['table_status_id'] = Lookup::id(LookupType::TABLE_STATUS, TableStatus::FREE);

        $table = DiningTable::create($data);

        AuditLog::record('dining_table.created', $table, ['table_no' => $table->table_no]);

        return response()->json(['message' => "Table \"{$table->table_no}\" created.", 'dining_table' => $table->load(['area', 'status'])], 201);
    }

    public function update(UpdateDiningTableRequest $request, DiningTable $diningTable): JsonResponse
    {
        $diningTable->update($request->validated());

        AuditLog::record('dining_table.updated', $diningTable, ['table_no' => $diningTable->table_no]);

        return response()->json(['message' => 'Table updated.', 'dining_table' => $diningTable->load(['area', 'status'])]);
    }

    public function updateStatus(UpdateDiningTableStatusRequest $request, DiningTable $diningTable): JsonResponse
    {
        $newStatus = $request->statusLookup();

        if (in_array($newStatus->code, self::WORKFLOW_ONLY_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => "Table status \"{$newStatus->code}\" is set automatically when an order is opened against it.",
            ]);
        }

        $from = $diningTable->status?->code;
        $diningTable->update(['table_status_id' => $newStatus->id]);

        AuditLog::record('dining_table.status_changed', $diningTable, ['from' => $from, 'to' => $newStatus->code]);

        return response()->json(['message' => 'Table status updated.', 'dining_table' => $diningTable->load('status')]);
    }
}
