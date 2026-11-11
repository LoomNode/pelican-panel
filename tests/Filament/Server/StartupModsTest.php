<?php

use App\Enums\SubuserPermission;
use App\Filament\Server\Pages\Startup;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ReinstallServerService;
use Filament\Facades\Filament;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('server');
    Cache::flush();
});
afterEach(fn () => Filament::setCurrentPanel(null));

/** Give the server's egg the stage 2 Minecraft settings and set their values. */
function minecraftServer(Server $server, array $values = []): Server
{
    $rules = [
        'MC_MODPACK' => ['nullable', 'string', 'max:120'],
        'MC_MODPACK_VERSION' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._+-]+\z/'],
        'MC_MODS' => ['nullable', 'string', 'max:4000', 'regex:/^[A-Za-z0-9_@.+-]+(,[ ]*[A-Za-z0-9_@.+-]+)*\z/'],
        'MC_LOADER' => ['required', 'string', 'in:auto,fabric,neoforge,forge'],
    ];
    $defaults = ['MC_MODPACK' => '', 'MC_MODPACK_VERSION' => 'latest', 'MC_MODS' => '', 'MC_LOADER' => 'auto'];
    $sort = 2;
    foreach ($rules as $env => $rule) {
        EggVariable::query()->create([
            'egg_id' => $server->egg_id, 'name' => $env, 'description' => '', 'env_variable' => $env,
            'default_value' => $defaults[$env], 'user_viewable' => true, 'user_editable' => true, 'rules' => $rule, 'sort' => $sort++,
        ]);
    }
    $server->ensureVariablesExist();
    foreach ($values as $env => $value) {
        $server->serverVariables()->whereHas('variable', fn ($q) => $q->where('env_variable', $env))->update(['variable_value' => $value]);
    }

    return $server;
}

function mcValue(Server $server, string $env): ?string
{
    /** @var ServerVariable $variable */
    $variable = $server->serverVariables()->whereHas('variable', fn ($q) => $q->where('env_variable', $env))->firstOrFail();

    return $variable->variable_value;
}

function installFile(string $content): void
{
    test()->mock(DaemonFileRepository::class, function ($mock) use ($content) {
        $mock->shouldReceive('setServer')->andReturnSelf();
        $mock->shouldReceive('getContent')->with('.loomnode/install', 4096)->andReturn($content);
    });
}

function fakeModrinth(): void
{
    Http::fake([
        'api.modrinth.com/v2/search*' => Http::response(['hits' => [
            ['slug' => 'chunky', 'project_id' => 'fALzjamp', 'title' => 'Chunky', 'description' => 'Pre-generates chunks', 'downloads' => 100, 'server_side' => 'required', 'client_side' => 'optional'],
            ['slug' => 'xaeros-minimap', 'project_id' => '1bokaNcj', 'title' => 'Minimap', 'description' => 'A minimap', 'downloads' => 100, 'server_side' => 'optional', 'client_side' => 'required'],
            ['slug' => 'e4mc', 'project_id' => 'qANg5Jrr', 'title' => 'e4mc', 'description' => 'Tunnel', 'downloads' => 99],
            ['slug' => 'create', 'project_id' => 'LNytGWDc', 'title' => 'Create', 'description' => 'Machines', 'downloads' => 98],
        ]]),
        'api.modrinth.com/v2/project/create_plus/version?*' => Http::response([
            ['id' => 'NEW1', 'version_number' => '6.0.1', 'version_type' => 'release', 'game_versions' => ['1.21.1']],
            ['id' => 'OLD1', 'version_number' => '6.0.0', 'version_type' => 'release', 'game_versions' => ['1.21.1']],
        ]),
        'api.modrinth.com/v2/project/create_plus/version' => Http::response([
            ['id' => 'NEWER', 'version_number' => '7.0.0', 'version_type' => 'release', 'game_versions' => ['1.21.11']],
            ['id' => 'NEW1', 'version_number' => '6.0.1', 'version_type' => 'release', 'game_versions' => ['1.21.1']],
        ]),
    ]);
}

