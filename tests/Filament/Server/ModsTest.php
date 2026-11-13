<?php

use App\Enums\SubuserPermission;
use App\Filament\Server\Pages\Mods;
use App\Filament\Server\Pages\Startup;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Subuser;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ReinstallServerService;
use Filament\Facades\Filament;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

require_once __DIR__ . '/MinecraftHelpers.php';

beforeEach(function () {
    Filament::setCurrentPanel('server');
    Cache::flush();
});
afterEach(fn () => Filament::setCurrentPanel(null));

const FABRIC_WITH_LITHIUM = FABRIC_INSTALL . "mods=lithium\n";

it('is only there for a server with the Minecraft mod settings', function () {
    [$user, $server] = generateTestAccount();
    $this->actingAs($user);
    Filament::setTenant($server);

    expect(Mods::canAccess())->toBeFalse();
    livewire(Mods::class)->assertForbidden();

    minecraftServer($server);
    expect(Mods::canAccess())->toBeTrue();
});

it('is hidden from a subuser who can\'t see the game settings', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::WebsocketConnect]);
    minecraftServer($server);
    $this->actingAs($user);
    Filament::setTenant($server);

    expect(Mods::canAccess())->toBeFalse();
    livewire(Mods::class)->assertForbidden();
});

it('lists server mods from Modrinth for the server\'s loader and version, without tunnel and client mods', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server);
    installFile(FABRIC_WITH_LITHIUM);
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)
        ->loadTable()
        ->assertSee('Fabric mods for Minecraft 1.21.1')
        ->assertSee('Chunky')
        ->assertSee('Pre-generates chunks')
        ->assertSee('pop4959')
        ->assertSee('1.2M')
        ->assertSee('Management')
        ->assertSee('Players need it too')
        ->assertSee('/mod-icon/fALzjamp/icon.png')
        ->assertDontSee('evil.example')
        ->assertDontSee('cdn.modrinth.com')
        ->assertDontSee('Tunnel')
        ->assertDontSee('A minimap');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/search')
        && str_contains(urldecode($request->url()), '"categories:fabric"')
        && str_contains(urldecode($request->url()), '"versions:1.21.1"')
        && str_contains(urldecode($request->url()), '"server_side:required","server_side:optional"')
        && str_contains(urldecode($request->url()), '"project_id!=qANg5Jrr"')
        && str_contains(urldecode($request->url()), '"categories!=library"')
        && str_contains(urldecode($request->url()), 'index=downloads')
        && str_contains(urldecode($request->url()), 'offset=0'));
});

it('searches, filters, sorts and pages through Modrinth', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server);
    installFile(FABRIC_WITH_LITHIUM);
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)
        ->loadTable()
        ->searchTable('chunk')
        ->filterTable('category', 'management')
        ->filterTable('players', 'server')
        ->filterTable('sort', 'updated')
        ->call('gotoPage', 3)
        ->assertSee('Chunky');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/search')
        && str_contains(urldecode($request->url()), 'query=chunk')
        && str_contains(urldecode($request->url()), '"categories:management"')
        && str_contains(urldecode($request->url()), '["client_side:optional","client_side:unsupported"]')
        && str_contains(urldecode($request->url()), 'index=updated')
        && str_contains(urldecode($request->url()), 'offset=40'));
});

it('adds and removes mods, and says what is waiting to be installed', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'lithium']);
    installFile(FABRIC_WITH_LITHIUM);
    fakeModrinth();
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(Mods::class)
        ->loadTable()
        ->assertActionHidden('install')
        ->assertDontSee('waiting to be installed')
        ->assertTableActionVisible('add', 'chunky')
        ->assertTableActionHidden('remove', 'chunky')
        ->callTableAction('add', 'chunky');

    expect(mcValue($server, 'MC_MODS'))->toBe('lithium,chunky')
        ->and(mcValue($server, 'MC_LOADER'))->toBe('auto');

    $page->assertSee('1 change waiting to be installed')
        ->assertSee('+ Chunky')
        ->assertActionVisible('install')
        ->assertTableActionHidden('add', 'chunky')
        ->assertTableActionVisible('remove', 'chunky')
        ->set('activeTab', 'yours')
        ->assertSee('Lithium')
        ->assertSee('Installed')
        ->assertSee('Waiting for install')
        ->callTableAction('remove', 'lithium');

    expect(mcValue($server, 'MC_MODS'))->toBe('chunky');
    $page->assertSee('2 changes waiting to be installed')->assertSee('− Lithium');
});

