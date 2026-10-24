<?php

use App\Models\Schedule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * LoomNode: schedules made before per-schedule timezones already ran on the panel's wall clock
     * (America/Chicago), and their next_run_at was worked out that way, so only the label changes.
     */
    public function up(): void
    {
        DB::table('schedules')->where('timezone', '!=', Schedule::panelTimezone())->update(['timezone' => Schedule::panelTimezone()]);
    }

    public function down(): void
    {
        // Nothing to undo: the add_timezone_to_schedules migration drops the column.
    }
};
