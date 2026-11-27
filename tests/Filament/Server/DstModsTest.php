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
use Illuminate\Support\Sleep;

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

// ---- Options tab ---------------------------------------------------------------------------------------

const OPTIONS_RULE = '/^([0-9]{1,12}:[0-9a-f]{2,400}:(n-?[0-9]+(\.[0-9]+)?|b[01]|s[0-9a-f]{0,800})(;|\z))*\z/';
const OPTIONS_PATH = '/DoNotStarveTogether/config/world/Master/save/loomnode_mod_options';

/** A server whose egg also has the hidden options setting. */
function dstOptionsServer(Server $server, array $values = [], array $more = []): Server
{
    foreach (['DST_MOD_OPTIONS' => ['nullable', 'string', 'max:60000', 'regex:' . OPTIONS_RULE]] + $more as $env => $rule) {
        EggVariable::query()->create([
            'egg_id' => $server->egg_id, 'name' => $env, 'description' => '', 'env_variable' => $env,
            'default_value' => '', 'user_viewable' => false, 'user_editable' => false, 'rules' => $rule, 'sort' => 60,
        ]);
    }

    return dstServer($server, $values);
}

/** The file the game writes: two mods with options, as the real game writes them. */
function dstOptionsFile(array $more = []): string
{
    return 'KLEI     1 ' . json_encode($more + [
        'workshop-' . POSITIONS => ['name' => 'Global Positions', 'version' => '1.7.6', 'options' => [
            ['name' => 'SHOWPLAYERSOPTIONS', 'label' => 'Player Indicators', 'hover' => "The arrow things\nat the edge of the screen.", 'default' => 2, 'options' => [
                ['description' => 'Always', 'data' => 3], ['description' => 'Scoreboard', 'data' => 2, 'hover' => 'x'], ['description' => 'Never', 'data' => 1],
            ]],
            ['name' => 'SHOWPLAYERICONS', 'label' => 'Player Icons', 'hover' => 'Icons on the map.', 'default' => true, 'options' => [
                ['description' => 'Show', 'data' => true], ['description' => 'Hide', 'data' => false],
            ]],
            ['name' => '', 'label' => 'A heading in the game', 'default' => 0, 'options' => [['description' => '', 'data' => 0]]],
        ]],
        'workshop-' . HEALTH => ['name' => 'Health <script>alert(1)</script>', 'version' => '2.2.04', 'options' => [
            ['name' => 'random_health_value', 'label' => 'Chance Fluctuation', 'default' => 0, 'options' => [
                ['description' => '0%', 'data' => 0], ['description' => '5%', 'data' => 0.05],
            ]],
            ['name' => 'say "it"; a:b', 'default' => 'plain', 'options' => [
                ['description' => 'Plain', 'data' => 'plain'], ['description' => 'Fancy', 'data' => 'ünï "q"; a:b'],
            ]],
        ]],
        'workshop-' . MINIMAP => ['name' => 'Minimap HUD', 'version' => '1', 'options' => new stdClass()],
    ]);
}

/**
 * The node for a server with the Options tab: the start script's file, the game's options file (null: not
 * there), and whether the game writes it again when sent the command.
 */
