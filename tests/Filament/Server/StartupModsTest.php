<?php

use App\Filament\Server\Pages\Startup;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ReinstallServerService;
use Filament\Facades\Filament;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

use function Pest\Livewire\livewire;

require_once __DIR__ . '/MinecraftHelpers.php';

beforeEach(function () {
    Filament::setCurrentPanel('server');
    Cache::flush();
});
afterEach(fn () => Filament::setCurrentPanel(null));

it('hides the modpack button for other games', function () {
    [$user, $server] = generateTestAccount();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)->assertActionHidden('loomnode_update_pack');
});

it('hides the modpack button while no modpack is set', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'lithium']);
    installFile(FABRIC_INSTALL);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)->assertActionHidden('loomnode_update_pack');
});

it('backs up, pins the new pack version and queues the reinstall', function () {
    Bus::fake();
    [$user, $server] = generateTestAccount();
    $server->update(['backup_limit' => 2]);
    minecraftServer($server, ['MC_MODPACK' => 'create_plus', 'MC_MODPACK_VERSION' => 'latest']);
    installFile(PACK_INSTALL);
    fakeModrinth();
    $backup = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => null]);
    $this->mock(InitiateBackupService::class, function ($mock) use ($backup) {
        $mock->shouldReceive('setIgnoredFiles')->with([])->andReturnSelf();
        $mock->shouldReceive('handle')->once()->andReturn($backup);
    });
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)
        ->assertActionVisible('loomnode_update_pack')
        ->mountAction('loomnode_update_pack')
        ->assertMountedActionModalSee('6.0.1')
        ->assertMountedActionModalSee('7.0.0 for Minecraft 1.21.11')
        ->callMountedAction();

    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('6.0.1');
    Bus::assertDispatched(CallQueuedClosure::class);
});

it('does nothing when the pack is already at the newest release for its Minecraft version', function () {
    [$user, $server] = generateTestAccount();
    $server->update(['backup_limit' => 2]);
    minecraftServer($server, ['MC_MODPACK' => 'create_plus']);
    installFile(str_replace('pack_version_id=OLD1', 'pack_version_id=NEW1', PACK_INSTALL));
    fakeModrinth();
    $this->mock(InitiateBackupService::class)->shouldNotReceive('handle');
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)->mountAction('loomnode_update_pack')->assertMountedActionModalSee('Nothing to update')->callMountedAction();

    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('latest');
});

it('reinstalls once the backup succeeded, and puts the old version back when it failed', function () {
    [, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODPACK' => 'create_plus', 'MC_MODPACK_VERSION' => '6.0.1']);

    $good = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => now(), 'is_successful' => true]);
    $this->mock(ReinstallServerService::class)->shouldReceive('handle')->once()->andReturn($server);
    Startup::continuePackUpdate($server->id, $good->id, 'latest', 0);
    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('6.0.1');

    $bad = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => now(), 'is_successful' => false]);
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');
    Startup::continuePackUpdate($server->id, $bad->id, 'latest', 0);
    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('latest');
});

it('checks again later while the backup is still running', function () {
    Bus::fake();
    [, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODPACK' => 'create_plus', 'MC_MODPACK_VERSION' => '6.0.1']);
    $running = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => null]);
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');

    Startup::continuePackUpdate($server->id, $running->id, 'latest', 0);

    Bus::assertDispatched(CallQueuedClosure::class);
    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('6.0.1');
});
