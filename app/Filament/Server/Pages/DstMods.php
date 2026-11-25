<?php

namespace App\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Traits\Filament\CanCustomizeHeaderActions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\ArrayRecord;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Mods for a Don't Starve Together server (LoomNode, 2026-11-25; before that three text boxes in Game Settings,
 * which still work).
 *
 * Browse searches the game's Steam Workshop; Add and Remove write the "Workshop mods" setting (DST_MODS:
 * Workshop IDs, comma-separated) and the Collection tab the "Workshop collection" setting (DST_MOD_COLLECTION).
 * Nothing is downloaded here: the game fetches its mods at every start, so a change needs a restart, not a
 * reinstall. Shown only for a server whose egg has both settings.
 *
 * Steam gives its Workshop search only to holders of a Web API key, which LoomNode doesn't have (Kyle, 11-25).
 * So the search reads the public Workshop page, which carries the same answer as data; looking a mod or a
 * collection up by its ID uses Steam's keyless API. If the page ever changes shape, Browse says Steam can't be
 * reached and "Add by link or ID" still works.
 */
class DstMods extends Page implements HasTable
{
    use CanCustomizeHeaderActions;
    use InteractsWithTable;

    public const APP = 322330;

    private const COLLECTION_APP = 766;

    private const PER_PAGE = 30;

    private const SETTINGS = ['DST_MODS', 'DST_MOD_COLLECTION'];

    private const SETUP_FILE = 'mods/dedicated_server_mods_setup.lua';

    private const USER_AGENT = 'Mozilla/5.0 (compatible; mod-browser)';

    /** The Workshop's own kinds, in plain words. */
    private const KINDS = [
        'character' => 'Characters', 'item' => 'Items', 'creature' => 'Creatures', 'pet' => 'Pets',
        'environment' => 'Environment', 'interface' => 'Interface', 'utility' => 'Utility', 'tweak' => 'Tweaks',
        'worldgen' => 'World generation', 'art' => 'Art', 'scenario' => 'Scenarios', 'language' => 'Language', 'other' => 'Other',
    ];

    private const SORTS = [
        'totaluniquesubscribers' => 'Most subscribed', 'trend' => 'Popular this month',
        'lastupdated' => 'Recently updated', 'mostrecent' => 'Newest',
    ];

    private const FOR_PLAYERS = ['server_only_mod' => 'Server only', 'all_clients_require_mod' => 'Sent to players'];

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Puzzle;

    // right after Game Settings (10), where the Minecraft Mods page sits for a Minecraft server
    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'workshop-mods';

    public ?string $activeTab = 'browse';

    /** Set while the table loads: Steam didn't answer. */
    private bool $steamDown = false;

    /** @var array{known: bool, mods: string[], collection: string}|null */
    private ?array $started = null;

    public static function hasDstMods(Server $server): bool
    {
        $env = $server->egg->variables()->pluck('env_variable')->all();

        return in_array('DST_MODS', $env, true) && in_array('DST_MOD_COLLECTION', $env, true);
    }

    public static function canAccess(): bool
    {
        /** @var ?Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && $server !== null && !$server->isInConflictState()
            && user()?->can(SubuserPermission::StartupRead, $server) && self::hasDstMods($server);
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::StartupRead, $this->getRecord()) && self::hasDstMods($this->getRecord()), 403);
    }

    public function getRecord(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    public function getTitle(): string
    {
        return 'Mods';
    }

    public static function getNavigationLabel(): string
    {
        return 'Mods';
    }

    private function canEdit(): bool
    {
        return (bool) user()?->can(SubuserPermission::StartupUpdate, $this->getRecord());
    }

    // ---- The two settings ------------------------------------------------------------------------------

    private function variable(string $env): ?ServerVariable
    {
        abort_unless(in_array($env, self::SETTINGS, true), 403);

        $server = $this->getRecord();
        $server->ensureVariablesExist();

        /** @var ?ServerVariable $variable */
        $variable = $server->serverVariables()
            ->whereHas('variable', fn (Builder $query) => $query->where('env_variable', $env))
            ->first();

        return $variable;
    }

    /** Saves a setting the way Game Settings does: the setting's own rules, the activity log. */
    private function setVariable(string $env, string $value): bool
    {
        abort_unless($this->canEdit(), 403);
        $variable = $this->variable($env);
        abort_unless($variable !== null, 403);

        $original = (string) $variable->variable_value;
        if ($original === $value) {
            return true;
        }

        $validator = Validator::make(['variable_value' => $value], ['variable_value' => $variable->variable->rules]);
        if ($validator->fails()) {
            Notification::make()
                ->title($env === 'DST_MODS' ? 'The mod list is full' : 'That collection can\'t be saved')
                ->body($env === 'DST_MODS' ? 'Remove a mod first, or put your mods in a Steam Workshop collection and use the Collection tab.' : implode(', ', $validator->errors()->all()))
                ->danger()
                ->send();

            return false;
        }

        $variable->forceFill(['variable_value' => $value])->saveOrFail();
        Activity::event('server:startup.edit')
            ->property([
                'variable' => $env,
                'old' => EggVariable::forActivity($env, $original),
                'new' => EggVariable::forActivity($env, $value),
            ])
            ->log();

        return true;
    }