it('never adds a tunnel mod or one the search didn\'t offer', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server);
    installFile(FABRIC_WITH_LITHIUM);
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(Mods::class)->loadTable();
    foreach (['e4mc', 'xaeros-minimap', 'not-in-the-results'] as $slug) {
        try {
            $page->callTableAction('add', $slug);
        } catch (Throwable) {
            // no such row
        }
    }

    expect(mcValue($server, 'MC_MODS'))->toBe('');
});

it('sets the picked loader with a vanilla server\'s first mod, and goes back to auto when the list is empty again', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server);
    installFile(VANILLA_INSTALL . "mods=\n");
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(Mods::class)
        ->loadTable()
        ->assertSee('Vanilla Minecraft 1.21.1')
        ->filterTable('loader', 'neoforge')
        ->assertSee('adding mods makes it a NeoForge server')
        ->callTableAction('add', 'create');

    expect(mcValue($server, 'MC_MODS'))->toBe('create')
        ->and(mcValue($server, 'MC_LOADER'))->toBe('neoforge');
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '"categories:neoforge"'));

    $page->callTableAction('remove', 'create');

    expect(mcValue($server, 'MC_MODS'))->toBe('')
        ->and(mcValue($server, 'MC_LOADER'))->toBe('auto');
});

it('pins a mod to a version and back to newest', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'lithium,chunky']);
    installFile(FABRIC_WITH_LITHIUM);
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(Mods::class)
        ->set('activeTab', 'yours')
        ->loadTable()
        ->callTableAction('version', 'lithium', data: ['version' => 'mc1.21.1-0.15.0']);
    expect(mcValue($server, 'MC_MODS'))->toBe('lithium@mc1.21.1-0.15.0,chunky');

    // a version name the setting can't hold goes in by its id
    $page->callTableAction('version', 'lithium', data: ['version' => 'LIT1']);
    expect(mcValue($server, 'MC_MODS'))->toBe('lithium@LIT1,chunky');

    $page->callTableAction('version', 'lithium', data: ['version' => null]);
    expect(mcValue($server, 'MC_MODS'))->toBe('lithium,chunky');

    // a version Modrinth doesn't list for this server is refused
    livewire(Mods::class)
        ->set('activeTab', 'yours')
        ->loadTable()
        ->callTableAction('version', 'lithium', data: ['version' => 'made-up'])
        ->assertHasActionErrors(['version']);
    expect(mcValue($server, 'MC_MODS'))->toBe('lithium,chunky');
});

it('shows a mod Modrinth doesn\'t know so it can be removed', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'lithium,typo-mod']);
    installFile(FABRIC_WITH_LITHIUM);
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)
        ->set('activeTab', 'yours')
        ->loadTable()
        ->assertSee('typo-mod')
        ->assertSee('Modrinth has no mod with this name')
        ->assertTableActionHidden('version', 'typo-mod')
        ->callTableAction('remove', 'typo-mod');

    expect(mcValue($server, 'MC_MODS'))->toBe('lithium');
});

it('installs added mods with a reinstall and no backup', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'lithium,chunky']);
    installFile(FABRIC_WITH_LITHIUM);
    fakeModrinth();
    $this->mock(InitiateBackupService::class)->shouldNotReceive('handle');
    $this->mock(ReinstallServerService::class)->shouldReceive('handle')->once()->andReturn($server);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)
        ->assertActionVisible('install')
        ->mountAction('install')
        ->assertMountedActionModalSee('your world and configs stay')
        ->assertMountedActionModalDontSee('out of the world')
        ->callMountedAction();
});

