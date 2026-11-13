<?php

use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonFileRepository;
use Illuminate\Support\Facades\Http;

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
        'api.modrinth.com/v2/search*' => Http::response(['total_hits' => 45, 'hits' => [
            ['slug' => 'chunky', 'project_id' => 'fALzjamp', 'title' => 'Chunky', 'description' => 'Pre-generates chunks', 'downloads' => 1234567, 'server_side' => 'required', 'client_side' => 'optional',
                'author' => 'pop4959', 'icon_url' => 'https://cdn.modrinth.com/data/fALzjamp/icon.png', 'display_categories' => ['fabric', 'management', 'utility'], 'date_modified' => '2026-10-01T00:00:00Z'],
            ['slug' => 'xaeros-minimap', 'project_id' => '1bokaNcj', 'title' => 'Minimap', 'description' => 'A minimap', 'downloads' => 100, 'server_side' => 'optional', 'client_side' => 'required'],
            ['slug' => 'e4mc', 'project_id' => 'qANg5Jrr', 'title' => 'e4mc', 'description' => 'Tunnel', 'downloads' => 99],
            ['slug' => 'create', 'project_id' => 'LNytGWDc', 'title' => 'Create', 'description' => 'Machines', 'downloads' => 98, 'server_side' => 'required', 'client_side' => 'required',
                'icon_url' => 'https://evil.example/x.png'],
        ]]),
        'api.modrinth.com/v2/projects*' => Http::response([
            ['slug' => 'lithium', 'id' => 'gvQqBUqZ', 'title' => 'Lithium', 'description' => 'Faster game logic', 'downloads' => 5000, 'server_side' => 'optional', 'client_side' => 'optional', 'categories' => ['optimization'], 'updated' => '2026-10-01T00:00:00Z'],
            ['slug' => 'chunky', 'id' => 'fALzjamp', 'title' => 'Chunky', 'description' => 'Pre-generates chunks', 'downloads' => 1234567, 'server_side' => 'required', 'client_side' => 'optional', 'categories' => ['management']],
        ]),
        'api.modrinth.com/v2/project/lithium/version*' => Http::response([
            ['id' => 'LIT2', 'version_number' => 'mc1.21.1-0.15.0', 'version_type' => 'release', 'date_published' => '2026-09-01T00:00:00Z'],
            ['id' => 'LIT1', 'version_number' => 'mc1.21.1 0.14 (old)', 'version_type' => 'beta', 'date_published' => '2026-08-01T00:00:00Z'],
        ]),
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
