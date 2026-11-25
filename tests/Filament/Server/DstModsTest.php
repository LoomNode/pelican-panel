<?php

use App\Enums\SubuserPermission;
use App\Filament\Server\Pages\DstMods;
use App\Filament\Server\Pages\Mods;
use App\Models\EggVariable;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

use function Pest\Livewire\livewire;

beforeEach(function () {
    Filament::setCurrentPanel('server');
    Cache::flush();
});
afterEach(fn () => Filament::setCurrentPanel(null));

const HEALTH = '375859599';       // all players need it
const QUICK_PICK = '501385076';   // server only
const POSITIONS = '378160973';    // needs HEALTH (says the search)
const MINIMAP = '345692228';      // only for a player's own game
const COLLECTION = '2942929819';
const OTHER_GAME = '111111111';
const GONE = '222222222';

/** Give the server's egg the Don't Starve Together mod settings and set their values. */
function dstServer(Server $server, array $values = []): Server
{
    $rules = [
        'DST_MODS' => ['nullable', 'string', 'max:2000', 'regex:/^[0-9, ]*\z/'],
        'DST_MOD_COLLECTION' => ['nullable', 'string', 'max:20', 'regex:/^[0-9]*\z/'],
    ];
    $sort = 50;
    foreach ($rules as $env => $rule) {
        EggVariable::query()->create([
            'egg_id' => $server->egg_id, 'name' => $env, 'description' => '', 'env_variable' => $env,
            'default_value' => '', 'user_viewable' => true, 'user_editable' => true, 'rules' => $rule, 'sort' => $sort++,
        ]);
    }
    $server->ensureVariablesExist();
    foreach ($values as $env => $value) {
        $server->serverVariables()->whereHas('variable', fn ($q) => $q->where('env_variable', $env))->update(['variable_value' => $value]);
    }

    return $server;
}

function dstValue(Server $server, string $env): ?string
{
    return $server->serverVariables()->whereHas('variable', fn ($q) => $q->where('env_variable', $env))->firstOrFail()->variable_value;
}

/** What the start script wrote at the last start, and whether the server runs. */
function dstStarted(array $mods = [], string $collection = '', string $state = 'running'): void
{
    $file = "-- Written by LoomNode\n" . implode('', array_map(fn ($id) => "ServerModSetup(\"$id\")\n", $mods))
        . ($collection !== '' ? "ServerModCollectionSetup(\"$collection\")\n" : '');
    test()->mock(DaemonFileRepository::class, function ($mock) use ($file) {
        $mock->shouldReceive('setServer')->andReturnSelf();
        $mock->shouldReceive('getContent')->with('mods/dedicated_server_mods_setup.lua', 65536)->andReturn($file);
    });
    test()->mock(DaemonServerRepository::class, function ($mock) use ($state) {
        $mock->shouldReceive('setServer')->andReturnSelf();
        $mock->shouldReceive('getDetails')->andReturn(['state' => $state]);
    });
}

function workshopItem(string $id, string $title, array $tags, array $more = []): array
{
    return $more + [
        'publishedfileid' => $id, 'title' => $title, 'consumer_appid' => 322330, 'consumer_app_id' => '322330', 'creator_app_id' => '245850',
        'file_type' => 0, 'creator' => '7656', 'subscriptions' => '4702434', 'time_updated' => '1742515573', 'result' => 1, 'num_children' => 0,
        'preview_url' => 'https://images.steamusercontent.com/ugc/27366287301149683/3BAAF0CC0FFEE0003BAAF0CC0FFEE000/',
        'short_description' => '[h1]' . $title . '[/h1] does things. https://example.com/x', 'description' => '[b]' . $title . '[/b] does things.',
        'tags' => array_map(fn ($tag) => ['tag' => $tag, 'display_name' => $tag], $tags), 'children' => [],
    ];
}

function workshopPage(array $results, int $total = 45): string
{
    $queries = ['queries' => [
        ['queryKey' => ['apphub', 322330], 'state' => ['data' => ['results' => 'not this one']]],
        ['queryKey' => ['workshop_browse', ['appid' => 322330], 1], 'state' => ['data' => [
            'eresult' => 1, 'total_count' => $total, 'results' => $results,
            'creator_player_link_details' => [['public_data' => ['steamid' => '7656', 'persona_name' => 'Klei fan']]],
        ]]],
    ]];

    return '<html><body><div>Workshop</div><script type="application/json" id="valve-ssr-data" nonce="x">'
        . json_encode(['route' => '/workshop/browse', 'renderContext' => ['queryData' => json_encode($queries)]])
        . '</script></body></html>';
}