function dstGame(array $mods, string $state = 'running', ?string $file = null, bool $answers = true, int $uptime = 600): void
{
    Sleep::fake();
    $setup = "-- Written by LoomNode\n" . implode('', array_map(fn ($id) => "ServerModSetup(\"$id\")\n", $mods));
    test()->mock(DaemonFileRepository::class, function ($mock) use ($setup, $file, $answers) {
        $lists = 0;
        $mock->shouldReceive('setServer')->andReturnSelf();
        $mock->shouldReceive('getContent')->with('mods/dedicated_server_mods_setup.lua', 65536)->andReturn($setup);
        $content = $mock->shouldReceive('getContent')->with(OPTIONS_PATH, 2097152);
        $file === null ? $content->andThrow(new Exception('no such file')) : $content->andReturn($file);
        // the first look is before the command; the file is new after it when the game answers
        $mock->shouldReceive('getDirectory')->with('/DoNotStarveTogether/config/world/Master/save')->andReturnUsing(function () use (&$lists, $file, $answers) {
            $new = $answers && $lists++ > 0;

            return $file === null ? [] : [['name' => 'session', 'modified' => 'a'], ['name' => 'loomnode_mod_options', 'modified' => $new ? 'later' : 'before', 'size' => 10]];
        });
    });
    test()->mock(DaemonServerRepository::class, function ($mock) use ($state, $uptime) {
        $mock->shouldReceive('setServer')->andReturnSelf();
        $mock->shouldReceive('getDetails')->andReturn(['state' => $state, 'utilization' => ['uptime' => $uptime * 1000]]);
    });
    // before fakeSteam(): the first match answers
    Http::fake(['*/api/servers/*/commands' => Http::response('', 204)]);
    fakeSteam();
}

function hexOption(string $id, string $name, string $token): string
{
    return $id . ':' . bin2hex($name) . ':' . $token;
}

function commandSent(): Closure
{
    return fn ($request) => str_contains($request->url(), '/commands') && $request->data() === ['commands' => [DstMods::OPTIONS_COMMAND]];
}

it('stores mod options in one line and reads them back', function () {
    $choices = [
        POSITIONS => ['SHOWPLAYERSOPTIONS' => 3, 'SHOWPLAYERICONS' => false],
        '99' => ['f' => 0.05, 'tiny' => 1.0E-7, 'big' => -2.5E+3, 'whole' => 3.0, 'on' => true, 'text' => 'ünï "q"; a:b \'x\'', 'empty' => '', '12' => 7],
    ];
    $stored = DstMods::encodeOptions($choices);

    expect($stored)->toBe(implode(';', [
        hexOption('99', 'f', 'n0.05'), hexOption('99', 'tiny', 'n0.0000001'), hexOption('99', 'big', 'n-2500'), hexOption('99', 'whole', 'n3'),
        hexOption('99', 'on', 'b1'), hexOption('99', 'text', 's' . bin2hex('ünï "q"; a:b \'x\'')), hexOption('99', 'empty', 's'), hexOption('99', '12', 'n7'),
        '378160973:53484f57504c41594552534f5054494f4e53:n3;378160973:53484f57504c4159455249434f4e53:b0',
    ]))
        ->and(preg_match(OPTIONS_RULE, $stored))->toBe(1)
        ->and(strpbrk($stored, "\"' \n\\"))->toBeFalse();

    $back = DstMods::decodeOptions($stored);
    expect($back[POSITIONS])->toBe(['SHOWPLAYERSOPTIONS' => 3, 'SHOWPLAYERICONS' => false])
        ->and($back['99'])->toBe(['f' => 0.05, 'tiny' => 1.0E-7, 'big' => -2500, 'whole' => 3, 'on' => true, 'text' => 'ünï "q"; a:b \'x\'', 'empty' => '', 12 => 7])
        ->and(DstMods::encodeOptions($back))->toBe($stored);

    // what can't be stored is left out
    expect(DstMods::encodeOptions(['abc' => ['a' => 1], '1234567890123' => ['a' => 1], '5' => ['a' => null, 'b' => [1], 'c' => INF, 'd' => NAN, str_repeat('n', 201) => 1, 'e' => str_repeat('x', 401), 'ok' => 1.5]]))
        ->toBe(hexOption('5', 'ok', 'n1.5'));

    // a long value still passes the setting's rule
    $long = DstMods::encodeOptions(['7' => array_combine(array_map(fn ($n) => 'option_' . $n, range(1, 1300)), array_fill(0, 1300, 'some text'))]);
    expect(strlen($long))->toBeGreaterThan(50000)->toBeLessThan(60000)
        ->and(preg_match(OPTIONS_RULE, $long))->toBe(1)
        ->and(DstMods::decodeOptions($long)['7'])->toHaveCount(1300);
});

