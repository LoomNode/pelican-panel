<?php

namespace App\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Models\Server;
use App\Traits\Filament\CanCustomizeHeaderActions;
use App\Traits\Filament\ManagesMinecraftMods;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Mods for a Minecraft server (LoomNode, 2026-11-13; before that a button on Game Settings).
 *
 * Browse searches Modrinth for mods that run on a server, for this server's loader and Minecraft
 * version; Add and Remove write the Mods setting (MC_MODS: "slug" or "slug@version", comma-separated).
 * Nothing is downloaded here: "Install changes" reinstalls, and the egg's installer fetches the list
 * and whatever those mods need. Shown only for a server whose egg has the stage 2 Minecraft settings.
 */
class Mods extends Page implements HasTable
{
    use CanCustomizeHeaderActions;
    use InteractsWithTable;
    use ManagesMinecraftMods;

    private const PER_PAGE = 20;

    /** Modrinth's mod categories, without "library": libraries come along when a mod needs them. */
    private const CATEGORIES = [
        'adventure' => 'Adventure', 'decoration' => 'Decoration', 'economy' => 'Economy', 'equipment' => 'Equipment',
        'food' => 'Food', 'game-mechanics' => 'Game mechanics', 'magic' => 'Magic', 'management' => 'Management',
        'minigame' => 'Minigame', 'mobs' => 'Mobs', 'optimization' => 'Optimization', 'social' => 'Social',
        'storage' => 'Storage', 'technology' => 'Technology', 'transportation' => 'Transportation',
        'utility' => 'Utility', 'worldgen' => 'World generation', 'cursed' => 'Cursed',
    ];

    private const SORTS = ['downloads' => 'Most downloaded', 'follows' => 'Most followed', 'newest' => 'Newest', 'updated' => 'Recently updated'];

    private const SLUG = '/^[A-Za-z0-9_.+-]{1,64}$/';

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Puzzle;

    // right after Game Settings (10); Settings shares 11 and sorts behind it
    protected static ?int $navigationSort = 11;

    public ?string $activeTab = 'browse';

    /** Set while the table loads: Modrinth didn't answer. */
    private bool $modrinthDown = false;

    public static function canAccess(): bool
    {
        /** @var ?Server $server */
        $server = Filament::getTenant();

        return parent::canAccess() && $server !== null && !$server->isInConflictState()
            && user()?->can(SubuserPermission::StartupRead, $server) && self::hasMinecraftMods($server);
    }

