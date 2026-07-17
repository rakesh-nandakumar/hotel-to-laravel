<?php

namespace App\Http\Controllers\Hotel;

use App\Http\Controllers\Controller;
use App\Models\Hotel\Attendance;
use App\Services\Hotel\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /** Clock in — any authenticated staff member. */
    public function clockIn(Request $request): JsonResponse
    {
        return response()->json(['attendance' => $this->attendance->clockIn($request->user()->id)], 201);
    }

    public function clockOut(Request $request): JsonResponse
    {
        return response()->json(['attendance' => $this->attendance->clockOut($request->user()->id)]);
    }

    /** My own status/history. */
    public function me(Request $request): JsonResponse
    {
        $rows = Attendance::query()->where('user_id', $request->user()->id)->latest('clock_in')->limit(30)->get();

        return response()->json(['attendance' => $rows->map(fn (Attendance $a) => $this->withHours($a))]);
    }

    /** Who's currently clocked in — dashboard "staff on duty" widget. */
    public function onDuty(): JsonResponse
    {
        $rows = Attendance::query()->open()->with('user:id,name')->with('user.roles:name')->oldest('clock_in')->get();

        return response()->json(['on_duty' => $rows->map(fn (Attendance $a) => [
            'id' => $a->id, 'name' => $a->user->name,
            'role' => $a->user->roles->pluck('name')->implode(', '),
            'clock_in' => $a->clock_in,
        ])]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Attendance::query()->with('user:id,name')->with('user.roles:name')->latest('clock_in');

        if ($month = $request->string('month')->toString()) {
            $start = Carbon::parse("{$month}-01")->startOfMonth();
            $query->whereBetween('clock_in', [$start, $start->copy()->addMonthNoOverflow()]);
        }

        if ($request->has('page')) {
            $paginated = $query->paginate($request->integer('page_size', 25))->withQueryString();
            $paginated->getCollection()->transform(fn (Attendance $a) => $this->withHours($a));

            return response()->json(['attendance' => $paginated]);
        }

        $rows = $query->limit(500)->get()->map(fn (Attendance $a) => $this->withHours($a));

        return response()->json(['attendance' => $rows]);
    }

    /** CSV export for payroll reference — this system does not run payroll itself. */
    public function export(Request $request): Response
    {
        $month = $request->string('month', now()->format('Y-m'))->toString();
        $start = Carbon::parse("{$month}-01")->startOfMonth();

        $rows = Attendance::query()
            ->whereBetween('clock_in', [$start, $start->copy()->addMonthNoOverflow()])
            ->with('user:id,name')
            ->with('user.roles:name')
            ->orderBy('user_id')
            ->orderBy('clock_in')
            ->get();

        $lines = ['Staff,Role,Clock In,Clock Out,Hours'];
        foreach ($rows as $row) {
            $role = $row->user->roles->pluck('name')->implode('/');
            $hours = $row->hours() !== null ? number_format($row->hours(), 2) : '';
            $lines[] = "\"{$row->user->name}\",{$role},{$row->clock_in->toIso8601String()},".($row->clock_out?->toIso8601String() ?? '').",{$hours}";
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=attendance-{$month}.csv",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function withHours(Attendance $attendance): array
    {
        return array_merge($attendance->toArray(), ['hours' => $attendance->hours()]);
    }
}