it('backs up before an install that removes a mod, and reinstalls only after the backup', function () {
    Bus::fake();
    [$user, $server] = generateTestAccount();
    $server->update(['backup_limit' => 3]);
    minecraftServer($server, ['MC_MODS' => 'chunky']);
    installFile(FABRIC_WITH_LITHIUM);
    fakeModrinth();
    $backup = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => null]);
    $this->mock(InitiateBackupService::class, function ($mock) use ($backup) {
        $mock->shouldReceive('setIgnoredFiles')->with([])->andReturnSelf();
        $mock->shouldReceive('handle')->once()->withArgs(fn ($s, $name, $override) => str_starts_with($name, 'Before removing ') && $override === true)->andReturn($backup);
    });
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)
        ->loadTable()
        ->assertSee('out of the world for good')
        ->mountAction('install')
        ->assertMountedActionModalSee('A backup is made first')
        ->assertMountedActionModalSee('doesn\'t bring them back')
        ->callMountedAction();

    Bus::assertDispatched(CallQueuedClosure::class, 1);
});

it('reinstalls once that backup succeeded and installs nothing when it failed', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'chunky']);

    $good = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => now(), 'is_successful' => true]);
    $this->mock(ReinstallServerService::class)->shouldReceive('handle')->once()->andReturn($server);
    Mods::continueInstallAfterBackup($server->id, $good->id, $user->id, 0);

    $bad = Backup::factory()->create(['backup_host_id' => BackupHost::factory()->create()->id, 'server_id' => $server->id, 'completed_at' => now(), 'is_successful' => false]);
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');
    Mods::continueInstallAfterBackup($server->id, $bad->id, $user->id, 0);

    expect(mcValue($server, 'MC_MODS'))->toBe('chunky')
        ->and($user->notifications()->count())->toBe(1)
        ->and($user->notifications()->first()->data['title'])->toContain('Mods were not installed');
});

it('says so when a removal can\'t be backed up, and hides the install from a subuser who may not back up', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'chunky']);
    installFile(FABRIC_WITH_LITHIUM);
    fakeModrinth();
    $server->update(['backup_limit' => 0]);
    $this->mock(InitiateBackupService::class)->shouldNotReceive('handle');
    $this->mock(ReinstallServerService::class)->shouldReceive('handle')->once()->andReturn($server);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)->mountAction('install')->assertMountedActionModalSee('This server has no backups')->callMountedAction();

    $server->update(['backup_limit' => 3]);
    [$helper] = generateTestAccount();
    Subuser::query()->create(['user_id' => $helper->id, 'server_id' => $server->id, 'permissions' => [SubuserPermission::StartupRead->value, SubuserPermission::StartupUpdate->value, SubuserPermission::SettingsReinstall->value]]);
    $this->actingAs($helper);
    livewire(Mods::class)->loadTable()->assertActionHidden('install')->assertSee('ask the owner');
});

it('marks a mod the last install had to skip', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'lithium,chunky']);
    installFile(FABRIC_INSTALL . "mods=lithium,chunky\nmods_skipped=chunky,fabric-api\n");
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)->set('activeTab', 'yours')->loadTable()
        ->assertDontSee('waiting to be installed')
        ->assertSee('Skipped at install (see Console)')
        ->assertSee('Installed');
});

it('lets a subuser without the rights look but not change or install', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::StartupRead]);
    minecraftServer($server, ['MC_MODS' => 'lithium,chunky']);
    installFile(FABRIC_WITH_LITHIUM);
    fakeModrinth();
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)
        ->loadTable()
        ->assertSee('ask the owner')
        ->assertActionHidden('install')
        ->assertTableActionHidden('add', 'create')
        ->assertTableActionHidden('remove', 'chunky');

    expect(mcValue($server, 'MC_MODS'))->toBe('lithium,chunky');
});

it('asks for an install first when the Panel doesn\'t know the Minecraft version, or a modpack is waiting', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server);
    installFile('');
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Mods::class)->loadTable()->assertSee('Install this server once first')->assertDontSee('Chunky');

    Cache::flush();
    $server->serverVariables()->whereHas('variable', fn ($q) => $q->where('env_variable', 'MC_MODPACK'))->update(['variable_value' => 'create_plus']);
    installFile(VANILLA_INSTALL);
    livewire(Mods::class)->loadTable()->assertSee('Install the modpack first')->assertDontSee('Chunky');
    Http::assertNothingSent();
});

it('no longer has a Mods button on Game Settings', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'lithium']);
    installFile(FABRIC_WITH_LITHIUM);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)->assertActionDoesNotExist('loomnode_mods');
});