it('reads a stored options value that is broken as empty', function (string $stored) {
    expect(DstMods::decodeOptions($stored))->toBe([]);
})->with([
    'words' => 'not options at all',
    'odd hex' => '5:abc:n1',
    'no value' => '5:6162:',
    'exponent' => '5:6162:n1e5',
    'one bad entry' => '5:6162:n1;5:6163:x9',
    'empty entry' => '5:6162:n1;;5:6163:n2',
    'upper case hex' => '5:6A62:n1',
    'line break' => "5:6162:n1\n",
    'too long' => str_repeat('5:6162:n1;', 6001),
]);

it('takes only what it understands from the game\'s options file', function () {
    expect(DstMods::parseOptionsFile('KLEI     1 ERR something'))->toBeNull()
        ->and(DstMods::parseOptionsFile('KLEI     1 {"workshop-1": '))->toBeNull()
        ->and(DstMods::parseOptionsFile('KLEI     1 ' . json_encode(['workshop-1' => ['name' => str_repeat('x', 2100000)]])))->toBeNull()
        ->and(DstMods::parseOptionsFile('KLEI     1 {}'))->toBe([]);

    $mods = DstMods::parseOptionsFile(dstOptionsFile([
        'workshop-1' => 'a string',
        'workshop-2' => ['name' => ['x'], 'version' => 7, 'options' => 'none'],
        'workshop-3' => ['name' => str_repeat('é', 300), 'options' => ['a' => ['name' => 'x', 'default' => 1, 'options' => [['data' => 1]]]]],
        'not-a-mod' => ['name' => 'x', 'options' => []],
        'workshop-12345678901234' => ['name' => 'x', 'options' => []],
        'workshop-4' => ['options' => [
            'text',
            ['name' => 'no_default', 'options' => [['data' => 1]]],
            ['name' => 'list_default', 'default' => [1], 'options' => [['data' => 1]]],
            ['name' => 'no_choices', 'default' => 1, 'options' => []],
            ['name' => 'object_choices', 'default' => 1, 'options' => ['a' => ['data' => 1]]],
            ['name' => ['x'], 'default' => 1, 'options' => [['data' => 1]]],
            ['name' => 'only_bad_choices', 'default' => 1, 'options' => [['data' => null], ['data' => [1]], 'x', ['description' => 'nothing']]],
            ['name' => 'good', 'label' => ['x'], 'hover' => str_repeat("h\n", 600), 'default' => 1.0, 'options' => [
                ['data' => 1, 'description' => str_repeat('d', 300)], ['data' => null], ['data' => 'two', 'description' => 5], ['data' => false], ['data' => 2.5],
            ]],
            ['name' => 'good', 'default' => 1, 'options' => [['data' => 1]]],
            ['name' => 'many', 'default' => 0, 'options' => array_map(fn ($n) => ['data' => $n], range(0, 249))],
        ]],
        'workshop-5' => ['name' => 'Lots', 'options' => array_map(fn ($n) => ['name' => 'o' . $n, 'default' => 1, 'options' => [['data' => 1]]], range(1, 350))],
    ]));

    expect(array_keys($mods))->toBe([2, 3, 4, 5, (int) POSITIONS, (int) HEALTH, (int) MINIMAP])
        ->and($mods[2])->toBe(['name' => 'Mod 2', 'version' => '7', 'options' => []])
        ->and(mb_strlen($mods[3]['name']))->toBe(200)
        ->and($mods[3]['options'])->toBe([])
        ->and($mods[MINIMAP]['options'])->toBe([])
        ->and(array_column($mods[4]['options'], 'name'))->toBe(['good', 'many'])
        ->and($mods[4]['options'][0]['label'])->toBe('good')
        ->and($mods[4]['options'][0]['default'])->toBe('n1')
        ->and(strlen($mods[4]['options'][0]['hover']))->toBe(500)
        ->and($mods[4]['options'][0]['hover'])->not->toContain("\n")
        ->and(array_column($mods[4]['options'][0]['choices'], 'token'))->toBe(['n1', 's' . bin2hex('two'), 'b0', 'n2.5'])
        ->and(array_column($mods[4]['options'][0]['choices'], 'label'))->toBe([str_repeat('d', 200), '5', 'No', '2.5'])
        ->and($mods[4]['options'][1]['choices'])->toHaveCount(200)
        ->and($mods[5]['options'])->toHaveCount(300)
        ->and(array_column($mods[POSITIONS]['options'], 'label'))->toBe(['Player Indicators', 'Player Icons'])
        ->and($mods[HEALTH]['options'][1]['label'])->toBe('say "it"; a:b');

    $many = [];
    foreach (range(1, 250) as $n) {
        $many['workshop-' . $n] = ['name' => 'M' . $n];
    }
    expect(DstMods::parseOptionsFile('KLEI     1 ' . json_encode($many)))->toHaveCount(200);
});

