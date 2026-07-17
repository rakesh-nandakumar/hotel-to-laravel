<?php

namespace App\Services\Hotel;

use App\Models\Hotel\Attendance;
use Illuminate\Validation\ValidationException;

/**
 * Simple clock in/out — one open record per user. Ported from the Node
 * app's routes/attendance.ts.
 */
class AttendanceService
{
    public function clockIn(int $userId): Attendance
    {
        if (Attendance::query()->where('user_id', $userId)->open()->exists()) {
            throw ValidationException::withMessages(['attendance' => 'Already clocked in — clock out first.']);
        }

        return Attendance::create(['user_id' => $userId, 'clock_in' => now()]);
    }

    public function clockOut(int $userId): Attendance
    {
        $open = Attendance::query()->where('user_id', $userId)->open()->first();
        if (! $open) {
            throw ValidationException::withMessages(['attendance' => 'Not clocked in.']);
        }

        $open->update(['clock_out' => now()]);

        return $open;
    }
}
