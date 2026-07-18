<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Hotel\SendTestNotificationRequest;
use App\Models\Hotel\Notification;
use App\Services\Hotel\NotificationSchedulerService;
use App\Services\Hotel\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NotificationSchedulerService $scheduler,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()->with(['channel', 'status'])->latest('created_at');

        if ($request->has('page')) {
            return response()->json(['notifications' => $query->paginate($request->integer('page_size', 25))->withQueryString()]);
        }

        return response()->json(['notifications' => $query->limit(200)->get()]);
    }

    /** Integration test-send — verifies WhatsApp/SMS credentials. System-Admin-only. */
    public function test(SendTestNotificationRequest $request): JsonResponse
    {
        $notification = $this->notifications->send([
            'type' => 'INTEGRATION_TEST',
            'channel' => $request->validated('channel'),
            'to' => $request->validated('to'),
            'subject' => 'Mount View HMS — integration test',
            'body' => 'Test message from Mount View Hospitality Management System ('.now()->format('d/m/Y, H:i:s').'). If you received this, the '.$request->validated('channel').' integration works.',
        ]);

        return response()->json(['notification' => $notification]);
    }

    /** Manually trigger the scheduled reminder sweep (also runs hourly via the scheduler). */
    public function runScheduled(): JsonResponse
    {
        return response()->json($this->scheduler->run());
    }
}