it('has no Options tab for an egg without the options setting, and asks the game nothing', function () {
    [$user, $server] = generateTestAccount();
    dstServer($server, ['DST_MODS' => POSITIONS]);
    dstGame([POSITIONS], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)
        ->assertDontSeeHtml('tabler-adjustments')
        ->assertSee('I set up mod options myself')
        ->set('activeTab', 'options')
        ->assertSet('activeTab', 'browse')
        ->assertDontSee('Player Indicators');

    Http::assertNotSent(commandSent());
});

it('asks the running game for its mods\' options and shows them as dropdowns', function () {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => POSITIONS . ',' . HEALTH . ',' . MINIMAP . ',' . QUICK_PICK, 'DST_MOD_OPTIONS' => hexOption(HEALTH, 'random_health_value', 'n0.05')]);
    dstGame([POSITIONS, HEALTH, MINIMAP], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(DstMods::class)
        ->assertSeeHtml('tabler-adjustments')
        ->assertDontSee('I set up mod options myself')
        ->assertDontSee('Player Indicators');
    Http::assertNotSent(commandSent());

    $page->set('activeTab', 'options')
        ->assertSee('Global Positions')
        ->assertSee('Player Indicators')
        ->assertSee('The arrow things at the edge of the screen.')
        ->assertSee('Always')
        ->assertSee('Scoreboard (default)')
        ->assertSee('Show (default)')
        ->assertSee('Chance Fluctuation')
        ->assertSee('say "it"; a:b')
        ->assertSee('Health <script>alert(1)</script>')
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('A heading in the game')
        ->assertDontSee('Minimap HUD')
        ->assertDontSee('last time the server ran')
        ->assertSee('Quick Pick: restart the server once so it downloads; its options show here after that.')
        ->assertSet('optionsData.m' . POSITIONS, ['o0' => 'c1', 'o1' => 'c0'])
        ->assertSet('optionsData.m' . HEALTH, ['o0' => 'c1', 'o1' => 'c0'])
        ->assertActionVisible('saveOptions');
    Http::assertSentCount(2); // the command, and Steam for Quick Pick's name
    Http::assertSent(commandSent());

    // kept: the tab again asks nothing
    $page->set('activeTab', 'yours')->set('activeTab', 'options')->assertSee('Player Indicators');
    expect(Http::recorded(commandSent()))->toHaveCount(1);

    // Refresh asks again
    $page->callAction('refreshOptions');
    expect(Http::recorded(commandSent()))->toHaveCount(2);
});