function fakeSteam(): void
{
    $items = [
        HEALTH => workshopItem(HEALTH, 'Health Info', ['all_clients_require_mod', 'interface', 'version:2']),
        QUICK_PICK => workshopItem(QUICK_PICK, 'Quick Pick', ['server_only_mod', 'tweak', 'Other']),
        POSITIONS => workshopItem(POSITIONS, 'Global Positions', ['all_clients_require_mod', 'utility'], ['children' => [['publishedfileid' => HEALTH]], 'num_children' => 1]),
        MINIMAP => workshopItem(MINIMAP, 'Minimap HUD', ['client_only_mod', 'interface']),
        COLLECTION => workshopItem(COLLECTION, 'Quality of Life', [], ['file_type' => 2, 'creator_app_id' => '766', 'num_children' => 2]),
        OTHER_GAME => workshopItem(OTHER_GAME, 'A Skyrim sword', [], ['consumer_appid' => 72850, 'consumer_app_id' => '72850']),
    ];

    Http::fake([
        'steamcommunity.com/workshop/browse/*' => function ($request) use ($items) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            if (($query['section'] ?? '') === 'collections') {
                return Http::response(workshopPage([$items[COLLECTION], $items[HEALTH]], 2));
            }

            return Http::response(workshopPage([$items[QUICK_PICK], $items[POSITIONS], $items[OTHER_GAME], ['publishedfileid' => 'x'], $items[COLLECTION]]));
        },
        'api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/*' => function ($request) use ($items) {
            $answer = [];
            foreach ((array) ($request->data()['publishedfileids'] ?? []) as $id) {
                $answer[] = $items[$id] ?? ['publishedfileid' => (string) $id, 'result' => 9];
            }

            return Http::response(['response' => ['publishedfiledetails' => $answer]]);
        },
        'api.steampowered.com/ISteamRemoteStorage/GetCollectionDetails/*' => Http::response(['response' => ['collectiondetails' => [
            ['publishedfileid' => COLLECTION, 'result' => 1, 'children' => [['publishedfileid' => HEALTH], ['publishedfileid' => QUICK_PICK]]],
        ]]]),
        '*' => Http::response('', 500),
    ]);
}

it('is only there for a server with the Don\'t Starve Together mod settings', function () {
    [$user, $server] = generateTestAccount();
    $this->actingAs($user);
    Filament::setTenant($server);

    expect(DstMods::canAccess())->toBeFalse();
    livewire(DstMods::class)->assertForbidden();

    dstServer($server);
    expect(DstMods::canAccess())->toBeTrue()
        ->and(Mods::canAccess())->toBeFalse();
});

it('is hidden from a subuser who can\'t see the game settings', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::WebsocketConnect]);
    dstServer($server);
    $this->actingAs($user);
    Filament::setTenant($server);

    expect(DstMods::canAccess())->toBeFalse();
    livewire(DstMods::class)->assertForbidden();
});

it('lists Workshop mods for this game from Steam\'s search page, with pictures through the Panel', function () {
    [$user, $server] = generateTestAccount();
    dstServer($server);
    dstStarted();
    fakeSteam();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)
        ->loadTable()
        ->assertSee('Quick Pick')
        ->assertSee('Quick Pick does things.')
        ->assertSee('Klei fan')
        ->assertSee('4.7M subscribers')
        ->assertSee('Server only')
        ->assertSee('Sent to players')
        ->assertSee('Tweaks')
        ->assertSee('/workshop-icon/27366287301149683/3BAAF0CC0FFEE0003BAAF0CC0FFEE000')
        ->assertDontSee('steamusercontent.com')
        ->assertDontSee('example.com')
        ->assertDontSee('[h1]')
        ->assertDontSee('Skyrim')
        ->assertDontSee('Quality of Life')
        ->assertDontSee('waiting for a restart');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'steamcommunity.com/workshop/browse/')
        && str_contains(urldecode($request->url()), 'appid=322330')
        && str_contains(urldecode($request->url()), 'section=readytouseitems')
        && str_contains(urldecode($request->url()), 'excludedtags[0]=client_only_mod')
        && str_contains(urldecode($request->url()), 'browsesort=totaluniquesubscribers')
        && str_contains(urldecode($request->url()), 'p=1')
        && !str_contains($request->header('User-Agent')[0] ?? '', 'LoomNode'));
});