const FABRIC_INSTALL = "type=fabric\nloader=fabric\nminecraft=1.21.1\nloader_version=0.20.1\njava=21\njar=server.jar\npack=\npack_version=\n";
const VANILLA_INSTALL = "type=vanilla\nloader=vanilla\nminecraft=1.21.1\nloader_version=\njava=21\njar=server.jar\npack=\npack_version=\n";
const PACK_INSTALL = "type=modrinth\nloader=neoforge\nminecraft=1.21.1\nloader_version=21.1.233\njava=21\njar=server.jar\npack=Create+\npack_version=6.0.0\npack_slug=create_plus\npack_version_id=OLD1\n";

it('hides the mod and modpack buttons for other games', function () {
    [$user, $server] = generateTestAccount();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)
        ->assertActionHidden('loomnode_mods')
        ->assertActionHidden('loomnode_update_pack');
});

it('adds picked mods, keeps ticked ones, drops unticked ones and never adds tunnel mods', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server, ['MC_MODS' => 'lithium,ferrite-core']);
    installFile(FABRIC_INSTALL);
    fakeModrinth();
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)
        ->assertActionVisible('loomnode_mods')
        ->assertActionHidden('loomnode_update_pack')
        ->callAction('loomnode_mods', data: ['keep' => ['lithium'], 'search' => '', 'add' => ['chunky', 'e4mc'], 'install_now' => false])
        ->assertHasNoActionErrors();

    expect(mcValue($server, 'MC_MODS'))->toBe('lithium,chunky');
});

it('lists server mods from Modrinth for the server\'s loader, without tunnel mods', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server);
    installFile(FABRIC_INSTALL);
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)->mountAction('loomnode_mods')->assertMountedActionModalSee('Chunky')->assertMountedActionModalSee("Pre-generates chunks · players don't need it");

    livewire(Startup::class)->mountAction('loomnode_mods')->assertMountedActionModalDontSee('Tunnel')->assertMountedActionModalDontSee('A minimap');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/search')
        && str_contains(urldecode($request->url()), '"categories:fabric"')
        && str_contains(urldecode($request->url()), '"versions:1.21.1"')
        && str_contains(urldecode($request->url()), '"server_side:required","server_side:optional"')
        && str_contains(urldecode($request->url()), '"categories!=library"'));
});

it('sets the picked loader when a vanilla server gets its first mods', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server);
    installFile(VANILLA_INSTALL);
    fakeModrinth();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)
        ->callAction('loomnode_mods', data: ['loader' => 'neoforge', 'search' => 'create', 'add' => ['create'], 'install_now' => false])
        ->assertHasNoActionErrors();

    expect(mcValue($server, 'MC_MODS'))->toBe('create')
        ->and(mcValue($server, 'MC_LOADER'))->toBe('neoforge');
});

it('reinstalls at once when asked and allowed', function () {
    [$user, $server] = generateTestAccount();
    minecraftServer($server);
    installFile(FABRIC_INSTALL);
    fakeModrinth();
    $this->mock(ReinstallServerService::class)->shouldReceive('handle')->once()->andReturn($server);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)->callAction('loomnode_mods', data: ['search' => '', 'add' => ['chunky'], 'install_now' => true]);

    expect(mcValue($server, 'MC_MODS'))->toBe('chunky');
});

it('does not reinstall for a subuser without the reinstall permission', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::StartupRead, SubuserPermission::StartupUpdate]);
    minecraftServer($server);
    installFile(FABRIC_INSTALL);
    fakeModrinth();
    $this->mock(ReinstallServerService::class)->shouldNotReceive('handle');
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)
        ->assertActionHidden('loomnode_update_pack')
        ->callAction('loomnode_mods', data: ['search' => '', 'add' => ['chunky'], 'install_now' => true]);

    expect(mcValue($server, 'MC_MODS'))->toBe('chunky');
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