    public function mount(): void
    {
        abort_unless(user()?->can(SubuserPermission::StartupRead, $this->getRecord()) && self::hasMinecraftMods($this->getRecord()), 403);
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

    // ---- What the server runs and what the list says ----------------------------------------------------

    /**
     * The loader and Minecraft version mods must fit.
     *  - vanilla: no mod loader is installed yet
     *  - choose:  the loader can still be picked here (vanilla, and nothing has fixed one yet)
     *  - pack:    a modpack is picked but not installed, so its loader isn't known yet
     *
     * @return array{loader: string, minecraft: string, vanilla: bool, choose: bool, pack: bool}
     */
    protected function modTarget(): array
    {
        $info = $this->installInfo();
        $installed = $info['loader'] ?? '';
        $vanilla = $installed === '' || $installed === 'vanilla';
        $wanted = (string) $this->minecraftVariable('MC_LOADER')?->variable_value;
        $choose = $vanilla && !array_key_exists($wanted, self::LOADERS);
        $picked = (string) ($this->tableFilters['loader']['value'] ?? '');

        return [
            'loader' => !$vanilla ? $installed : (!$choose ? $wanted : (array_key_exists($picked, self::LOADERS) ? $picked : 'fabric')),
            'minecraft' => $info['minecraft'] ?? '',
            'vanilla' => $vanilla,
            'choose' => $choose,
            'pack' => $this->wantedPack() !== '' && strtolower($this->wantedPack()) !== strtolower($info['pack_slug'] ?? ''),
        ];
    }

    // ---- The page ----------------------------------------------------------------------------------------

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(fn () => $this->aboutHeading())
                ->description(fn () => $this->aboutText())
                ->info()
                ->key('about')
                ->actions([$this->modLoaderAction()]),
            Callout::make(fn () => $this->pendingCount() === 1 ? '1 change waiting to be installed' : $this->pendingCount() . ' changes waiting to be installed')
                ->description(fn () => $this->pendingSummary() . '. '
                    . ($this->pendingRisk() !== '' ? $this->pendingRisk() . ' ' : '')
                    . ($this->canInstall()
                        ? 'Pick everything you want first, then install it in one go.'
                        : 'Someone who may reinstall this server' . ($this->installNeedsBackup() ? ' and make backups' : '') . ' has to install them; ask the owner.'))
                ->warning()
                ->key('pendingChanges')
                ->actions([$this->installAction()])
                ->visible(fn () => $this->pendingCount() > 0),
            Tabs::make('Mods')
                ->key('modTabs')
                ->livewireProperty('activeTab')
                ->tabPanels(false)
                ->contained(false)
                ->tabs([
                    'browse' => Tab::make('browse')->label('Browse')->icon(TablerIcon::Search),
                    'yours' => Tab::make('yours')->label('Your mods')->icon(TablerIcon::Puzzle)->badge(fn () => count($this->currentMods()) ?: null),
                    'pack' => Tab::make('pack')->label('Modpack')->icon(TablerIcon::Package),
                ]),
            Callout::make(fn () => $this->packHeading())
                ->description(fn () => $this->packText())
                ->color('gray')
                ->icon(TablerIcon::Package)
                ->key('pack')
                ->actions([$this->changePackVersionAction(), $this->removePackAction()])
                ->visible(fn () => $this->activeTab === 'pack'),
            EmbeddedTable::make(),
        ]);
    }

    private function packHeading(): string
    {
        $info = $this->installInfo();
        $pack = $this->wantedPack();
        if ($pack === '') {
            return ($info['pack_slug'] ?? '') !== '' ? 'No modpack any more (still installed: ' . (($info['pack'] ?? '') ?: $info['pack_slug']) . ')' : 'No modpack';
        }
        if (strtolower($pack) !== strtolower($info['pack_slug'] ?? '')) {
            return self::packTitle($pack) . ' · waiting for install';
        }

        return (($info['pack'] ?? '') ?: $pack) . ' ' . ($info['pack_version'] ?? '') . ' · Minecraft ' . ($info['minecraft'] ?? '?') . ' · ' . (self::LOADERS[$info['loader'] ?? ''] ?? ($info['loader'] ?? ''));
    }

    private function packText(): string
    {
        if ($this->wantedPack() === '') {
            return 'A modpack is a ready-made set of mods that brings its own Minecraft version and mod loader. Pick one below, or add single mods on the Browse tab. Players install the same pack to join.';
        }

        return 'A server runs one modpack. It keeps its pack version on every reinstall until you press Update modpack (top of the page) or pick another version here. Single mods from the Browse tab go on top of the pack.';
    }

    private function aboutHeading(): string
    {
        $info = $this->installInfo();
        $target = $this->modTarget();
        if ($target['minecraft'] === '') {
            return 'Install this server once first';
        }
        if ($target['pack']) {
            return 'Install the modpack first';
        }
        if (($info['type'] ?? '') === 'modrinth') {
            return 'On top of ' . (($info['pack'] ?? '') ?: 'the modpack') . ': ' . self::LOADERS[$target['loader']] . ' mods for Minecraft ' . $target['minecraft'];
        }
        if ($target['vanilla']) {
            return 'Vanilla Minecraft ' . $target['minecraft'] . ' · adding mods makes it a ' . self::LOADERS[$target['loader']] . ' server';
        }

        return self::LOADERS[$target['loader']] . ' mods for Minecraft ' . $target['minecraft'];
    }

    private function aboutText(): string
    {
        $target = $this->modTarget();
        if ($target['minecraft'] === '') {
            return 'The Panel doesn\'t know yet which Minecraft version this server runs. Press Reinstall in Settings once (your world stays), then pick mods here.';
        }
        if ($target['pack']) {
            return 'A modpack is picked but isn\'t installed yet, so its mod loader isn\'t known. Press Install changes, then add mods on top here.';
        }
        $text = 'Only mods that run on a server are listed. Mods they need come along by themselves. "Players need it too" means everyone who joins must install that mod as well.';
        if ($target['vanilla']) {
            $text .= $target['choose']
                ? ' Fabric has the most mods for recent Minecraft versions; big mods like Create are NeoForge or Forge. Pick the loader under Mod loader before the first mod: a server stays on its loader. Or take a ready-made set on the Modpack tab.'
                : ' To use another loader, remove the mods in the list first.';
            $text .= ' Mods use more memory and CPU than vanilla.';
        }

        return $text;
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
                'pack' => $this->browsePacks((string) $search, (int) $page, $filters ?? []),
                default => $this->browseMods((string) $search, (int) $page, $filters ?? []),
            })
            ->deferLoading()
            ->paginated([self::PER_PAGE])
            ->defaultPaginationPageOption(self::PER_PAGE)
            ->searchable()
            ->searchPlaceholder(fn () => $this->activeTab === 'pack' ? 'Search modpacks' : 'Search mods')
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
                            ->url(fn (array $record) => $record['found'] ? 'https://modrinth.com/' . ($record['pack'] ? 'modpack/' : 'mod/') . $record['slug'] : null, true),
                        TextColumn::make('description')
                            ->color('gray')
                            ->wrap(),
                        TextColumn::make('meta')
                            ->state(fn (array $record) => implode(' · ', array_filter([
                                $record['author'] !== '' ? 'by ' . $record['author'] : null,
                                $record['downloads'] === null ? null : self::short((int) $record['downloads']) . ' downloads',
                                $record['updated'] === null ? null : 'updated ' . self::ago($record['updated']),
                            ])))
                            ->size(TextSize::ExtraSmall)
                            ->color('gray'),
                        TextColumn::make('badges')
                            ->state(fn (array $record) => array_values(array_filter([$record['status'], $record['players']])))
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'Installed' => 'success',
                                'Players need it too' => 'gray',
                                'Waiting for install' => 'warning',
                                default => 'danger',
                            })
                            ->hiddenFrom('md'),
                    ])->space(1),
                    TextColumn::make('categories')
                        ->badge()
                        ->color('gray')
                        ->visibleFrom('lg')
                        ->grow(false),
                    Stack::make([
                        TextColumn::make('status')
                            ->badge()
                            ->color(fn (?string $state) => match ($state) {
                                'Installed' => 'success',
                                'Waiting for install' => 'warning',
                                default => 'danger',
                            }),
                        TextColumn::make('players')
                            ->badge()
                            ->color(fn (?string $state) => $state === 'Players need it too' ? 'warning' : 'gray'),
                    ])->space(1)->alignment(Alignment::End)->grow(false)->visibleFrom('md'),
                ]),
            ])
            ->filters([
                SelectFilter::make('loader')
                    ->label('Mod loader')
                    ->options(self::LOADERS)
                    ->default('fabric')
                    ->selectablePlaceholder(false)
                    ->visible(fn () => $this->activeTab === 'browse' && $this->modTarget()['choose'] && $this->currentMods() === []),
                SelectFilter::make('category')
                    ->label('Kind')
                    ->placeholder('All kinds')
                    ->options(self::CATEGORIES)
                    ->visible(fn () => $this->activeTab === 'browse'),
                SelectFilter::make('minecraft')
                    ->label('Minecraft version')
                    ->placeholder('Any version')
                    ->options(fn () => array_filter(['same' => ($this->installInfo()['minecraft'] ?? '') !== '' ? 'This server\'s (' . $this->installInfo()['minecraft'] . ')' : null]))
                    ->visible(fn () => $this->activeTab === 'pack' && ($this->installInfo()['minecraft'] ?? '') !== ''),
                SelectFilter::make('sort')
                    ->label('Sort by')
                    ->placeholder('Best match')
                    ->options(self::SORTS)
                    ->visible(fn () => $this->activeTab !== 'yours'),
                SelectFilter::make('players')
                    ->label('For players')
                    ->placeholder('Any')
                    ->options(['server' => 'Nothing to install'])
                    ->visible(fn () => $this->activeTab === 'browse'),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(['default' => 2, 'lg' => 4])
            ->deferFilters(false)
            ->recordActions([
                Action::make('add')
                    ->label('Add')
                    ->icon(TablerIcon::Plus)
                    ->button()
                    ->visible(fn (array $record) => !$record['pack'] && !$record['in_list'] && $this->canEdit())
                    ->action(fn (array $record) => $this->addMod($record['slug'], $record['title'])),
                Action::make('version')
                    ->label(fn (array $record) => $record['pinned'] !== '' ? 'Version ' . $record['pinned'] : 'Version')
                    ->icon(TablerIcon::Versions)
                    ->button()
                    ->outlined()
                    ->color('gray')
                    ->visible(fn (array $record) => !$record['pack'] && $record['in_list'] && $record['found'] && $this->canEdit())
                    ->modalHeading(fn (array $record) => $record['title'] . ': which version')
                    ->modalDescription('Newest is right for almost everyone: each install then fetches the newest version for this server. Pick one only when a newer version gives trouble.')
                    ->modalSubmitActionLabel('Save')
                    ->modalWidth('lg')
                    ->fillForm(fn (array $record) => ['version' => $record['pinned']])
                    ->schema(fn (array $record) => [
                        Select::make('version')
                            ->label('Version')
                            ->placeholder('Newest (recommended)')
                            ->options($this->modVersions($record['slug'], $record['pinned']))
                            ->searchable(),
                    ])
                    ->action(fn (array $record, array $data) => $this->pinMod($record['slug'], $record['title'], (string) ($data['version'] ?? ''))),
                Action::make('remove')
                    ->label('Remove')
                    ->icon(TablerIcon::Trash)
                    ->button()
                    ->outlined()
                    ->color('danger')
                    ->visible(fn (array $record) => !$record['pack'] && $record['in_list'] && $this->canEdit())
                    ->action(fn (array $record) => $this->removeMod($record['slug'], $record['title'])),
                Action::make('usePack')
                    ->label('Use this pack')
                    ->icon(TablerIcon::Package)
                    ->button()
                    ->visible(fn (array $record) => $record['pack'] && !$record['in_list'] && $this->canEdit())
                    ->modalHeading(fn (array $record) => 'Use ' . $record['title'])
                    ->modalDescription(fn () => 'The pack brings its own Minecraft version and mod loader'
                        . ($this->wantedPack() !== '' ? ', and it replaces the pack this server has now' : '')
                        . '. A world made with other mods or a newer Minecraft version can break on it, so a backup is made before the install. Nothing changes until you press Install changes.')
                    ->modalSubmitActionLabel('Use this pack')
                    ->modalWidth('lg')
                    ->fillForm(['version' => 'latest'])
                    ->schema(fn (array $record) => [
                        Select::make('version')
                            ->label('Version')
                            ->options(['latest' => 'Newest release (recommended)'] + $this->packVersionOptions($record['slug']))
                            ->selectablePlaceholder(false)
                            ->required()
                            ->searchable(),
                    ])
                    ->action(fn (array $record, array $data) => $this->usePack($record['slug'], $record['title'], (string) ($data['version'] ?? 'latest'))),
            ])
            ->emptyStateIcon(TablerIcon::Puzzle)
            ->emptyStateHeading(fn () => $this->emptyState()[0])
            ->emptyStateDescription(fn () => $this->emptyState()[1]);
    }

    /** @return array{0: string, 1: string} */
    private function emptyState(): array
    {
        $target = $this->modTarget();

        return match (true) {
            $this->activeTab === 'yours' => ['No mods yet', 'Add some from the Browse tab.'],
            $this->modrinthDown => ['Modrinth can\'t be reached right now', 'Try again in a minute.'],
            $this->activeTab === 'pack' => ['No modpacks found', 'Only packs that run on a server with Fabric, NeoForge or Forge are listed. Try another word.'],
            $target['minecraft'] === '' || $target['pack'] => ['Nothing to pick from yet', 'See the note at the top of this page.'],
            default => ['No mods found', 'Only mods that run on a ' . self::LOADERS[$target['loader']] . ' server with Minecraft ' . $target['minecraft'] . ' are listed. Try another word, or clear Kind.'],
        };
    }

    // ---- Rows ----------------------------------------------------------------------------------------------

    /**
     * Modrinth search: mods that run on a server, for the loader and Minecraft version. An empty search
     * lists the most downloaded ones.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<string, array<string, mixed>>
     */
    protected function browseMods(string $search, int $page, array $filters): LengthAwarePaginator
    {
        $page = max(1, min($page, 100));
        $target = $this->modTarget();
        if ($target['minecraft'] === '' || $target['pack']) {
            return new LengthAwarePaginator([], 0, self::PER_PAGE, $page);
        }

        $search = mb_substr(trim($search), 0, 100);
        $facets = [
            ['project_type:mod'],
            ['categories:' . $target['loader']],
            ['versions:' . $target['minecraft']],
            ['server_side:required', 'server_side:optional'],
            // not a client mod with an optional server part (minimaps and the like): no use on its own
            ['server_side:required', 'client_side:optional', 'client_side:unsupported'],
            ['categories!=library'],
            ...array_map(fn ($id) => ['project_id!=' . $id], self::TUNNEL_MODS),
        ];
        $category = (string) ($filters['category']['value'] ?? '');
        if (array_key_exists($category, self::CATEGORIES)) {
            $facets[] = ['categories:' . $category];
        }
        if (($filters['players']['value'] ?? '') === 'server') {
            $facets[] = ['client_side:optional', 'client_side:unsupported'];
        }
        $sort = (string) ($filters['sort']['value'] ?? '');

        $answer = self::modrinth('search', [
            'query' => $search,
            'facets' => json_encode($facets),
            'index' => array_key_exists($sort, self::SORTS) ? $sort : ($search === '' ? 'downloads' : 'relevance'),
            'offset' => ($page - 1) * self::PER_PAGE,
            'limit' => self::PER_PAGE,
        ]);
        $this->modrinthDown = $answer === null;

        $list = $this->listBySlug();
        $rows = [];
        foreach ($answer['hits'] ?? [] as $hit) {
            $row = $this->row((string) ($hit['slug'] ?? ''), $hit + ['id' => $hit['project_id'] ?? '', 'updated' => $hit['date_modified'] ?? null], $list);
            if ($row !== null) {
                $rows[$row['slug']] = $row;
            }
        }

        return new LengthAwarePaginator($rows, min((int) ($answer['total_hits'] ?? 0), 100 * self::PER_PAGE), self::PER_PAGE, $page);
    }

    /**
     * The mods in the list, with what Modrinth says about each.
     *
     * @return LengthAwarePaginator<string, array<string, mixed>>
     */
    protected function yourMods(string $search, int $page): LengthAwarePaginator
    {
        $list = $this->listBySlug();
        $projects = [];
        if ($list !== []) {
            $answer = self::modrinth('projects', ['ids' => json_encode(array_keys($list))]);
            foreach ($answer ?? [] as $project) {
                $projects[strtolower((string) ($project['slug'] ?? ''))] = $project;
                $projects[strtolower((string) ($project['id'] ?? ''))] = $project;
            }
        }

        $rows = [];
        foreach ($list as $slug => $entry) {
            $project = $projects[$slug] ?? null;
            $row = $project !== null
                ? $this->row(explode('@', $entry)[0], $project, $list)
                : [
                    ArrayRecord::getKeyName() => explode('@', $entry)[0], 'slug' => explode('@', $entry)[0], 'title' => explode('@', $entry)[0], 'pack' => false,
                    'description' => 'Modrinth has no mod with this name (or can\'t be reached right now). The installer skips it.', 'found' => false,
                    'icon' => null, 'author' => '', 'categories' => [], 'players' => null, 'downloads' => null, 'updated' => null,
                    'in_list' => true, 'pinned' => str_contains($entry, '@') ? explode('@', $entry, 2)[1] : '', 'status' => $this->status($entry),
                ];
            if ($row !== null && ($search === '' || str_contains(strtolower($row['title'] . ' ' . $row['slug']), strtolower(trim($search))))) {
                $rows[$row['slug']] = $row;
            }
        }

        return new LengthAwarePaginator(array_slice($rows, (max(1, $page) - 1) * self::PER_PAGE, self::PER_PAGE, true), count($rows), self::PER_PAGE, max(1, $page));
    }

    /**
     * Modrinth search for modpacks that run on a server with a loader the installer knows.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<string, array<string, mixed>>
     */
    protected function browsePacks(string $search, int $page, array $filters): LengthAwarePaginator
    {
        $page = max(1, min($page, 100));
        $search = mb_substr(trim($search), 0, 100);
        $facets = [
            ['project_type:modpack'],
            array_map(fn ($loader) => 'categories:' . $loader, array_keys(self::LOADERS)),
            ['server_side:required', 'server_side:optional'],
        ];
        $minecraft = $this->installInfo()['minecraft'] ?? '';
        if (($filters['minecraft']['value'] ?? '') === 'same' && $minecraft !== '') {
            $facets[] = ['versions:' . $minecraft];
        }
        $sort = (string) ($filters['sort']['value'] ?? '');

        $answer = self::modrinth('search', [
            'query' => $search,
            'facets' => json_encode($facets),
            'index' => array_key_exists($sort, self::SORTS) ? $sort : ($search === '' ? 'downloads' : 'relevance'),
            'offset' => ($page - 1) * self::PER_PAGE,
            'limit' => self::PER_PAGE,
        ]);
        $this->modrinthDown = $answer === null;

        $rows = [];
        foreach ($answer['hits'] ?? [] as $hit) {
            $row = $this->packRow($hit);
            if ($row !== null) {
                $rows[$row['slug']] = $row;
            }
        }

        return new LengthAwarePaginator($rows, min((int) ($answer['total_hits'] ?? 0), 100 * self::PER_PAGE), self::PER_PAGE, $page);
    }

    /**
     * One table row from a Modrinth modpack search hit, in the shape of a mod's row.
     *
     * @param  array<string, mixed>  $hit
     * @return array<string, mixed>|null
     */
    private function packRow(array $hit): ?array
    {
        $slug = (string) ($hit['slug'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $slug)) {
            return null;
        }
        $wanted = strtolower($this->wantedPack());
        $installed = strtolower($this->installInfo()['pack_slug'] ?? '');
        $title = mb_substr((string) ($hit['title'] ?? $slug), 0, 80);
        Cache::put('loomnode:modrinth:title:' . strtolower($slug), $title, now()->addDay());
        $mine = $wanted !== '' && in_array($wanted, [strtolower($slug), strtolower((string) ($hit['project_id'] ?? ''))], true);

        return [
            ArrayRecord::getKeyName() => $slug,
            'slug' => $slug,
            'pack' => true,
            'title' => $title,
            'description' => mb_substr((string) ($hit['description'] ?? ''), 0, 200),
            'found' => true,
            'icon' => self::iconUrl((string) ($hit['icon_url'] ?? '')),
            'author' => mb_substr((string) ($hit['author'] ?? ''), 0, 40),
            // for a pack the tags are the loaders it comes for
            'categories' => array_values(array_intersect_key(self::LOADERS, array_flip(array_filter((array) ($hit['categories'] ?? []), 'is_string')))),
            'players' => null,
            'downloads' => (int) ($hit['downloads'] ?? 0),
            'updated' => is_string($hit['date_modified'] ?? null) ? $hit['date_modified'] : null,
            'in_list' => $mine,
            'pinned' => '',
            'status' => !$mine ? null : ($wanted === $installed && !$this->packVersionWaiting() ? 'Installed' : 'Waiting for install'),
        ];
    }

    private function packVersionWaiting(): bool
    {
        return array_filter($this->pendingChanges()['risky'], fn ($change) => str_contains($change, ' → ')) !== [];
    }

    /**
     * A pack's versions for the loaders the installer knows, newest first: what goes into the Modpack
     * version setting => what the picker shows. The version id stands in for a name the setting can't hold.
     *
     * @return array<string, string>
     */
    protected function packVersionOptions(string $slug): array
    {
        $options = [];
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $slug)) {
            return $options;
        }
        foreach (array_slice(self::modrinth('project/' . $slug . '/version') ?? [], 0, 80) as $version) {
            $loaders = array_intersect_key(self::LOADERS, array_flip(array_filter((array) ($version['loaders'] ?? []), 'is_string')));
            if ($loaders === []) {
                continue;
            }
            $name = (string) ($version['version_number'] ?? '');
            $key = preg_match('/^[A-Za-z0-9._+-]{1,64}$/', $name) && $name !== 'latest' ? $name : (string) ($version['id'] ?? '');
            if ($key !== '') {
                $options[$key] ??= mb_substr($name, 0, 50) . ' · ' . ($version['version_type'] ?? 'release')
                    . ' · Minecraft ' . implode(', ', array_slice(array_filter((array) ($version['game_versions'] ?? []), 'is_string'), 0, 3))
                    . ' · ' . implode('/', $loaders);
            }
        }

        return $options;
    }

    protected function usePack(string $slug, string $title, string $version): void
    {
        abort_unless($this->canEdit(), 403);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $slug) || ($version !== 'latest' && !array_key_exists($version, $this->packVersionOptions($slug)))) {
            return;
        }
        if ($this->setMinecraftVariable('MC_MODPACK', $slug) && $this->setMinecraftVariable('MC_MODPACK_VERSION', $version)) {
            Notification::make()->title($title . ' picked')->body('Press Install changes to install it.')->success()->send();
        }
        $this->flushCachedTableRecords();
    }

    /** In the Modpack box: another version of the pack the server has. Public so Filament finds it by name. */
    public function changePackVersionAction(): Action
    {
        return Action::make('changePackVersion')
            ->label('Change version')
            ->icon(TablerIcon::Versions)
            ->button()
            ->outlined()
            ->color('gray')
            ->visible(fn () => $this->wantedPack() !== '' && $this->canEdit())
            ->modalHeading('Modpack version')
            ->modalDescription('For the newest version of the same Minecraft version, Update modpack (top of the page) is the easy way. Here you can pick any version. A world can break on another Minecraft version or on an older pack, so a backup is made before the install. Nothing changes until you press Install changes.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('lg')
            ->fillForm(function () {
                $version = (string) $this->minecraftVariable('MC_MODPACK_VERSION')?->variable_value;
                $info = $this->installInfo();
                $installed = array_key_exists($info['pack_version'] ?? '', $this->packVersionOptions($this->wantedPack())) ? $info['pack_version'] : ($info['pack_version_id'] ?? null);

                return ['version' => $version !== '' && $version !== 'latest' ? $version : $installed];
            })
            ->schema(fn () => [
                Select::make('version')
                    ->label('Version')
                    ->options($this->packVersionOptions($this->wantedPack()))
                    ->required()
                    ->searchable(),
            ])
            ->action(function (array $data) {
                abort_unless($this->canEdit(), 403);
                $version = (string) ($data['version'] ?? '');
                if ($this->wantedPack() === '' || !array_key_exists($version, $this->packVersionOptions($this->wantedPack()))) {
                    return;
                }
                if ($this->setMinecraftVariable('MC_MODPACK_VERSION', $version)) {
                    Notification::make()->title('Modpack version set to ' . $version)->body('Press Install changes to install it.')->success()->send();
                }
                $this->flushCachedTableRecords();
            });
    }

    public function removePackAction(): Action
    {
        return Action::make('removePack')
            ->label('Remove modpack')
            ->icon(TablerIcon::Trash)
            ->button()
            ->outlined()
            ->color('danger')
            ->visible(fn () => $this->wantedPack() !== '' && $this->canEdit())
            ->requiresConfirmation()
            ->modalHeading('Remove the modpack')
            ->modalDescription('The next install takes the pack\'s mods off the server; the mod loader and your own mods stay. Everything the pack\'s mods added to the world goes with them for good, so a backup is made before the install. Nothing changes until you press Install changes.')
            ->modalSubmitActionLabel('Remove modpack')
            ->action(function () {
                abort_unless($this->canEdit(), 403);
                if ($this->setMinecraftVariable('MC_MODPACK', '') && $this->setMinecraftVariable('MC_MODPACK_VERSION', 'latest')) {
                    Notification::make()->title('Modpack removed')->body('Press Install changes to take it off the server.')->success()->send();
                }
                $this->flushCachedTableRecords();
            });
    }

    /** In the box at the top: the mod loader with no mods listed (for your own jars) and its version. */
    public function modLoaderAction(): Action
    {
        return Action::make('modLoader')
            ->label('Mod loader')
            ->icon(TablerIcon::Settings)
            ->button()
            ->outlined()
            ->color('gray')
            ->visible(fn () => $this->wantedPack() === '' && ($this->installInfo()['type'] ?? '') !== 'modrinth' && $this->installInfo() !== [] && $this->canEdit())
            ->modalHeading('Mod loader')
            ->modalDescription('Adding a mod on the Browse tab sets this for you. Pick a loader here only to get it without any mods from the list, for example to upload your own jars into mods/ (Files).')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('lg')
            ->fillForm(fn () => [
                'loader' => (string) $this->minecraftVariable('MC_LOADER')?->variable_value ?: 'auto',
                'loader_version' => (string) $this->minecraftVariable('MC_LOADER_VERSION')?->variable_value ?: 'latest',
            ])
            ->schema(fn () => [
                Select::make('loader')
                    ->label('Mod loader')
                    ->options(['auto' => 'Automatic: what your mods need, vanilla without mods'] + self::LOADERS)
                    ->selectablePlaceholder(false)
                    ->required()
                    ->disabled(fn () => $this->currentMods() !== [])
                    ->helperText(fn () => $this->currentMods() !== [] ? 'The mods in your list are for this loader. Remove them first to pick another.' : 'A server stays on its loader: changing it later takes out everything the old loader\'s mods added.'),
                TextInput::make('loader_version')
                    ->label('Loader version')
                    ->helperText('latest, or a version number from the loader\'s own site. Leave it on latest unless a mod asks for a certain one.')
                    ->required()
                    ->maxLength(64),
            ])
            ->action(function (array $data) {
                abort_unless($this->canEdit(), 403);
                $saved = true;
                if ($this->currentMods() === [] && array_key_exists((string) ($data['loader'] ?? ''), ['auto' => ''] + self::LOADERS)) {
                    $saved = $this->setMinecraftVariable('MC_LOADER', (string) $data['loader']);
                }
                $saved = $saved && $this->setMinecraftVariable('MC_LOADER_VERSION', trim((string) ($data['loader_version'] ?? 'latest')) ?: 'latest');
                if ($saved) {
                    Notification::make()->title('Mod loader saved')->success()->send();
                    $this->redirect(static::getUrl(), navigate: true);
                }
            });
    }

    private static function iconUrl(string $url): ?string
    {
        if (preg_match('#^https://cdn\.modrinth\.com/data/([A-Za-z0-9]{4,16})/([A-Za-z0-9_-]{1,100}\.(?:png|jpe?g|webp|gif))$#', $url, $m)) {
            return route('loomnode.mod-icon', ['project' => $m[1], 'file' => $m[2]]);
        }

        return null;
    }

    /** @return array<string, string> the list by lowercase slug: "sodium" => "sodium@0.6.0" */
    private function listBySlug(): array
    {
        $list = [];
        foreach ($this->currentMods() as $entry) {
            $list[strtolower(explode('@', $entry)[0])] = $entry;
        }

        return $list;
    }

    private function status(?string $entry): ?string
    {
        if ($entry === null) {
            return null;
        }

        if (in_array(strtolower($entry), array_map('strtolower', $this->pendingChanges()['add']), true)) {
            return 'Waiting for install';
        }
        // the installer leaves out what it can't use here (no version for this loader or Minecraft, client-only); the Console says why
        $skipped = array_map('strtolower', array_filter(explode(',', $this->installInfo()['mods_skipped'] ?? '')));

        return in_array(strtolower(explode('@', $entry)[0]), $skipped, true) ? 'Skipped at install (see Console)' : 'Installed';
    }

    /**
     * One table row from a Modrinth search hit or project; null for what is never offered.
     *
     * @param  array<string, mixed>  $mod
     * @param  array<string, string>  $list
     * @return array<string, mixed>|null
     */
    private function row(string $slug, array $mod, array $list): ?array
    {
        $id = (string) ($mod['id'] ?? '');
        if (!preg_match(self::SLUG, $slug) || in_array($slug, self::TUNNEL_MODS, true) || in_array($id, self::TUNNEL_MODS, true)) {
            return null;
        }
        $entry = $list[strtolower($slug)] ?? $list[strtolower($id)] ?? null;
        $server = $mod['server_side'] ?? 'unknown';
        $client = $mod['client_side'] ?? 'unknown';
        if ($entry === null && $server === 'optional' && $client === 'required') {
            return null;
        }
        $title = mb_substr((string) ($mod['title'] ?? $slug), 0, 80);
        Cache::put('loomnode:modrinth:title:' . strtolower($slug), $title, now()->addDay());

        return [
            ArrayRecord::getKeyName() => $slug,
            'slug' => $slug,
            'pack' => false,
            'title' => $title,
            'description' => mb_substr((string) ($mod['description'] ?? ''), 0, 200),
            'found' => true,
            'icon' => self::iconUrl((string) ($mod['icon_url'] ?? '')),
            'author' => mb_substr((string) ($mod['author'] ?? ''), 0, 40),
            'categories' => array_values(array_map(
                fn ($category) => self::CATEGORIES[$category],
                array_slice(array_filter((array) ($mod['display_categories'] ?? $mod['categories'] ?? []), fn ($category) => is_string($category) && array_key_exists($category, self::CATEGORIES)), 0, 3),
            )),
            'players' => $client === 'required' ? 'Players need it too' : null,
            'downloads' => (int) ($mod['downloads'] ?? 0),
            'updated' => is_string($mod['updated'] ?? null) ? $mod['updated'] : null,
            'in_list' => $entry !== null,
            'pinned' => $entry !== null && str_contains($entry, '@') ? explode('@', $entry, 2)[1] : '',
            'status' => $this->status($entry),
        ];
    }

    private static function short(int $number): string
    {
        return match (true) {
            $number >= 1_000_000 => round($number / 1_000_000, 1) . 'M',
            $number >= 1_000 => round($number / 1_000) . 'k',
            default => (string) $number,
        };
    }

    private static function ago(?string $date): string
    {
        try {
            return $date ? Carbon::parse($date)->diffForHumans() : '';
        } catch (Throwable) {
            return '';
        }
    }

    // ---- Changing the list -----------------------------------------------------------------------------

    /** @param  string[]  $mods */
    private function saveMods(array $mods): bool
    {
        $saved = $this->setMinecraftVariable('MC_MODS', implode(',', $mods));
        $this->flushCachedTableRecords();

        return $saved;
    }

    protected function addMod(string $slug, string $title): void
    {
        abort_unless($this->canEdit(), 403);
        if (!preg_match(self::SLUG, $slug) || in_array($slug, self::TUNNEL_MODS, true) || array_key_exists(strtolower($slug), $this->listBySlug())) {
            return;
        }

        // The first mod on a vanilla server fixes the loader the list is for.
        $target = $this->modTarget();
        if ($target['choose'] && !$this->setMinecraftVariable('MC_LOADER', $target['loader'])) {
            return;
        }

        if ($this->saveMods([...$this->currentMods(), $slug])) {
            Notification::make()->title($title . ' added')->body('Pick more, then press Install changes.')->success()->send();
            if ($target['choose']) {
                // the loader is fixed now: load the page again so the Mod loader filter goes away
                $this->redirect(static::getUrl(), navigate: true);
            }
        }
    }

    protected function removeMod(string $slug, string $title): void
    {
        abort_unless($this->canEdit(), 403);
        $mods = array_values(array_filter($this->currentMods(), fn ($entry) => strtolower(explode('@', $entry)[0]) !== strtolower($slug)));
        if (!$this->saveMods($mods)) {
            return;
        }

        // An empty list on a server that is still vanilla: back to "auto", so it stays vanilla and the loader can be picked again.
        $installed = $this->installInfo()['loader'] ?? '';
        if ($mods === [] && ($installed === '' || $installed === 'vanilla') && $this->minecraftVariable('MC_LOADER')?->variable_value !== 'auto') {
            $this->setMinecraftVariable('MC_LOADER', 'auto');
            Notification::make()->title($title . ' removed from the list')->body('The list is empty, so the mod loader can be picked again.')->success()->send();
            $this->redirect(static::getUrl(), navigate: true);

            return;
        }

        Notification::make()->title($title . ' removed from the list')->success()->send();
    }

    protected function pinMod(string $slug, string $title, string $version): void
    {
        abort_unless($this->canEdit(), 403);
        if ($version !== '' && !array_key_exists($version, $this->modVersions($slug, ''))) {
            return;
        }
        $mods = array_map(
            fn ($entry) => strtolower(explode('@', $entry)[0]) === strtolower($slug) ? explode('@', $entry)[0] . ($version !== '' ? '@' . $version : '') : $entry,
            $this->currentMods(),
        );
        if ($this->saveMods($mods)) {
            Notification::make()->title($title . ($version !== '' ? ' set to version ' . $version : ' set to the newest version'))->success()->send();
        }
    }

    /**
     * A mod's versions for this server's loader and Minecraft version, newest first: what goes after the
     * "@" => what the picker shows. The version id stands in for a version name the setting can't hold.
     *
     * @return array<string, string>
     */
    protected function modVersions(string $slug, string $pinned): array
    {
        $target = $this->modTarget();
        $options = [];
        if (preg_match(self::SLUG, $slug) && $target['minecraft'] !== '') {
            $versions = self::modrinth('project/' . $slug . '/version', [
                'loaders' => json_encode([$target['loader']]),
                'game_versions' => json_encode([$target['minecraft']]),
            ]);
            foreach (array_slice($versions ?? [], 0, 60) as $version) {
                $name = (string) ($version['version_number'] ?? '');
                $key = preg_match('/^[A-Za-z0-9_.+-]{1,64}$/', $name) ? $name : (string) ($version['id'] ?? '');
                if ($key !== '') {
                    $options[$key] ??= mb_substr($name, 0, 60) . ' · ' . ($version['version_type'] ?? 'release') . ' · ' . substr((string) ($version['date_published'] ?? ''), 0, 10);
                }
            }
        }
        if ($pinned !== '' && !array_key_exists($pinned, $options)) {
            $options[$pinned] = $pinned . ' · not offered for this server';
        }

        return $options;
    }

    // ---- Installing ---------------------------------------------------------------------------------------

    /** Shown in the "waiting to be installed" box; public so Filament finds it by name. */
    public function installAction(): Action
    {
        return Action::make('install')
            ->label('Install changes')
            ->icon(TablerIcon::Download)
            ->button()
            ->visible(fn () => $this->pendingCount() > 0 && $this->canInstall())
            ->requiresConfirmation()
            ->modalHeading('Install changes')
            ->modalDescription(function () {
                $text = $this->pendingSummary() . '. ' . ($this->pendingRisk() !== '' ? $this->pendingRisk() . ' ' : '');
                $text .= match (true) {
                    !$this->installNeedsBackup() => 'The server stops and reinstalls; your world and configs stay. ',
                    $this->getRecord()->backup_limit <= 0 => 'This server has no backups, so none is made first: download your world (Files) if you\'re not sure. The server stops and reinstalls. ',
                    default => 'A backup is made first; then the server stops and reinstalls by itself. ',
                };

                return $text . 'When the Console says the install is done, press Start: the first start with new mods takes longer.';
            })
            ->modalSubmitActionLabel(fn () => $this->installNeedsBackup() && $this->getRecord()->backup_limit > 0 ? 'Back up and install' : 'Stop and install')
            ->action(function () {
                $server = $this->getRecord();
                abort_unless($this->canInstall() && $this->pendingCount() > 0, 403);
                if ($this->installNeedsBackup()) {
                    $this->backupThenReinstall($server, $this->backupName());

                    return;
                }
                $this->reinstallNow($server);
            });
    }

    /** @return array<Action> */
    protected function getDefaultHeaderActions(): array
    {
        return [$this->updatePackAction()];
    }
}