it('searches, filters and sorts through Steam', function () {
    [$user, $server] = generateTestAccount();
    dstServer($server);
    dstStarted();
    fakeSteam();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)
        ->loadTable()
        ->searchTable('pick')
        ->filterTable('kind', 'tweak')
        ->filterTable('players', 'server_only_mod')
        ->assertSee('Quick Pick');

    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'searchtext=pick')
        && str_contains(urldecode($request->url()), 'browsesort=textsearch')
        && str_contains(urldecode($request->url()), 'requiredtags[0]=tweak')
        && str_contains(urldecode($request->url()), 'requiredtags[1]=server_only_mod'));

    livewire(DstMods::class)->loadTable()->filterTable('sort', 'trend')->assertSee('Quick Pick');
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'browsesort=trend') && str_contains(urldecode($request->url()), 'days=30'));
});

it('says so when Steam\'s page no longer carries the answer', function () {
    [$user, $server] = generateTestAccount();
    dstServer($server);
    dstStarted();
    Http::fake(['*' => Http::response('<html><body>A new page</body></html>')]);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->loadTable()->assertSee('Steam can\'t be reached right now');
});

it('adds a mod with the mods it needs, removes one, and says what waits for a restart', function () {
    [$user, $server] = generateTestAccount();
    dstServer($server, ['DST_MODS' => QUICK_PICK]);
    dstStarted([QUICK_PICK]);
    fakeSteam();
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(DstMods::class)
        ->loadTable()
        ->assertDontSee('waiting for a restart')
        ->assertTableActionVisible('add', 'w' . POSITIONS)
        ->assertTableActionHidden('remove', 'w' . POSITIONS)
        ->assertTableActionHidden('add', 'w' . QUICK_PICK)
        ->assertTableActionVisible('remove', 'w' . QUICK_PICK)
        ->callTableAction('add', 'w' . POSITIONS);

    expect(dstValue($server, 'DST_MODS'))->toBe(QUICK_PICK . ',' . POSITIONS . ',' . HEALTH);

    $page->assertSee('2 changes waiting for a restart')
        ->assertSee('2 mods to add')
        ->assertActionVisible('restart')
        ->set('activeTab', 'yours')
        ->assertSee('Health Info')
        ->assertSee('Installed')
        ->assertSee('Waiting for restart')
        ->callTableAction('remove', 'w' . QUICK_PICK);

    expect(dstValue($server, 'DST_MODS'))->toBe(POSITIONS . ',' . HEALTH);
    $page->assertSee('3 changes waiting for a restart')->assertSee('2 mods to add, 1 to take out');
});

it('adds mods by link or ID, and only public mods for this game', function () {
    [$user, $server] = generateTestAccount();
    dstServer($server);
    dstStarted();
    fakeSteam();
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(DstMods::class)
        ->callAction('addByLink', ['mods' => 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . HEALTH . "&searchtext=health\n" . QUICK_PICK . ', ' . OTHER_GAME . ' ' . GONE . ' ' . COLLECTION . ' 12']);

    expect(dstValue($server, 'DST_MODS'))->toBe(HEALTH . ',' . QUICK_PICK);
    $page->assertNotified('Health Info and 1 more added');

    $page->callAction('addByLink', ['mods' => OTHER_GAME]);
    expect(dstValue($server, 'DST_MODS'))->toBe(HEALTH . ',' . QUICK_PICK);
    $page->assertNotified('Not a Don\'t Starve Together mod');
});

it('adds nothing when Steam can\'t say what an ID is', function () {
    [$user, $server] = generateTestAccount();
    dstServer($server);
    dstStarted();
    Http::fake(['*' => Http::response('', 500)]);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->callAction('addByLink', ['mods' => HEALTH])->assertNotified('Steam can\'t be reached right now');
    expect(dstValue($server, 'DST_MODS'))->toBe('');
});

it('shows a listed mod Steam doesn\'t know, and one only for a player\'s own game, so they can be removed', function () {
    [$user, $server] = generateTestAccount();
    dstServer($server, ['DST_MODS' => GONE . ',' . MINIMAP]);
    dstStarted([GONE, MINIMAP]);
    fakeSteam();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)
        ->set('activeTab', 'yours')
        ->loadTable()
        ->assertSee('Mod ' . GONE)
        ->assertSee('Not on Steam any more')
        ->assertSee('Minimap HUD')
        ->assertSee('Does nothing on a server')
        ->assertTableActionHidden('add', 'w' . GONE)
        ->callTableAction('remove', 'w' . GONE);

    expect(dstValue($server, 'DST_MODS'))->toBe(MINIMAP);
});

