<?php

use App\Enums\SubuserPermission;
use App\Filament\Server\Pages\Mods;
use App\Filament\Server\Pages\Settings;
use App\Filament\Server\Pages\Startup;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Subuser;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ReinstallServerService;
use Filament\Actions\Testing\TestAction;
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

    livewire(Mods::class)->assertForbidden();
});

it('hides the modpack button while no modpack is set', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'lithium']);
    installFile(FABRIC_INSTALL);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)->assertActionHidden('loomnode_update_pack');
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

    livewire(Mods::class)
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

    livewire(Mods::class)->mountAction('loomnode_update_pack')->assertMountedActionModalSee('Nothing to update')->callMountedAction();

    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('latest');
});

it('reinstalls once the backup succeeded, and puts the old version back when it failed', function () {
    [, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODPACK' => 'create_plus', 'MC_MODPACK_VERSION' => '6.0.1']);

    $good = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => now(), 'is_successful' => true]);
    $this->mock(ReinstallServerService::class)->shouldReceive('handle')->once()->andReturn($server);
    Mods::continuePackUpdate($server->id, $good->id, 'latest', 0);
    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('6.0.1');

    $bad = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => now(), 'is_successful' => false]);
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');
    Mods::continuePackUpdate($server->id, $bad->id, 'latest', 0);
    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('latest');
});

it('checks again later while the backup is still running', function () {
    Bus::fake();
    [, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODPACK' => 'create_plus', 'MC_MODPACK_VERSION' => '6.0.1']);
    $running = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => null]);
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');

    Mods::continuePackUpdate($server->id, $running->id, 'latest', 0);

    Bus::assertDispatched(CallQueuedClosure::class);
    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('6.0.1');
});

it('lists modpacks on the Modpack tab and picks one, waiting for an install that backs up first', function () {
    [$user, $server] = generateTestAccount();
    $server->update(['backup_limit' => 3]);
    minecraftServer($server);
    installFile(VANILLA_INSTALL . "mods=\n");
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(Mods::class)
        ->set('activeTab', 'pack')
        ->loadTable()
        ->assertSee('No modpack')
        ->assertSee('Chunky')
        ->assertActionHidden('removePack')
        ->assertActionHidden('changePackVersion')
        ->assertTableActionHidden('add', 'chunky')
        ->assertTableActionVisible('usePack', 'chunky')
        ->callTableAction('usePack', 'chunky', data: ['version' => 'latest']);

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'project_type:modpack') && str_contains(urldecode($request->url()), 'categories:neoforge'));
    expect(mcValue($server, 'MC_MODPACK'))->toBe('chunky')
        ->and(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('latest');

    $page->assertSee('1 change waiting to be installed')
        ->assertSee('+ modpack Chunky')
        ->assertSee('a world can break on another Minecraft version')
        ->assertTableActionHidden('usePack', 'chunky')
        ->assertActionVisible('removePack')
        ->mountAction('install')
        ->assertMountedActionModalSee('A backup is made first');
});

it('never takes a pack version Modrinth doesn\'t list', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server);
    installFile(VANILLA_INSTALL . "mods=\n");
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)->set('activeTab', 'pack')->loadTable()->callTableAction('usePack', 'chunky', data: ['version' => '9.9.9']);

    expect(mcValue($server, 'MC_MODPACK'))->toBe('');
});