it('saves only the choices that differ from a mod\'s default, and they wait for a restart', function () {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => POSITIONS . ',' . HEALTH]);
    dstGame([POSITIONS, HEALTH], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(DstMods::class)->set('activeTab', 'options')
        ->callAction('saveOptions')
        ->assertNotified('Nothing to save')
        ->assertDontSee('waiting for a restart');
    expect(dstValue($server, 'DST_MOD_OPTIONS'))->toBe('');

    $page->set('optionsData.m' . POSITIONS . '.o0', 'c0')
        ->set('optionsData.m' . POSITIONS . '.o1', 'c1')
        ->set('optionsData.m' . HEALTH . '.o1', 'c1')
        ->callAction('saveOptions')
        ->assertNotified('Mod options saved')
        ->assertSee('1 change waiting for a restart')
        ->assertSee('Mod options changed')
        ->assertActionVisible('restart');

    $stored = dstValue($server, 'DST_MOD_OPTIONS');
    expect($stored)->toBe(implode(';', [
        hexOption(HEALTH, 'say "it"; a:b', 's' . bin2hex('ünï "q"; a:b')),
        hexOption(POSITIONS, 'SHOWPLAYERSOPTIONS', 'n3'),
        hexOption(POSITIONS, 'SHOWPLAYERICONS', 'b0'),
    ]))->and(preg_match(OPTIONS_RULE, $stored))->toBe(1);

    // a new page shows the saved choices, and the change still waits
    $again = livewire(DstMods::class)
        ->assertSee('Mod options changed')
        ->set('activeTab', 'options')
        ->assertSet('optionsData.m' . POSITIONS, ['o0' => 'c0', 'o1' => 'c1'])
        ->assertSet('optionsData.m' . HEALTH, ['o0' => 'c0', 'o1' => 'c1']);

    // back to the defaults, as the server started: nothing stored, nothing waiting
    $again->set('optionsData.m' . POSITIONS . '.o0', 'c1')
        ->set('optionsData.m' . POSITIONS . '.o1', 'c0')
        ->set('optionsData.m' . HEALTH . '.o1', 'c0')
        ->callAction('saveOptions')
        ->assertDontSee('waiting for a restart');
    expect(dstValue($server, 'DST_MOD_OPTIONS'))->toBe('');
});

it('stops saying the options wait once the server has started again', function () {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => POSITIONS]);
    dstGame([POSITIONS], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')
        ->set('optionsData.m' . POSITIONS . '.o0', 'c2')
        ->callAction('saveOptions')
        ->assertSee('Mod options changed');

    $this->travel(5)->minutes();
    livewire(DstMods::class)->assertSee('Mod options changed');

    // 20 minutes on, the node says the server has been up for 10: it started after the change
    $this->travel(15)->minutes();
    livewire(DstMods::class)->assertDontSee('waiting for a restart');
    expect(dstValue($server, 'DST_MOD_OPTIONS'))->toBe(hexOption(POSITIONS, 'SHOWPLAYERSOPTIONS', 'n1'));
});