it('browses collections, uses one, lists its mods and stops using it', function () {
    [$user, $server] = generateTestAccount();
    dstServer($server, ['DST_MODS' => HEALTH]);
    dstStarted([HEALTH]);
    fakeSteam();
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(DstMods::class)
        ->set('activeTab', 'collection')
        ->loadTable()
        ->assertSee('No collection')
        ->assertSee('Quality of Life')
        ->assertSee('2 mods')
        ->assertDontSee('Health Info')
        ->assertTableActionHidden('add', 'w' . COLLECTION)
        ->callTableAction('useCollection', 'w' . COLLECTION);

    expect(dstValue($server, 'DST_MOD_COLLECTION'))->toBe(COLLECTION);
    Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), 'section=collections') && !str_contains(urldecode($request->url()), 'excludedtags'));

    $page->assertSee('Quality of Life · waiting for restart')
        ->assertSee('1 change waiting for a restart')
        ->assertSee('A collection to add')
        ->assertSee('Health Info')
        ->assertSee('Quick Pick')
        ->assertTableActionHidden('add', 'w' . QUICK_PICK)
        ->assertTableActionHidden('remove', 'w' . HEALTH)
        ->callAction('removeCollection');

    expect(dstValue($server, 'DST_MOD_COLLECTION'))->toBe('')
        ->and(dstValue($server, 'DST_MODS'))->toBe(HEALTH);
});

it('takes a collection by link, and not a single mod', function () {
    [$user, $server] = generateTestAccount();
    dstServer($server);
    dstStarted();
    fakeSteam();
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(DstMods::class)->set('activeTab', 'collection')
        ->callAction('collectionLink', ['collection' => HEALTH]);
    expect(dstValue($server, 'DST_MOD_COLLECTION'))->toBe('');
    $page->assertNotified('Not a Don\'t Starve Together collection');

    $page->callAction('collectionLink', ['collection' => 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . COLLECTION]);
    expect(dstValue($server, 'DST_MOD_COLLECTION'))->toBe(COLLECTION);
});

it('restarts a running server and starts a stopped one', function (string $state, string $power, string $label) {
    [$user, $server] = generateTestAccount();
    dstServer($server, ['DST_MODS' => HEALTH]);
    dstStarted([], '', $state);
    fakeSteam();
    $this->mock(DaemonServerRepository::class, function ($mock) use ($state, $power) {
        $mock->shouldReceive('setServer')->andReturnSelf();
        $mock->shouldReceive('getDetails')->andReturn(['state' => $state]);
        $mock->shouldReceive('power')->once()->with($power);
    });
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)
        ->assertSee('1 change waiting for a restart')
        ->assertActionHasLabel('restart', $label)
        ->callAction('restart')
        ->assertRedirect();
})->with([
    ['running', 'restart', 'Restart now'],
    ['offline', 'start', 'Start the server'],
]);

it('lets a subuser without the rights look but not change or restart', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::StartupRead]);
    dstServer($server, ['DST_MODS' => HEALTH, 'DST_MOD_COLLECTION' => COLLECTION]);
    dstStarted();
    fakeSteam();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)
        ->loadTable()
        ->assertSee('2 changes waiting for a restart')
        ->assertActionHidden('restart')
        ->assertActionHidden('addByLink')
        ->assertTableActionHidden('add', 'w' . QUICK_PICK)
        ->set('activeTab', 'yours')
        ->assertTableActionHidden('remove', 'w' . HEALTH)
        ->set('activeTab', 'collection')
        ->assertActionHidden('removeCollection')
        ->assertActionHidden('collectionLink');

    expect(dstValue($server, 'DST_MODS'))->toBe(HEALTH);
});

it('says the list is full instead of saving a list the setting can\'t hold', function () {
    [$user, $server] = generateTestAccount();
    $full = implode(',', array_map(fn ($n) => (string) (3000000000 + $n), range(1, 182)));
    dstServer($server, ['DST_MODS' => $full]);
    dstStarted();
    fakeSteam();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->callAction('addByLink', ['mods' => HEALTH])->assertNotified('The mod list is full');
    expect(dstValue($server, 'DST_MODS'))->toBe($full);
});