it('changes the pack version and removes the pack, both as changes that back up first', function () {
    [$user, $server] = generateTestAccount();
    $server->update(['backup_limit' => 3]);
    minecraftServer($server, ['MC_MODPACK' => 'create_plus']);
    installFile(PACK_INSTALL . "mods=\n");
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(Mods::class)
        ->set('activeTab', 'pack')
        ->assertSee('Create+ 6.0.0 · Minecraft 1.21.1 · NeoForge')
        ->assertDontSee('waiting to be installed')
        ->mountAction('changePackVersion')
        ->assertMountedActionModalSee('7.0.0')
        ->assertMountedActionModalDontSee('quilt')
        ->fillForm(['version' => '7.0.0'])
        ->callMountedAction();

    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('7.0.0');
    $page->assertSee('Create+ 6.0.0 → 7.0.0')->mountAction('install')->assertMountedActionModalSee('A backup is made first');

    livewire(Mods::class)->callAction('changePackVersion', ['version' => 'not-a-version']);
    expect(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('7.0.0');

    livewire(Mods::class)->callAction('removePack')->assertSee('− modpack Create+');
    expect(mcValue($server, 'MC_MODPACK'))->toBe('')
        ->and(mcValue($server, 'MC_MODPACK_VERSION'))->toBe('latest');
});

it('sets a mod loader without mods, and locks the loader while mods are listed', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server);
    installFile(VANILLA_INSTALL . "mods=\n");
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)->callAction('modLoader', ['loader' => 'forge', 'loader_version' => '47.2.0']);
    expect(mcValue($server, 'MC_LOADER'))->toBe('forge')
        ->and(mcValue($server, 'MC_LOADER_VERSION'))->toBe('47.2.0');
    livewire(Mods::class)->assertSee('+ Forge mod loader')->mountAction('install')->assertMountedActionModalSee('your world and configs stay');

    $server->serverVariables()->whereHas('variable', fn ($q) => $q->where('env_variable', 'MC_MODS'))->update(['variable_value' => 'lithium']);
    livewire(Mods::class)->callAction('modLoader', ['loader' => 'fabric', 'loader_version' => 'latest']);
    expect(mcValue($server, 'MC_LOADER'))->toBe('forge')
        ->and(mcValue($server, 'MC_LOADER_VERSION'))->toBe('latest');
});

it('treats another loader on a modded server as a change that backs up first', function () {
    [$user, $server] = generateTestAccount();
    $server->update(['backup_limit' => 3]);
    minecraftServer($server, ['MC_LOADER' => 'forge']);
    installFile(FABRIC_INSTALL . "mods=\n");
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)->assertSee('mod loader Fabric → Forge')->mountAction('install')->assertMountedActionModalSee('A backup is made first');
});

it('keeps the mod settings and buttons out of Game Settings', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODPACK' => 'create_plus', 'MC_MODS' => 'lithium']);
    installFile(PACK_INSTALL . "mods=lithium\n");
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)
        ->assertActionDoesNotExist('loomnode_update_pack')
        ->assertActionDoesNotExist('loomnode_mods')
        ->assertDontSee('MC_MODPACK')
        ->assertDontSee('create_plus');
});

it('backs up first when Reinstall in Settings would remove a mod, and reinstalls plainly otherwise', function () {
    Bus::fake();
    [$user, $server] = generateTestAccount();
    $server->update(['backup_limit' => 3]);
    minecraftServer($server, ['MC_MODS' => 'chunky']);
    installFile(FABRIC_INSTALL . "mods=lithium,chunky\n");
    fakeModrinth();
    $backup = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => null]);
    $this->mock(InitiateBackupService::class, function ($mock) use ($backup) {
        $mock->shouldReceive('setIgnoredFiles')->with([])->andReturnSelf();
        $mock->shouldReceive('handle')->once()->andReturn($backup);
    });
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Settings::class)
        ->mountAction(TestAction::make('exclude_reinstall')->schemaComponent(true))
        ->assertMountedActionModalSee('out of the world for good')
        ->assertMountedActionModalSee('A backup is made first')
        ->callMountedAction();
    Bus::assertDispatched(CallQueuedClosure::class, 1);

    // a helper who may reinstall but not back up can't start that one
    [$helper] = generateTestAccount();
    Subuser::query()->create(['user_id' => $helper->id, 'server_id' => $server->id, 'permissions' => [SubuserPermission::SettingsReinstall->value]]);
    $this->actingAs($helper);
    livewire(Settings::class)->callAction(TestAction::make('exclude_reinstall')->schemaComponent(true))->assertNotified('This reinstall needs a backup first');

    // nothing risky waiting: the usual reinstall
    $this->actingAs($user);
    $server->serverVariables()->whereHas('variable', fn ($q) => $q->where('env_variable', 'MC_MODS'))->update(['variable_value' => 'lithium,chunky']);
    $this->mock(InitiateBackupService::class)->shouldNotReceive('handle');
    $this->mock(ReinstallServerService::class)->shouldReceive('handle')->once()->andReturn($server);
    livewire(Settings::class)->callAction(TestAction::make('exclude_reinstall')->schemaComponent(true));
});

it('leaves Reinstall in Settings alone for other games', function () {
    [$user, $server] = generateTestAccount();
    $this->mock(InitiateBackupService::class)->shouldNotReceive('handle');
    $this->mock(ReinstallServerService::class)->shouldReceive('handle')->once()->andReturn($server);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Settings::class)->callAction(TestAction::make('exclude_reinstall')->schemaComponent(true));
});
