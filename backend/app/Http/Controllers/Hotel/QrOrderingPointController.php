<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\StoreQrOrderingPointRequest;
use App\Http\Requests\Hotel\UpdateQrOrderingPointRequest;
use App\Models\Hotel\DiningTable;
use App\Models\Hotel\QrOrderingPoint;
use App\Models\Hotel\Room;
use App\Services\AuditLog;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Admin management of QR ordering points — one per room (guest self-orders
 * to the room folio) or per dining table (self-orders to the table's tab).
 * Guest-facing ordering itself lives in QrOrderController.
 */
class QrOrderingPointController extends Controller
{
    /** Every room and every dining table, decorated with its QR point if one has been generated. */
    public function index(): JsonResponse
    {
        $points = QrOrderingPoint::query()->get()->keyBy(fn (QrOrderingPoint $p) => $p->room_id ? "room:{$p->room_id}" : "table:{$p->dining_table_id}");

        $rooms = Room::query()->orderBy('number')->get()->map(fn (Room $room) => [
            'id' => $room->id,
            'number' => $room->number,
            'room_type' => $room->name,
            'qr' => $this->present($points->get("room:{$room->id}")),
        ]);

        $tables = DiningTable::query()->with('area:id,name')->orderBy('table_no')->get()->map(fn (DiningTable $table) => [
            'id' => $table->id,
            'table_no' => $table->table_no,
            'area' => $table->area?->name,
            'qr' => $this->present($points->get("table:{$table->id}")),
        ]);

        return response()->json(['rooms' => $rooms, 'tables' => $tables]);
    }

    public function store(StoreQrOrderingPointRequest $request): JsonResponse
    {
        $data = $request->validated();

        $point = QrOrderingPoint::create([
            'room_id' => $data['room_id'] ?? null,
            'dining_table_id' => $data['dining_table_id'] ?? null,
            'token' => Str::random(32),
        ]);

        AuditLog::record('qr_ordering_point.created', $point, ['token' => $point->token]);

        return response()->json(['qr_ordering_point' => $this->present($point)], 201);
    }

    public function update(UpdateQrOrderingPointRequest $request, QrOrderingPoint $qrOrderingPoint): JsonResponse
    {
        $qrOrderingPoint->update(['enabled' => $request->boolean('enabled')]);

        AuditLog::record('qr_ordering_point.toggled', $qrOrderingPoint, ['enabled' => $qrOrderingPoint->enabled]);

        return response()->json(['qr_ordering_point' => $this->present($qrOrderingPoint)]);
    }

    /** Issues a fresh token — the old printed/stuck QR code stops working immediately. */
    public function regenerate(QrOrderingPoint $qrOrderingPoint): JsonResponse
    {
        $qrOrderingPoint->update(['token' => Str::random(32)]);

        AuditLog::record('qr_ordering_point.regenerated', $qrOrderingPoint, ['token' => $qrOrderingPoint->token]);

        return response()->json(['qr_ordering_point' => $this->present($qrOrderingPoint)]);
    }

    /** The scannable QR image, as SVG — no GD/Imagick extension dependency. */
    public function image(QrOrderingPoint $qrOrderingPoint): Response
    {
        $renderer = new ImageRenderer(new RendererStyle(320), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($qrOrderingPoint->publicUrl());

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    /**
     * @return array{id: int, token: string, enabled: bool, url: string, created_at: ?string}|null
     */
    private function present(?QrOrderingPoint $point): ?array
    {
        if (! $point) {
            return null;
        }

        return [
            'id' => $point->id,
            'token' => $point->token,
            'enabled' => $point->enabled,
            'url' => $point->publicUrl(),
            'created_at' => $point->created_at?->toIso8601String(),
        ];
    }
}