    /** @return string[] the Workshop mods setting as a list of IDs */
    private function currentMods(): array
    {
        $value = (string) $this->variable('DST_MODS')?->variable_value;

        return array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), fn ($id) => self::isId($id))));
    }

    private function collection(): string
    {
        $value = trim((string) $this->variable('DST_MOD_COLLECTION')?->variable_value);

        return self::isId($value) ? $value : '';
    }

    private static function isId(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[0-9]{1,20}$/', $id) === 1;
    }

    // ---- What the server last started with -----------------------------------------------------------------

    /**
     * The mod list the start script wrote at the last start (it rewrites the file at every start). Not known
     * when the server has never started or the node can't be reached: then everything counts as waiting.
     *
     * @return array{known: bool, mods: string[], collection: string}
     */
    private function started(): array
    {
        if ($this->started !== null) {
            return $this->started;
        }

        $server = $this->getRecord();

        return $this->started = Cache::remember('loomnode:dst-started:' . $server->id, now()->addSeconds(20), function () use ($server) {
            try {
                $content = Container::getInstance()->make(DaemonFileRepository::class)->setServer($server)->getContent(self::SETUP_FILE, 65536);
            } catch (Throwable) {
                return ['known' => false, 'mods' => [], 'collection' => ''];
            }
            preg_match_all('/^ServerModSetup\("([0-9]{1,20})"\)/m', $content, $mods);
            preg_match('/^ServerModCollectionSetup\("([0-9]{1,20})"\)/m', $content, $collection);

            return ['known' => true, 'mods' => array_values(array_unique($mods[1])), 'collection' => $collection[1] ?? ''];
        });
    }

    /** @return array{add: string[], remove: string[], collection: bool} */
    private function pending(): array
    {
        $started = $this->started();
        $mods = $this->currentMods();

        return [
            'add' => array_values(array_diff($mods, $started['mods'])),
            'remove' => array_values(array_diff($started['mods'], $mods)),
            'collection' => $this->collection() !== $started['collection'],
        ];
    }

    private function pendingCount(): int
    {
        $pending = $this->pending();

        return count($pending['add']) + count($pending['remove']) + (int) $pending['collection'];
    }

    private function pendingSummary(): string
    {
        $pending = $this->pending();
        $parts = array_filter([
            $pending['add'] !== [] ? count($pending['add']) . (count($pending['add']) === 1 ? ' mod' : ' mods') . ' to add' : null,
            $pending['remove'] !== [] ? count($pending['remove']) . ' to take out' : null,
            $pending['collection'] ? ($this->collection() === '' ? 'the collection to take out' : 'a collection to add') : null,
        ]);

        return ucfirst(implode(', ', $parts));
    }

    private function running(): bool
    {
        try {
            return !$this->getRecord()->retrieveStatus()->isOffline();
        } catch (Throwable) {
            return false;
        }
    }

    // ---- Steam -------------------------------------------------------------------------------------------

    /**
     * One page of the Workshop's own search, cached for 10 minutes; null when Steam can't be reached or the
     * page no longer carries the answer.
     *
     * @param  string[]  $required
     * @return array{total: int, results: array<int, array<string, mixed>>, authors: array<string, string>}|null
     */
    protected static function steamBrowse(string $section, string $search, string $sort, int $page, array $required = []): ?array
    {
        $query = ['appid' => self::APP, 'section' => $section, 'searchtext' => $search, 'browsesort' => $sort, 'p' => $page, 'l' => 'english'];
        if ($sort === 'trend') {
            $query['days'] = $section === 'collections' ? 365 : 30;
        }
        if ($section !== 'collections') {
            // a mod only for a player's own game does nothing on a server
            $query['excludedtags'] = ['client_only_mod'];
        }
        if ($required !== []) {
            $query['requiredtags'] = array_values($required);
        }

        return Cache::remember('loomnode:steam:browse:' . md5(json_encode($query)), now()->addMinutes(10), function () use ($query) {
            try {
                $response = Http::withUserAgent(self::USER_AGENT)->withHeaders(['Accept-Language' => 'en'])->timeout(12)
                    ->get('https://steamcommunity.com/workshop/browse/', $query);
                if (!$response->successful() || !preg_match('#<script type="application/json" id="valve-ssr-data"[^>]*>(.*?)</script>#s', $response->body(), $m)) {
                    return null;
                }
                $data = json_decode($m[1], true);
                $queries = json_decode((string) ($data['renderContext']['queryData'] ?? ''), true)['queries'] ?? [];
                foreach (is_array($queries) ? $queries : [] as $entry) {
                    $answer = $entry['state']['data'] ?? null;
                    if (($entry['queryKey'][0] ?? '') !== 'workshop_browse' || !is_array($answer) || !is_array($answer['results'] ?? null)) {
                        continue;
                    }
                    $authors = [];
                    foreach ((array) ($answer['creator_player_link_details'] ?? []) as $player) {
                        $authors[(string) ($player['public_data']['steamid'] ?? '')] = (string) ($player['public_data']['persona_name'] ?? '');
                    }
                    $keep = ['publishedfileid', 'title', 'short_description', 'preview_url', 'subscriptions', 'time_updated', 'creator', 'consumer_appid', 'file_type', 'num_children'];

                    return [
                        'total' => (int) ($answer['total_count'] ?? 0),
                        'results' => array_values(array_map(fn ($item) => array_intersect_key($item, array_flip($keep)) + [
                            'tags' => array_column((array) ($item['tags'] ?? []), 'tag'),
                            'children' => array_column((array) ($item['children'] ?? []), 'publishedfileid'),
                        ], array_filter($answer['results'], 'is_array'))),
                        'authors' => $authors,
                    ];
                }
            } catch (Throwable) {
                // fall through
            }

            return null;
        }) ?: null;
    }

    /**
     * What Steam says about Workshop items, by ID (keyless API), each kept for 10 minutes. An ID Steam doesn't
     * know is left out; null when Steam can't be reached.
     *
     * @param  string[]  $ids
     * @return array<string, array<string, mixed>>|null
     */
    protected static function steamDetails(array $ids): ?array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => self::isId($id))));
        $found = [];
        $ask = [];
        foreach ($ids as $id) {
            $cached = Cache::get('loomnode:steam:item:' . $id);
            if (is_array($cached)) {
                $found[$id] = $cached;
            } else {
                $ask[] = $id;
            }
        }

        foreach (array_chunk($ask, 100) as $chunk) {
            try {
                $response = Http::withUserAgent(self::USER_AGENT)->timeout(10)->asForm()
                    ->post('https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/', ['itemcount' => count($chunk), 'publishedfileids' => $chunk]);
                if (!$response->successful()) {
                    return null;
                }
                foreach ((array) $response->json('response.publishedfiledetails') as $item) {
                    $id = (string) ($item['publishedfileid'] ?? '');
                    $item = ['tags' => array_column((array) ($item['tags'] ?? []), 'tag'), 'missing' => (int) ($item['result'] ?? 0) !== 1] + (array) $item;
                    unset($item['description_full']);
                    $item['description'] = mb_substr((string) ($item['description'] ?? ''), 0, 600);
                    Cache::put('loomnode:steam:item:' . $id, $item, now()->addMinutes(10));
                    $found[$id] = $item;
                }
            } catch (Throwable) {
                return null;
            }
        }

        return array_filter($found, fn ($item) => !$item['missing']);
    }

    /** @return string[]|null the mods in a collection, in its order; null when Steam can't be reached */
    protected static function steamCollection(string $id): ?array
    {
        return Cache::remember('loomnode:steam:collection:' . $id, now()->addMinutes(10), function () use ($id) {
            try {
                $response = Http::withUserAgent(self::USER_AGENT)->timeout(10)->asForm()
                    ->post('https://api.steampowered.com/ISteamRemoteStorage/GetCollectionDetails/v1/', ['collectioncount' => 1, 'publishedfileids' => [$id]]);
                if (!$response->successful() || (int) $response->json('response.collectiondetails.0.result') !== 1) {
                    return null;
                }

                return ['ids' => array_values(array_filter(array_map(fn ($child) => (string) ($child['publishedfileid'] ?? ''), (array) $response->json('response.collectiondetails.0.children')), fn ($child) => self::isId($child)))];
            } catch (Throwable) {
                return null;
            }
        })['ids'] ?? null;
    }

    // ---- The page ----------------------------------------------------------------------------------------

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make('Steam Workshop mods for Don\'t Starve Together')
                ->description('The game downloads its mods at every start, so a change here needs a restart and nothing else. '
                    . '"Sent to players" mods reach everyone who joins by themselves; "Server only" mods need nothing from players. '
                    . 'Mods made only for a player\'s own game aren\'t listed: they do nothing on a server. '
                    . 'Every mod runs with its normal options; to set options yourself, see "Mods: I set up mod options myself" in Game Settings.')
                ->info()
                ->key('about')
                ->actions([$this->addByLinkAction()]),
            Callout::make(fn () => $this->pendingCount() === 1 ? '1 change waiting for a restart' : $this->pendingCount() . ' changes waiting for a restart')
                ->description(fn () => $this->pendingSummary() . '. Pick everything you want first. '
                    . ($this->running() ? 'A restart disconnects players for a minute or two while the mods download.' : 'The server is stopped: they load when it starts.'))
                ->warning()
                ->key('pendingChanges')
                ->actions([$this->restartAction()])
                ->visible(fn () => $this->pendingCount() > 0),
            Tabs::make('Mods')
                ->key('modTabs')
                ->livewireProperty('activeTab')
                ->tabPanels(false)
                ->contained(false)
                ->tabs([
                    'browse' => Tab::make('browse')->label('Browse')->icon(TablerIcon::Search),
                    'yours' => Tab::make('yours')->label('Your mods')->icon(TablerIcon::Puzzle)->badge(fn () => count($this->currentMods()) ?: null),
                    'collection' => Tab::make('collection')->label('Collection')->icon(TablerIcon::Package),
                ]),
            Callout::make(fn () => $this->collectionHeading())
                ->description(fn () => $this->collection() === ''
                    ? 'A Steam Workshop collection is a ready-made list of mods. A server follows one collection: every mod in it is turned on, and when its maker changes it, your server follows at the next start. Pick one below, or use a collection of your own by its link. Mods from the Browse tab go on top.'
                    : 'Every mod in this collection is turned on, on top of the mods in Your mods. When the collection changes on Steam, the server follows at its next start.')
                ->color('gray')
                ->icon(TablerIcon::Package)
                ->key('collection')
                ->actions([$this->collectionLinkAction(), $this->removeCollectionAction()])
                ->visible(fn () => $this->activeTab === 'collection'),
            EmbeddedTable::make(),
        ]);
    }

    private function collectionHeading(): string
    {
        $id = $this->collection();
        if ($id === '') {
            return 'No collection';
        }
        $title = (string) (self::steamDetails([$id])[$id]['title'] ?? '');

        return ($title !== '' ? $title : 'Collection ' . $id) . ($this->started()['collection'] === $id ? '' : ' · waiting for restart');
    }

    public function updatedActiveTab(): void
    {
        $this->resetPage();
        $this->resetTableSearch();
        $this->flushCachedTableRecords();
        // each tab has its own filters, and the filters form was built before the tab changed
        $this->cacheSchema('tableFiltersForm', $this->getTableFiltersForm(...));
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, int|string $page, ?array $filters): LengthAwarePaginator => match ($this->activeTab) {
                'yours' => $this->yourMods((string) $search, (int) $page),
                'collection' => $this->collection() === '' ? $this->browse('collections', (string) $search, (int) $page, []) : $this->collectionMods((string) $search, (int) $page),
                default => $this->browse('readytouseitems', (string) $search, (int) $page, $filters ?? []),
            })
            ->deferLoading()
            ->paginated([self::PER_PAGE])
            ->defaultPaginationPageOption(self::PER_PAGE)
            ->searchable()
            ->searchPlaceholder(fn () => $this->activeTab === 'collection' && $this->collection() === '' ? 'Search collections' : 'Search mods')
            ->searchDebounce('600ms')
            ->columns([
                // one card-like row per mod: picture, what it is, tags; it stays readable on a phone
                Split::make([
                    ImageColumn::make('icon')
                        ->imageSize(44)
                        ->checkFileExistence(false)
                        ->extraImgAttributes(['loading' => 'lazy', 'alt' => '', 'class' => 'rounded-lg'])
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('title')
                            ->weight(FontWeight::SemiBold)
                            ->url(fn (array $record) => $record['found'] ? 'https://steamcommunity.com/sharedfiles/filedetails/?id=' . $record['id'] : null, true),
                        TextColumn::make('description')
                            ->color('gray')
                            ->wrap(),
                        TextColumn::make('meta')
                            ->state(fn (array $record) => implode(' · ', array_filter([
                                $record['author'] !== '' ? 'by ' . $record['author'] : null,
                                $record['is_collection'] ? ($record['count'] === 1 ? '1 mod' : $record['count'] . ' mods') : null,
                                $record['subscribers'] === null || $record['is_collection'] ? null : self::short((int) $record['subscribers']) . ' subscribers',
                                $record['updated'] === null ? null : 'updated ' . self::ago($record['updated']),
                            ])))
                            ->size(TextSize::ExtraSmall)
                            ->color('gray'),
                        TextColumn::make('badges')
                            ->state(fn (array $record) => array_values(array_filter([$record['status'], $record['players']])))
                            ->badge()
                            ->color(fn (string $state) => self::badgeColor($state))
                            ->hiddenFrom('md'),
                    ])->space(1),
                    TextColumn::make('kinds')
                        ->badge()
                        ->color('gray')
                        ->visibleFrom('lg')
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('status')
                            ->badge()
                            ->color(fn (?string $state) => self::badgeColor((string) $state)),
                        TextColumn::make('players')
                            ->badge()
                            ->color(fn (?string $state) => self::badgeColor((string) $state)),
                    ])->space(1)->alignment(Alignment::End)->grow(false)->visibleFrom('md'),
                ]),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('Kind')
                    ->placeholder('All kinds')
                    ->options(self::KINDS)
                    ->visible(fn () => $this->activeTab === 'browse'),
                SelectFilter::make('players')
                    ->label('For players')
                    ->placeholder('Any')
                    ->options(self::FOR_PLAYERS)
                    ->visible(fn () => $this->activeTab === 'browse'),
                SelectFilter::make('sort')
                    ->label('Sort by')
                    ->placeholder('Most subscribed')
                    ->options(array_slice(self::SORTS, 1, null, true))
                    ->visible(fn () => $this->activeTab === 'browse'),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 1, 'md' => 3])
            ->deferFilters(false)
            ->recordActions([
                Action::make('add')
                    ->label('Add')
                    ->icon(TablerIcon::Plus)
                    ->button()
                    ->visible(fn (array $record) => !$record['is_collection'] && !$record['in_list'] && !$record['from_collection'] && $record['found'] && $this->canEdit())
                    ->action(fn (array $record) => $this->addMods([$record['id'], ...$record['needs']], $record['title'])),
                Action::make('remove')
                    ->label('Remove')
                    ->icon(TablerIcon::Trash)
                    ->button()
                    ->outlined()
                    ->color('danger')
                    ->visible(fn (array $record) => !$record['is_collection'] && $record['in_list'] && $this->canEdit())
                    ->action(fn (array $record) => $this->removeMod($record['id'], $record['title'])),
                Action::make('useCollection')
                    ->label('Use this collection')
                    ->icon(TablerIcon::Package)
                    ->button()
                    ->visible(fn (array $record) => $record['is_collection'] && $this->canEdit())
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record) => 'Use ' . $record['title'])
                    ->modalDescription('Every mod in the collection is turned on at the next start, and the server keeps following the collection when its maker changes it. Look through it on Steam first (press its name): collections are made by players, and nobody checks them.')
                    ->modalSubmitActionLabel('Use this collection')
                    ->action(fn (array $record) => $this->useCollection($record['id'])),
            ])
            ->emptyStateIcon(TablerIcon::Puzzle)
            ->emptyStateHeading(fn () => $this->emptyState()[0])
            ->emptyStateDescription(fn () => $this->emptyState()[1]);
    }

    private static function badgeColor(string $state): string
    {
        return match ($state) {
            'Installed' => 'success',
            'Waiting for restart' => 'warning',
            'Does nothing on a server', 'Not on Steam any more' => 'danger',
            default => 'gray',
        };
    }

    /** @return array{0: string, 1: string} */
    private function emptyState(): array
    {
        return match (true) {
            $this->activeTab === 'yours' => ['No mods yet', 'Add some from the Browse tab.'],
            $this->steamDown => ['Steam can\'t be reached right now', 'Try again in a minute. "Add by link or ID" at the top of the page may still work.'],
            $this->activeTab === 'collection' && $this->collection() !== '' => ['This collection has no mods', 'Or it isn\'t public: only a public collection can be read.'],
            $this->activeTab === 'collection' => ['No collections found', 'Try another word.'],
            default => ['No mods found', 'Try another word, or clear the filters.'],
        };
    }

    // ---- Rows ----------------------------------------------------------------------------------------------

    /**
     * The Workshop's search: mods (without the ones only for a player's own game) or collections.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<string, array<string, mixed>>
     */
    protected function browse(string $section, string $search, int $page, array $filters): LengthAwarePaginator
    {
        $page = max(1, min($page, 100));
        $search = mb_substr(trim($search), 0, 100);
        $collections = $section === 'collections';

        $required = [];
        $sort = $collections ? 'trend' : 'totaluniquesubscribers';
        if (!$collections) {
            $kind = (string) ($filters['kind']['value'] ?? '');
            $players = (string) ($filters['players']['value'] ?? '');
            $picked = (string) ($filters['sort']['value'] ?? '');
            $required = array_values(array_filter([array_key_exists($kind, self::KINDS) ? $kind : null, array_key_exists($players, self::FOR_PLAYERS) ? $players : null]));
            $sort = array_key_exists($picked, self::SORTS) ? $picked : $sort;
        }
        if ($search !== '' && !array_key_exists((string) ($filters['sort']['value'] ?? ''), self::SORTS)) {
            $sort = 'textsearch';
        }

        $answer = self::steamBrowse($section, $search, $sort, $page, $required);
        $this->steamDown = $answer === null;

        $rows = [];
        foreach ($answer['results'] ?? [] as $item) {
            // the search's two sections never mix, but don't take Steam's word for it
            if (((int) ($item['file_type'] ?? 0) === 2) !== $collections) {
                continue;
            }
            $row = $this->row($item + ['author' => $answer['authors'][(string) ($item['creator'] ?? '')] ?? '']);
            if ($row !== null) {
                $rows['w' . $row['id']] = $row;
            }
        }

        return new LengthAwarePaginator($rows, min((int) ($answer['total'] ?? 0), 100 * self::PER_PAGE), self::PER_PAGE, $page);
    }

    /**
     * The mods in the list, with what Steam says about each.
     *
     * @return LengthAwarePaginator<string, array<string, mixed>>
     */
    protected function yourMods(string $search, int $page): LengthAwarePaginator
    {
        return $this->listed($this->currentMods(), $search, $page, false);
    }

    /**
     * The mods in the server's collection.
     *
     * @return LengthAwarePaginator<string, array<string, mixed>>
     */
    protected function collectionMods(string $search, int $page): LengthAwarePaginator
    {
        $ids = self::steamCollection($this->collection());
        $this->steamDown = $ids === null;

        return $this->listed($ids ?? [], $search, $page, true);
    }

    /**
     * @param  string[]  $ids
     * @return LengthAwarePaginator<string, array<string, mixed>>
     */
    private function listed(array $ids, string $search, int $page, bool $fromCollection): LengthAwarePaginator
    {
        $details = $ids === [] ? [] : self::steamDetails($ids);
        $rows = [];
        foreach ($ids as $id) {
            $row = isset($details[$id]) ? $this->row($details[$id], $fromCollection) : null;
            $row ??= [
                ArrayRecord::getKeyName() => 'w' . $id, 'id' => $id, 'title' => 'Mod ' . $id, 'found' => false,
                'description' => $details === null ? 'Steam can\'t be reached right now, so its name isn\'t known.' : 'Steam has no public Workshop mod for Don\'t Starve Together with this ID. The game skips it.',
                'icon' => null, 'author' => '', 'kinds' => [], 'players' => $details === null ? null : 'Not on Steam any more', 'subscribers' => null, 'updated' => null,
                'is_collection' => false, 'count' => 0, 'needs' => [], 'from_collection' => $fromCollection, 'in_list' => !$fromCollection,
                'status' => $this->status($id, $fromCollection),
            ];
            if ($search === '' || str_contains(strtolower($row['title'] . ' ' . $row['id']), strtolower(trim($search)))) {
                $rows['w' . $id] = $row;
            }
        }

        return new LengthAwarePaginator(array_slice($rows, (max(1, $page) - 1) * self::PER_PAGE, self::PER_PAGE, true), count($rows), self::PER_PAGE, max(1, $page));
    }

    private function status(string $id, bool $fromCollection = false): ?string
    {
        $started = $this->started();
        if ($fromCollection) {
            return $started['collection'] === $this->collection() ? 'Installed' : 'Waiting for restart';
        }
        if (!in_array($id, $this->currentMods(), true)) {
            return null;
        }

        return in_array($id, $started['mods'], true) ? 'Installed' : 'Waiting for restart';
    }

    /**
     * One row from what Steam says about a Workshop item (from the search or from the lookup by ID); null for
     * anything that isn't a Don't Starve Together mod or collection.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function row(array $item, bool $fromCollection = false): ?array
    {
        $id = (string) ($item['publishedfileid'] ?? '');
        if (!self::isId($id) || (int) ($item['consumer_appid'] ?? $item['consumer_app_id'] ?? 0) !== self::APP || (int) ($item['banned'] ?? 0) === 1) {
            return null;
        }
        $isCollection = (int) ($item['file_type'] ?? 0) === 2 || (int) ($item['creator_app_id'] ?? 0) === self::COLLECTION_APP;
        $tags = array_map('strtolower', array_filter((array) ($item['tags'] ?? []), 'is_string'));
        $title = mb_substr(trim((string) ($item['title'] ?? '')), 0, 80) ?: 'Mod ' . $id;
        $inList = !$isCollection && !$fromCollection && in_array($id, $this->currentMods(), true);

        return [
            ArrayRecord::getKeyName() => 'w' . $id,
            'id' => $id,
            'title' => $title,
            'found' => true,
            'description' => self::plain((string) ($item['short_description'] ?? $item['description'] ?? '')),
            'icon' => self::iconUrl((string) ($item['preview_url'] ?? '')),
            'author' => mb_substr((string) ($item['author'] ?? ''), 0, 40),
            'kinds' => array_values(array_map(fn ($tag) => self::KINDS[$tag], array_slice(array_filter($tags, fn ($tag) => array_key_exists($tag, self::KINDS) && $tag !== 'other'), 0, 3))),
            'players' => match (true) {
                $isCollection => null,
                in_array('client_only_mod', $tags, true) => 'Does nothing on a server',
                in_array('all_clients_require_mod', $tags, true) => 'Sent to players',
                in_array('server_only_mod', $tags, true) => 'Server only',
                default => null,
            },
            'subscribers' => (int) ($item['subscriptions'] ?? 0),
            'updated' => (int) ($item['time_updated'] ?? 0) ?: null,
            'is_collection' => $isCollection,
            'count' => (int) ($item['num_children'] ?? 0),
            // other mods this one says it needs (the search tells; the lookup by ID doesn't)
            'needs' => array_values(array_filter((array) ($item['children'] ?? []), fn ($child) => self::isId($child))),
            'from_collection' => $fromCollection,
            'in_list' => $inList,
            'status' => $isCollection ? null : $this->status($id, $fromCollection),
        ];
    }

    /** A Workshop description as one short plain line: no BBCode, no links. */
    private static function plain(string $text): string
    {
        $text = (string) preg_replace('/\[\/?[a-z0-9*]+(=[^\]]*)?\]/i', ' ', $text);
        $text = (string) preg_replace('#https?://\S+#', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 140 ? rtrim(mb_substr($text, 0, 137)) . '…' : $text;
    }

    private static function iconUrl(string $url): ?string
    {
        if (preg_match('#^https://images\.steamusercontent\.com/ugc/([0-9]{6,24})/([A-Fa-f0-9]{16,64})/?$#', $url, $m)) {
            return route('loomnode.workshop-icon', ['file' => $m[1], 'hash' => $m[2]]);
        }

        return null;
    }

    private static function short(int $number): string
    {
        return match (true) {
            $number >= 1_000_000 => round($number / 1_000_000, 1) . 'M',
            $number >= 1_000 => round($number / 1_000) . 'k',
            default => (string) $number,
        };
    }

    private static function ago(int $time): string
    {
        try {
            return Carbon::createFromTimestamp($time)->diffForHumans();
        } catch (Throwable) {
            return '';
        }
    }

    // ---- Changing the list -----------------------------------------------------------------------------

    /**
     * Adds Workshop mods by ID after asking Steam what each one is: only public Don't Starve Together mods go
     * in (not a collection, not something for another game). The first ID is the one that was picked; the
     * rest are mods it says it needs.
     *
     * @param  string[]  $ids
     */
    protected function addMods(array $ids, string $title = ''): void
    {
        abort_unless($this->canEdit(), 403);
        $ids = array_slice(array_values(array_unique(array_filter($ids, fn ($id) => self::isId($id)))), 0, 50);
        $current = $this->currentMods();
        $new = array_values(array_diff($ids, $current));
        if ($new === []) {
            Notification::make()->title($ids === [] ? 'No Workshop ID found in that' : 'Already in your mods')->warning()->send();

            return;
        }

        $details = self::steamDetails($new);
        if ($details === null) {
            Notification::make()->title('Steam can\'t be reached right now')->body('Nothing was added. Try again in a minute.')->danger()->send();

            return;
        }
        $good = [];
        $skipped = 0;
        foreach ($new as $id) {
            $row = isset($details[$id]) ? $this->row($details[$id]) : null;
            if ($row !== null && !$row['is_collection']) {
                $good[$id] = $row['title'];
            } else {
                $skipped++;
            }
        }
        if ($good === []) {
            Notification::make()->title('Not a Don\'t Starve Together mod')->body('Only public Workshop mods for this game can be added. A collection goes on the Collection tab.')->danger()->send();

            return;
        }

        if ($this->setVariable('DST_MODS', implode(',', [...$current, ...array_keys($good)]))) {
            $this->flushCachedTableRecords();
            $first = $title !== '' && isset($good[$ids[0]]) ? $title : (string) reset($good);
            Notification::make()
                ->title(count($good) === 1 ? $first . ' added' : $first . ' and ' . (count($good) - 1) . ' more added')
                ->body(($title !== '' && count($good) > 1 ? 'The extra ones are mods it needs. ' : '')
                    . ($skipped > 0 ? $skipped . ' skipped: not a public mod for this game. ' : '')
                    . 'Pick more, then restart the server.')
                ->success()
                ->send();
        }
    }

    protected function removeMod(string $id, string $title): void
    {
        abort_unless($this->canEdit(), 403);
        $mods = array_values(array_filter($this->currentMods(), fn ($entry) => $entry !== $id));
        if ($this->setVariable('DST_MODS', implode(',', $mods))) {
            $this->flushCachedTableRecords();
            Notification::make()->title($title . ' removed')->body('It stops at the next restart. What it added to the world may go with it.')->success()->send();
        }
    }

    protected function useCollection(string $id): void
    {
        abort_unless($this->canEdit(), 403);
        $details = self::isId($id) ? self::steamDetails([$id]) : [];
        if ($details === null) {
            Notification::make()->title('Steam can\'t be reached right now')->body('Nothing was changed. Try again in a minute.')->danger()->send();

            return;
        }
        $row = isset($details[$id]) ? $this->row($details[$id]) : null;
        if ($row === null || !$row['is_collection']) {
            Notification::make()->title('Not a Don\'t Starve Together collection')->body('Only a public Workshop collection for this game can be used. A single mod goes in with "Add by link or ID".')->danger()->send();

            return;
        }
        if ($this->setVariable('DST_MOD_COLLECTION', $id)) {
            $this->resetPage();
            $this->flushCachedTableRecords();
            Notification::make()->title($row['title'] . ' is this server\'s collection')->body('Its mods load at the next restart.')->success()->send();
        }
    }

    /**
     * IDs in what someone pasted: Workshop links (…?id=123) or bare numbers.
     *
     * @return string[]
     */
    private static function idsIn(string $text): array
    {
        preg_match_all('/(?:[?&]id=|^|[\s,;])([0-9]{6,20})(?=$|[\s,;&#])/m', $text, $m);

        return array_values(array_unique($m[1]));
    }

    public function addByLinkAction(): Action
    {
        return Action::make('addByLink')
            ->label('Add by link or ID')
            ->icon(TablerIcon::Link)
            ->button()
            ->outlined()
            ->color('gray')
            ->visible(fn () => $this->canEdit())
            ->modalHeading('Add mods by link or ID')
            ->modalDescription('Paste Steam Workshop links or ID numbers (the number at the end of a mod\'s page address), one per line or separated by commas.')
            ->modalSubmitActionLabel('Add')
            ->modalWidth('lg')
            ->schema([
                Textarea::make('mods')->label('Links or IDs')->rows(4)->required()->maxLength(4000),
            ])
            ->action(fn (array $data) => $this->addMods(self::idsIn((string) ($data['mods'] ?? ''))));
    }

    public function collectionLinkAction(): Action
    {
        return Action::make('collectionLink')
            ->label(fn () => $this->collection() === '' ? 'Use a collection by link' : 'Use another collection')
            ->icon(TablerIcon::Link)
            ->button()
            ->outlined()
            ->color('gray')
            ->visible(fn () => $this->canEdit())
            ->modalHeading('Use a collection by link')
            ->modalDescription('Paste the link of a public Steam Workshop collection, or its ID number. It replaces the collection this server has now.')
            ->modalSubmitActionLabel('Use this collection')
            ->modalWidth('lg')
            ->schema([
                TextInput::make('collection')->label('Link or ID')->required()->maxLength(300),
            ])
            ->action(fn (array $data) => $this->useCollection(self::idsIn((string) ($data['collection'] ?? ''))[0] ?? ''));
    }

    public function removeCollectionAction(): Action
    {
        return Action::make('removeCollection')
            ->label('Stop using it')
            ->icon(TablerIcon::Trash)
            ->button()
            ->outlined()
            ->color('danger')
            ->visible(fn () => $this->collection() !== '' && $this->canEdit())
            ->requiresConfirmation()
            ->modalHeading('Stop using this collection')
            ->modalDescription('Its mods stop at the next restart, and what they added to the world may go with them. The mods in Your mods stay.')
            ->modalSubmitActionLabel('Stop using it')
            ->action(function () {
                if ($this->setVariable('DST_MOD_COLLECTION', '')) {
                    $this->resetPage();
                    $this->flushCachedTableRecords();
                    Notification::make()->title('No collection any more')->success()->send();
                }
            });
    }

    /** Shown in the "waiting for a restart" box; public so Filament finds it by name. */
    public function restartAction(): Action
    {
        return Action::make('restart')
            ->label(fn () => $this->running() ? 'Restart now' : 'Start the server')
            ->icon(TablerIcon::Reload)
            ->button()
            ->visible(fn () => $this->pendingCount() > 0 && $this->canPower())
            ->requiresConfirmation()
            ->modalHeading(fn () => $this->running() ? 'Restart the server' : 'Start the server')
            ->modalDescription(fn () => $this->pendingSummary() . '. '
                . ($this->running() ? 'Players are disconnected while it restarts. ' : '')
                . 'The game downloads the mods as it starts, so this start takes longer: watch the Console.')
            ->modalSubmitActionLabel(fn () => $this->running() ? 'Restart now' : 'Start')
            ->action(function () {
                $server = $this->getRecord();
                abort_unless($this->canPower() && !$server->isInConflictState(), 403);
                $running = $this->running();
                try {
                    Container::getInstance()->make(DaemonServerRepository::class)->setServer($server)->power($running ? 'restart' : 'start');
                } catch (Throwable) {
                    Notification::make()->title('The server couldn\'t be reached')->body('Try the Restart button on the Console page.')->danger()->send();

                    return;
                }
                Cache::forget('loomnode:dst-started:' . $server->id);
                Notification::make()->title($running ? 'Restarting' : 'Starting')->body('The mods download now. This page catches up once the server has started.')->success()->send();
                $this->redirect(Console::getUrl(), navigate: true);
            });
    }

    private function canPower(): bool
    {
        return (bool) user()?->can($this->running() ? SubuserPermission::ControlRestart : SubuserPermission::ControlStart, $this->getRecord());
    }
}
