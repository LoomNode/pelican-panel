<?php

namespace App\Tests\Integration\Api\Client\Server\Schedule;

use App\Enums\SubuserPermission;
use App\Models\Schedule;
use App\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class SchedulePanelTimezoneTest extends ClientApiIntegrationTestCase
{
    /**
     * A schedule made through the Client API runs in the panel timezone, and its first next run is
     * worked out in that timezone too, not in UTC.
     */
    public function test_store_uses_the_panel_timezone(): void
    {
        config()->set('panel.client_features.schedules.timezone', 'America/Chicago');
        [$user, $server] = $this->generateTestAccount([SubuserPermission::ScheduleCreate]);

        $this->actingAs($user)->postJson("/api/client/servers/$server->uuid/schedules", [
            'name' => 'Nightly',
            'minute' => '0',
            'hour' => '0',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',
            'is_active' => true,
        ])->assertOk();

        /** @var Schedule $schedule */
        $schedule = Schedule::query()->where('server_id', $server->id)->firstOrFail();
        $this->assertSame('America/Chicago', $schedule->timezone);
        $this->assertSame('00:00', $schedule->next_run_at->copy()->timezone('America/Chicago')->format('H:i'));
    }

    /**
     * Saving a schedule that arrived with another timezone moves it to the panel timezone and works
     * out its next run again.
     */
    public function test_saving_moves_a_schedule_to_the_panel_timezone(): void
    {
        config()->set('panel.client_features.schedules.timezone', 'America/Chicago');
        [, $server] = $this->generateTestAccount();

        /** @var Schedule $schedule */
        $schedule = Schedule::factory()->create([
            'server_id' => $server->id,
            'cron_minute' => '30',
            'cron_hour' => '4',
            'timezone' => 'UTC',
            'next_run_at' => now(),
        ]);

        $this->assertSame('America/Chicago', $schedule->refresh()->timezone);
        $this->assertSame('04:30', $schedule->next_run_at->copy()->timezone('America/Chicago')->format('H:i'));
    }
}