it('ignores a choice the mod doesn\'t offer', function () {
    [$user, $server] = generateTestAccount();
    $saved = hexOption(POSITIONS, 'SHOWPLAYERICONS', 'b0');
    dstOptionsServer($server, ['DST_MODS' => POSITIONS, 'DST_MOD_OPTIONS' => $saved]);
    dstGame([POSITIONS], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')
        ->set('optionsData.m' . POSITIONS . '.o0', 'c7')
        ->set('optionsData.m' . POSITIONS . '.o1', '0); os.execute("x") --')
        ->set('optionsData.m' . POSITIONS . '.o9', 'c0')
        ->set('optionsData.m' . QUICK_PICK . '.o0', 'c0')
        ->set('optionsData.m' . POSITIONS . '.o0', ['c0'])
        ->callAction('saveOptions');

    expect(dstValue($server, 'DST_MOD_OPTIONS'))->toBe($saved);
});

it('keeps what is saved for a mod whose options aren\'t known, and drops it for a mod that is gone', function () {
    [$user, $server] = generateTestAccount();
    $unknown = hexOption(QUICK_PICK, 'speed', 'n2');
    dstOptionsServer($server, ['DST_MODS' => POSITIONS . ',' . QUICK_PICK, 'DST_MOD_OPTIONS' => hexOption(GONE, 'x', 'b1') . ';' . hexOption(POSITIONS, 'old_option', 'n9') . ';' . $unknown]);
    dstGame([POSITIONS], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')
        ->set('optionsData.m' . POSITIONS . '.o0', 'c0')
        ->callAction('saveOptions')
        ->assertNotified('Mod options saved');

    expect(dstValue($server, 'DST_MOD_OPTIONS'))->toBe(hexOption(POSITIONS, 'SHOWPLAYERSOPTIONS', 'n3') . ';' . $unknown);
});

it('shows defaults when the stored options are broken, and saving puts them right', function () {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => POSITIONS, 'DST_MOD_OPTIONS' => 'return { broken = true }']);
    dstGame([POSITIONS], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')
        ->assertSet('optionsData.m' . POSITIONS, ['o0' => 'c1', 'o1' => 'c0'])
        ->set('optionsData.m' . POSITIONS . '.o1', 'c1')
        ->callAction('saveOptions');

    expect(dstValue($server, 'DST_MOD_OPTIONS'))->toBe(hexOption(POSITIONS, 'SHOWPLAYERICONS', 'b0'));
});

it('refuses to save more options than the setting holds', function () {
    [$user, $server] = generateTestAccount();
    $full = DstMods::encodeOptions([QUICK_PICK => array_combine(array_map(fn ($n) => 'option_' . $n, range(1, 72)), array_fill(0, 72, str_repeat('x', 400))) + ['last' => str_repeat('x', 64)]]);
    dstOptionsServer($server, ['DST_MODS' => POSITIONS . ',' . QUICK_PICK, 'DST_MOD_OPTIONS' => $full]);
    dstGame([POSITIONS], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    expect(strlen($full))->toBeGreaterThan(59950)->toBeLessThanOrEqual(60000);
    livewire(DstMods::class)->set('activeTab', 'options')
        ->set('optionsData.m' . POSITIONS . '.o0', 'c0')
        ->set('optionsData.m' . POSITIONS . '.o1', 'c1')
        ->callAction('saveOptions')
        ->assertNotified('Too many options to save');

    expect(dstValue($server, 'DST_MOD_OPTIONS'))->toBe($full);
});

it('drops a mod\'s saved options when the mod is removed', function () {
    [$user, $server] = generateTestAccount();
    $kept = hexOption(HEALTH, 'random_health_value', 'n0.05');
    dstOptionsServer($server, ['DST_MODS' => POSITIONS . ',' . HEALTH, 'DST_MOD_OPTIONS' => $kept . ';' . hexOption(POSITIONS, 'SHOWPLAYERSOPTIONS', 'n3') . ';' . hexOption(POSITIONS, 'SHOWPLAYERICONS', 'b0')]);
    dstGame([POSITIONS, HEALTH], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'yours')->loadTable()
        ->callTableAction('remove', 'w' . POSITIONS)
        ->assertSee('1 change waiting for a restart')
        ->assertDontSee('options changed');

    expect(dstValue($server, 'DST_MODS'))->toBe(HEALTH)
        ->and(dstValue($server, 'DST_MOD_OPTIONS'))->toBe($kept);
});

it('never sends the command to a server that isn\'t running', function (string $state, string $heading) {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => POSITIONS]);
    dstGame([POSITIONS], $state);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')
        ->assertSee($heading)
        ->assertDontSee('Player Indicators')
        ->callAction('refreshOptions')
        ->assertSee($heading);

    Http::assertNotSent(commandSent());
})->with([
    ['offline', 'Start the server to see its mods\' options'],
    ['stopping', 'Start the server to see its mods\' options'],
    ['starting', 'The server is still starting'],
]);

it('says when the owner looks after mod options by hand, since the choices are then not used', function (string $own, bool $says) {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => POSITIONS, 'DST_MODS_OWN' => $own], ['DST_MODS_OWN' => ['required', 'boolean']]);
    dstGame([POSITIONS], 'offline', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    $page = livewire(DstMods::class)->set('activeTab', 'options')->assertSee('Player Indicators');
    $says ? $page->assertSee('so the choices here are not used') : $page->assertDontSee('so the choices here are not used');
})->with([['1', true], ['0', false]]);

it('shows a stopped server\'s options from the file its last run left, and saves them', function () {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => POSITIONS . ',' . QUICK_PICK]);
    dstGame([POSITIONS], 'offline', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')
        ->assertSee('These options are from the last time the server ran.')
        ->assertSee('Player Indicators')
        ->assertSee('Quick Pick: start the server once so it downloads; its options show here after that.')
        ->set('optionsData.m' . POSITIONS . '.o0', 'c2')
        ->callAction('saveOptions')
        ->assertNotified('Mod options saved')
        ->assertSee('2 changes waiting for a restart')
        ->assertSee('1 mod to add, mod options changed')
        ->callAction('refreshOptions')
        ->assertSee('Player Indicators');

    expect(dstValue($server, 'DST_MOD_OPTIONS'))->toBe(hexOption(POSITIONS, 'SHOWPLAYERSOPTIONS', 'n1'));
    Http::assertNotSent(commandSent());
});

it('falls back to the file from an earlier run when the running game doesn\'t answer', function () {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => POSITIONS]);
    dstGame([POSITIONS], 'running', dstOptionsFile(), answers: false);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')
        ->assertSee('The game didn\'t answer just now')
        ->assertSee('Player Indicators');
    Http::assertSent(commandSent());
    Sleep::assertSleptTimes(8);
});

it('says so when the running game has no answer and no file', function () {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => POSITIONS]);
    dstGame([POSITIONS], 'running', null);
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')->assertSee('The game didn\'t answer');
});

it('says when the mods are new and when none has options', function () {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => QUICK_PICK]);
    dstGame([], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')
        ->assertSee('Restart the server once so the new mods load; their options show here after that')
        ->assertDontSee('Player Indicators');

    $server->serverVariables()->whereHas('variable', fn ($q) => $q->where('env_variable', 'DST_MODS'))->update(['variable_value' => MINIMAP]);
    livewire(DstMods::class)->set('activeTab', 'options')->assertSee('None of this server\'s mods have options');
});

it('reads the options file from the server\'s own world folder, and not from a path that isn\'t a plain name', function (string $world, bool $read) {
    [$user, $server] = generateTestAccount();
    dstOptionsServer($server, ['DST_MODS' => POSITIONS, 'DST_WORLD' => $world], ['DST_WORLD' => ['nullable', 'string']]);
    Sleep::fake();
    $this->mock(DaemonFileRepository::class, function ($mock) use ($read) {
        $mock->shouldReceive('setServer')->andReturnSelf();
        $mock->shouldReceive('getContent')->with('mods/dedicated_server_mods_setup.lua', 65536)->andReturn('');
        $mock->shouldReceive('getContent')->with('/DoNotStarveTogether/config/My-World_2/Master/save/loomnode_mod_options', 2097152)->times($read ? 1 : 0)->andReturn(dstOptionsFile());
    });
    $this->mock(DaemonServerRepository::class, function ($mock) {
        $mock->shouldReceive('setServer')->andReturnSelf();
        $mock->shouldReceive('getDetails')->andReturn(['state' => 'offline']);
    });
    fakeSteam();
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')->assertSee($read ? 'Player Indicators' : 'Start the server to see its mods\' options');
})->with([
    ['My-World_2', true],
    ['../../My-World_2', false],
    ['-World', false],
]);

it('shows a subuser without the rights the options, but saves nothing for them', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::StartupRead]);
    dstOptionsServer($server, ['DST_MODS' => POSITIONS]);
    dstGame([POSITIONS], 'running', dstOptionsFile());
    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(DstMods::class)->set('activeTab', 'options')
        ->assertSee('Player Indicators')
        ->assertSeeHtml('disabled="disabled" id="content.modOptions.optionsm' . POSITIONS . '.m' . POSITIONS . '.o0" wire:model="optionsData.m' . POSITIONS . '.o0"')
        ->assertActionHidden('saveOptions')
        ->set('optionsData.m' . POSITIONS . '.o0', 'c0')
        ->assertActionHidden('saveOptions');

    expect(dstValue($server, 'DST_MOD_OPTIONS'))->toBe('');
});
