<?php

namespace App\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Filament\Components\Actions\PreviewStartupAction;
use App\Filament\Components\Forms\Fields\StartupVariable;
use App\Models\Backup;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ReinstallServerService;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

class Startup extends ServerFormPage
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::PlayerPlay;

    protected static ?int $navigationSort = 10;

    /**
     * @throws Exception
     */
    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->columns([
                'default' => 1,
                'md' => 2,
            ])
            ->components([
                Hidden::make('previewing')
                    ->default(false),
                // Game Settings shows only the game's settings, to admins too (LoomNode, 2026-10-21): the
                // start command and the image are plumbing, edited in the admin area (Servers > Edit).
                TextInput::make('custom_startup')
                    ->label(trans('server/startup.command'))
                    ->readOnly()
                    ->hidden()
                    ->formatStateUsing(fn () => 'Custom Startup')
                    ->hintAction(PreviewStartupAction::make()->visible(fn () => user()?->isAdmin())),
                Select::make('startup_select')
                    ->label(trans('server/startup.command'))
                    ->live()
                    ->hidden()
                    ->disabled(fn (Server $server) => !user()?->can(SubuserPermission::StartupUpdate, $server))
                    ->formatStateUsing(fn (Server $server) => $server->startup)
                    ->afterStateUpdated(function ($state, Server $server, Set $set) {
                        abort_unless(user()?->can(SubuserPermission::StartupUpdate, $server), 403);
                        $original = $server->startup;
                        $server->forceFill(['startup' => $state])->saveOrFail();

                        $set('startup', $state);
                        $set('previewing', false);

                        if ($original !== $server->startup) {
                            $startups = array_flip($server->egg->startup_commands);
                            Activity::event('server:startup.command')
                                ->property(['old' => $startups[$original], 'new' => $startups[$state]])
                                ->log();
                        }

                        Notification::make()
                            ->title(trans('server/startup.notification_startup'))
                            ->body(trans('server/startup.notification_startup_body'))
                            ->success()
                            ->send();
                    })
                    ->options(fn (Server $server) => array_flip($server->egg->startup_commands))
                    ->selectablePlaceholder(false)
                    ->hintAction(PreviewStartupAction::make()->visible(fn () => user()?->isAdmin())),
                TextInput::make('custom_image')
                    ->label(trans('server/startup.docker_image'))
                    ->readOnly()
                    ->hidden()
                    ->formatStateUsing(fn (Server $server) => $server->image),
                Select::make('image')
                    ->label(trans('server/startup.docker_image'))
                    ->live()
                    // Only a game with a choice of images shows the picker (Minecraft's Java versions).
                    ->visible(fn (Server $server) => count($server->egg->docker_images) > 1 && in_array($server->image, $server->egg->docker_images))
                    ->disabled(fn (Server $server) => !user()?->can(SubuserPermission::StartupDockerImage, $server))
                    ->afterStateUpdated(function ($state, Server $server) {
                        abort_unless(user()?->can(SubuserPermission::StartupDockerImage, $server), 403);
                        $original = $server->image;
                        $server->forceFill(['image' => $state])->saveOrFail();

                        if ($original !== $server->image) {
                            Activity::event('server:startup.image')
                                ->property(['old' => $original, 'new' => $state])
                                ->log();
                        }

                        Notification::make()
                            ->title(trans('server/startup.notification_docker'))
                            ->body(trans('server/startup.notification_docker_body'))
                            ->success()
                            ->send();
                    })
                    ->options(function (Server $server) {
                        $images = $server->egg->docker_images;

                        return array_flip($images);
                    }),
                Textarea::make('startup')
                    ->hiddenLabel()
                    ->columnSpanFull()
                    ->autosize()
                    ->readOnly()
                    ->hidden(),
                ...$this->variableSections(),
            ]);
    }

    /**
     * One section of settings per group, in the order the groups first appear, and only the first
     * open: a name "Group · Setting" puts a setting in Group (EggVariable::groupName). An egg
     * without groups gets the single Variables section it always had (LoomNode, 2026-11-01).
     *
     * @return Section[]
     */
    protected function variableSections(): array
    {
        $groups = $this->getRecord()->eggVariables()
            ->where('user_viewable', true)
            ->orderBy('sort')
            ->get()
            ->groupBy(fn (EggVariable $variable) => $variable->groupName() ?? '');

        if ($groups->keys()->all() === [''] || $groups->isEmpty()) {
            return [
                Section::make(trans('server/startup.variables'))
                    ->columnSpanFull()
                    ->schema([$this->variableRepeater('server_variables')]),
            ];
        }

        return $groups->values()->map(fn ($variables, int $index) => Section::make($variables->first()->groupName() ?? trans('server/startup.variables'))
            ->columnSpanFull()
            ->collapsible()
            ->collapsed($index > 0)
            ->schema([$this->variableRepeater('server_variables_' . $index, $variables->pluck('id')->all())])
        )->all();
    }

    private bool $variablesChecked = false;

    /**
     * The server's settings, or only those of the given egg variables.
     *
     * @param  int[]|null  $variableIds
     */
    protected function variableRepeater(string $name, ?array $variableIds = null): Repeater
    {
        return Repeater::make($name)
            ->hiddenLabel()
            ->relationship('serverVariables', function (Builder $query, Server $server) use ($variableIds) {
                if (!$this->variablesChecked) {
                    $server->ensureVariablesExist();
                    $this->variablesChecked = true;
                }

                $query->where('egg_variables.user_viewable', true);

                if ($variableIds !== null) {
                    $query->whereIn('server_variables.variable_id', $variableIds);
                }

                return $query->orderByPowerJoins('variable.sort');
            })
            ->grid()
            ->disabled(fn (Server $server) => !user()?->can(SubuserPermission::StartupUpdate, $server))
            ->reorderable(false)->addable(false)->deletable(false)
            ->schema([
                StartupVariable::make('variable_value')
                    ->fromRecord()
                    ->afterStateUpdated(function ($state, ServerVariable $serverVariable) {
                        $this->update($state, $serverVariable);
                    }),
            ])
            ->columnSpan(6);
    }

    // ---- Minecraft mods and modpack updates (LoomNode, 2026-11-10) -------------------------------------
    // Only for a server whose egg has the stage 2 Minecraft settings (MC_MODS, MC_MODPACK). The mod browser
    // searches Modrinth for mods that run on a server, for this server's loader and Minecraft version, and
    // writes the Mods setting; "Update modpack" backs up, then reinstalls at the pack's newest release for the
    // same Minecraft version. The egg's installer does the downloading (eggs/minecraft-install.sh).

    private const MODRINTH = 'https://api.modrinth.com/v2/';

    /** Mods the installer never installs: they tunnel the server through someone else's relay (Kyle, 11-10). */
    private const TUNNEL_MODS = ['e4mc', 'qANg5Jrr'];

    private const LOADERS = ['fabric' => 'Fabric', 'neoforge' => 'NeoForge', 'forge' => 'Forge'];

    /** @var array<string, string>|null */
    private ?array $installInfo = null;

    /** @return array<Action> */
    protected function getDefaultHeaderActions(): array
    {
        return [$this->modsAction(), $this->updatePackAction()];
    }

    private function minecraftVariable(string $env): ?ServerVariable
    {
        $server = $this->getRecord();
        $server->ensureVariablesExist();

        /** @var ?ServerVariable $variable */
        $variable = $server->serverVariables()
            ->whereHas('variable', fn (Builder $query) => $query->where('env_variable', $env)->where('user_viewable', true)->where('user_editable', true))
            ->first();

        return $variable;
    }

    private function isModdableMinecraft(): bool
    {
        $env = $this->getRecord()->egg->variables()->pluck('env_variable')->all();

        return in_array('MC_MODS', $env, true) && in_array('MC_MODPACK', $env, true);
    }

    /**
     * What the egg's installer recorded in .loomnode/install: type, loader, minecraft, pack, pack_slug,
     * pack_version, pack_version_id. Empty when the server hasn't been installed by the stage 2 egg.
     *
     * @return array<string, string>
     */
    private function installInfo(): array
    {
        if ($this->installInfo !== null) {
            return $this->installInfo;
        }

        $info = [];
        try {
            $content = Container::getInstance()->make(DaemonFileRepository::class)->setServer($this->getRecord())->getContent('.loomnode/install', 4096);
            foreach (preg_split('/\r?\n/', $content) as $line) {
                if (preg_match('/^([a-z_]+)=(.*)$/', $line, $m)) {
                    $info[$m[1]] = trim($m[2]);
                }
            }
        } catch (Throwable) {
            // not installed by the stage 2 egg, or the node is unreachable: the actions explain it
        }

        return $this->installInfo = $info;
    }

    /**
     * Modrinth's answer, cached for 10 minutes; null when it can't be reached.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    private static function modrinth(string $path, array $query = []): ?array
    {
        return Cache::remember('loomnode:modrinth:' . md5($path . '?' . http_build_query($query)), now()->addMinutes(10), function () use ($path, $query) {
            try {
                $response = Http::withUserAgent('LoomNode/1.0 (support@loomnode.cloud)')->timeout(10)->get(self::MODRINTH . $path, $query);

                return $response->successful() ? $response->json() : null;
            } catch (Throwable) {
                return null;
            }
        }) ?: null;
    }

    /** @return string[] the Mods setting as a list ("slug" or "slug@version") */
    private function currentMods(): array
    {
        $value = (string) $this->minecraftVariable('MC_MODS')?->variable_value;

        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;]/', $value)))));
    }

    /**
     * The loader and Minecraft version mods must fit, and whether the server is still vanilla.
     *
     * @return array{loader: string, minecraft: string, vanilla: bool}
     */
    private function modTarget(?string $pickedLoader = null): array
    {
        $info = $this->installInfo();
        $loader = $info['loader'] ?? '';
        $vanilla = $loader === '' || $loader === 'vanilla';

        return [
            'loader' => $vanilla ? ($pickedLoader ?: 'fabric') : $loader,
            'minecraft' => $info['minecraft'] ?? '',
            'vanilla' => $vanilla,
        ];
    }

    /**
     * Modrinth search: mods that run on a server, for the loader and Minecraft version. An empty search
     * lists the most downloaded ones.
     *
     * @return array<string, array{title: string, description: string}>
     */
    private static function searchMods(string $search, string $loader, string $minecraft): array
    {
        // Libraries come along when a mod needs them, so they aren't listed.
        $facets = [['project_type:mod'], ['categories:' . $loader], ['server_side:required', 'server_side:optional'], ['categories!=library']];
        if ($minecraft !== '') {
            $facets[] = ['versions:' . $minecraft];
        }

        $answer = self::modrinth('search', [
            'query' => mb_substr(trim($search), 0, 100),
            'facets' => json_encode($facets),
            'index' => trim($search) === '' ? 'downloads' : 'relevance',
            'limit' => 40,
        ]);

        $mods = [];
        foreach ($answer['hits'] ?? [] as $hit) {
            $slug = (string) ($hit['slug'] ?? '');
            if ($slug === '' || !preg_match('/^[A-Za-z0-9_.+-]+$/', $slug) || in_array($slug, self::TUNNEL_MODS, true) || in_array($hit['project_id'] ?? '', self::TUNNEL_MODS, true)) {
                continue;
            }
            $server = $hit['server_side'] ?? 'unknown';
            $client = $hit['client_side'] ?? 'unknown';
            if ($server === 'optional' && $client === 'required') {
                continue;   // a client mod with an optional server part (minimaps and the like): no use on its own
            }
            $players = $client === 'required' ? 'players need it too' : 'players don\'t need it';
            $mods[$slug] = [
                'title' => mb_substr((string) ($hit['title'] ?? $slug), 0, 80),
                'description' => mb_substr((string) ($hit['description'] ?? ''), 0, 160) . ' · ' . $players . ' · ' . number_format((int) ($hit['downloads'] ?? 0)) . ' downloads',
            ];
            Cache::put('loomnode:modrinth:title:' . $slug, $mods[$slug]['title'], now()->addDay());
        }

        return $mods;
    }

    private function modsAction(): Action
    {
        return Action::make('loomnode_mods')
            ->label('Mods')
            ->icon(TablerIcon::Puzzle)
            ->visible(fn () => $this->isModdableMinecraft() && user()?->can(SubuserPermission::StartupUpdate, $this->getRecord()))
            ->modalHeading('Mods from Modrinth')
            ->modalWidth('3xl')
            ->modalSubmitActionLabel('Save')
            ->schema(function () {
                $target = $this->modTarget();
                $current = $this->currentMods();
                $titles = [];
                foreach ($current as $mod) {
                    $slug = explode('@', $mod)[0];
                    $titles[$mod] = (Cache::get('loomnode:modrinth:title:' . $slug) ?? $slug) . ($slug !== $mod ? ' (version ' . explode('@', $mod, 2)[1] . ')' : '');
                }

                return [
                    TextEntry::make('loomnode_mods_about')
                        ->hiddenLabel()
                        ->state(function () use ($target) {
                            $info = $this->installInfo();
                            if ($target['minecraft'] === '') {
                                return 'Reinstall this server once (Settings), then mods can be picked here.';
                            }
                            if (($info['type'] ?? '') === 'modrinth') {
                                return 'Mods added on top of ' . ($info['pack'] ?? 'the modpack') . ': ' . self::LOADERS[$target['loader']] . ' mods for Minecraft ' . $target['minecraft'] . '.';
                            }
                            if ($target['vanilla']) {
                                return 'This server runs vanilla Minecraft ' . $target['minecraft'] . '. Adding mods makes it a modded server on the loader you pick. Mods use more memory and CPU than vanilla.';
                            }

                            return 'Mods for ' . self::LOADERS[$target['loader']] . ' on Minecraft ' . $target['minecraft'] . '. Only mods that run on a server are listed; mods they need come along. When a mod says "players need it too", everyone who joins must install it as well.';
                        }),
                    Select::make('loader')
                        ->label('Mod loader')
                        ->options(self::LOADERS)
                        ->default('fabric')
                        ->selectablePlaceholder(false)
                        ->live()
                        ->helperText('Fabric has the most mods for recent Minecraft versions. Big mods like Create are NeoForge or Forge. A server stays on its loader.')
                        ->visible(fn () => $target['vanilla'] && $target['minecraft'] !== ''),
                    CheckboxList::make('keep')
                        ->label('Your mods (untick to remove)')
                        ->options($titles)
                        ->default($current)
                        ->columns(2)
                        ->visible($current !== []),
                    TextInput::make('search')
                        ->label('Find mods')
                        ->placeholder('Search Modrinth, e.g. "chunky" or "performance"')
                        ->live(debounce: 600)
                        ->visible($target['minecraft'] !== ''),
                    CheckboxList::make('add')
                        ->label(fn (Get $get) => trim((string) $get('search')) === '' ? 'Most downloaded' : 'Results')
                        ->options(function (Get $get) use ($target, $current) {
                            $loader = $target['vanilla'] ? ($get('loader') ?: 'fabric') : $target['loader'];
                            $have = array_map(fn ($mod) => explode('@', $mod)[0], $current);
                            $options = [];
                            foreach ((array) $get('add') as $slug) {   // ticked ones stay while the search changes
                                $options[$slug] = Cache::get('loomnode:modrinth:title:' . $slug) ?? $slug;
                            }
                            foreach (self::searchMods((string) $get('search'), array_key_exists($loader, self::LOADERS) ? $loader : 'fabric', $target['minecraft']) as $slug => $mod) {
                                if (!in_array($slug, $have, true)) {
                                    $options[$slug] ??= $mod['title'];
                                }
                            }

                            return $options;
                        })
                        ->descriptions(function (Get $get) use ($target) {
                            $loader = $target['vanilla'] ? ($get('loader') ?: 'fabric') : $target['loader'];

                            return array_map(fn ($mod) => $mod['description'], self::searchMods((string) $get('search'), array_key_exists($loader, self::LOADERS) ? $loader : 'fabric', $target['minecraft']));
                        })
                        ->columns(1)
                        ->visible($target['minecraft'] !== ''),
                    Toggle::make('install_now')
                        ->label('Install now: the server stops and reinstalls. Your world and configs stay; the first start sets up the mods.')
                        ->default(true)
                        ->visible(fn () => user()?->can(SubuserPermission::SettingsReinstall, $this->getRecord())),
                ];
            })
            ->action(function (array $data) {
                $server = $this->getRecord();
                abort_unless(user()?->can(SubuserPermission::StartupUpdate, $server), 403);
                $variable = $this->minecraftVariable('MC_MODS');
                abort_unless($variable !== null, 403);

                $current = $this->currentMods();
                $keep = array_values(array_intersect($current, (array) ($data['keep'] ?? [])));
                $add = array_values(array_filter((array) ($data['add'] ?? []), fn ($slug) => is_string($slug) && preg_match('/^[A-Za-z0-9_.+-]+$/', $slug) && !in_array($slug, self::TUNNEL_MODS, true)));
                $new = implode(',', array_values(array_unique([...$keep, ...$add])));

                $target = $this->modTarget($data['loader'] ?? null);
                if ($target['vanilla'] && $add !== [] && array_key_exists($data['loader'] ?? '', self::LOADERS)) {
                    $loaderVariable = $this->minecraftVariable('MC_LOADER');
                    if ($loaderVariable !== null && $loaderVariable->variable_value !== $data['loader']) {
                        $this->update($data['loader'], $loaderVariable);
                    }
                }

                if ($new !== implode(',', $current)) {
                    $this->update($new, $variable);
                    if ($variable->refresh()->variable_value !== $new) {
                        return;   // didn't pass the setting's rules; update() said why
                    }
                }

                if (($data['install_now'] ?? false) && user()->can(SubuserPermission::SettingsReinstall, $server)) {
                    $this->reinstall($server);
                } else {
                    Notification::make()->title('Mods saved')->body('Press Reinstall (Settings) to install them.')->success()->send();
                }
            });
    }

    private function reinstall(Server $server): void
    {
        try {
            Container::getInstance()->make(ReinstallServerService::class)->handle($server);
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title(trans('server/setting.reinstall.notification_fail'))->body($exception->getMessage())->danger()->send();

            return;
        }
        Activity::event('server:settings.reinstall')->log();
        Notification::make()->title(trans('server/setting.reinstall.notification_start'))->body('Watch it in the Console, then press Start.')->success()->send();
        redirect(Console::getUrl());
    }

    /**
     * The pack's newest release for this server's Minecraft version and loader, and the newest of all.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function packVersions(): array
    {
        $info = $this->installInfo();
        $slug = $info['pack_slug'] ?? '';
        if ($slug === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
            return [null, null];
        }
        $pick = fn (array $versions) => collect($versions)->firstWhere('version_type', 'release') ?? ($versions[0] ?? null);
        $same = self::modrinth('project/' . $slug . '/version', array_filter([
            'game_versions' => ($info['minecraft'] ?? '') !== '' ? json_encode([$info['minecraft']]) : null,
            'loaders' => ($info['loader'] ?? '') !== '' ? json_encode([$info['loader']]) : null,
        ]));
        $all = self::modrinth('project/' . $slug . '/version');

        return [$same ? $pick($same) : null, $all ? $pick($all) : null];
    }

    private function updatePackAction(): Action
    {
        return Action::make('loomnode_update_pack')
            ->label('Update modpack')
            ->icon(TablerIcon::Refresh)
            ->visible(fn () => $this->isModdableMinecraft()
                && filled($this->minecraftVariable('MC_MODPACK')?->variable_value)
                && user()?->can(SubuserPermission::StartupUpdate, $this->getRecord())
                && user()->can(SubuserPermission::SettingsReinstall, $this->getRecord())
                && user()->can(SubuserPermission::BackupCreate, $this->getRecord()))
            ->modalHeading('Update modpack')
            ->modalSubmitActionLabel('Back up and update')
            ->modalSubmitAction(fn (Action $action) => $action->disabled(!$this->packUpdateAvailable()))
            ->schema(fn () => [
                TextEntry::make('loomnode_pack_state')
                    ->hiddenLabel()
                    ->state(function () {
                        $info = $this->installInfo();
                        if (($info['pack_slug'] ?? '') === '') {
                            return ['Reinstall this server once (Settings) so the Panel knows which pack version is installed.'];
                        }
                        [$same, $all] = $this->packVersions();
                        $lines = ['Installed: ' . ($info['pack'] ?? '') . ' ' . ($info['pack_version'] ?? '') . ' (Minecraft ' . ($info['minecraft'] ?? '?') . ').'];
                        if ($same === null) {
                            $lines[] = 'Modrinth can\'t be reached right now, or it lists no version for this Minecraft version.';
                        } elseif ($same['id'] === ($info['pack_version_id'] ?? '')) {
                            $lines[] = 'This is the newest release for Minecraft ' . ($info['minecraft'] ?? '') . '. Nothing to update.';
                        } else {
                            $lines[] = 'Newest for Minecraft ' . ($info['minecraft'] ?? '') . ': ' . $same['version_number'] . '.';
                            $backup = $this->getRecord()->backup_limit > 0
                                ? 'First a backup is made (when all backup slots are used, the oldest unlocked one is replaced).'
                                : 'This server has no backup slots, so no backup is made first.';
                            $lines[] = $backup . ' Then the server stops and reinstalls at the new version. Your world stays, and configs you changed are kept unless the new version replaces them. Press Start when the install is done.';
                        }
                        if ($all !== null && $same !== null && $all['id'] !== $same['id'] && !in_array($info['minecraft'] ?? '', $all['game_versions'] ?? [], true)) {
                            $lines[] = 'There is also ' . $all['version_number'] . ' for Minecraft ' . implode(', ', $all['game_versions'] ?? []) . '. Moving to another Minecraft version can break a world, so that isn\'t done here: back up, then type that version under Modpack version and Reinstall.';
                        }

                        return $lines;
                    })
                    ->listWithLineBreaks(),
            ])
            ->action(function () {
                $server = $this->getRecord();
                abort_unless(user()?->can(SubuserPermission::StartupUpdate, $server)
                    && user()->can(SubuserPermission::SettingsReinstall, $server)
                    && user()->can(SubuserPermission::BackupCreate, $server), 403);
                if (!$this->packUpdateAvailable()) {
                    return;
                }
                [$same] = $this->packVersions();
                $variable = $this->minecraftVariable('MC_MODPACK_VERSION');
                abort_unless($variable !== null, 403);
                $old = $variable->variable_value;
                $version = preg_match('/^[A-Za-z0-9._+-]{1,64}$/', $same['version_number']) ? $same['version_number'] : $same['id'];

                $backup = null;
                if ($server->backup_limit > 0) {
                    try {
                        $backup = Container::getInstance()->make(InitiateBackupService::class)->setIgnoredFiles([])
                            ->handle($server, 'Before modpack update to ' . mb_substr($version, 0, 60), true);
                        Activity::event('server:backup.start')->subject($backup)->property(['name' => $backup->name, 'locked' => false])->log();
                    } catch (Throwable $exception) {
                        Notification::make()->title('The backup couldn\'t start, so nothing was updated')->body($exception->getMessage())->danger()->send();

                        return;
                    }
                }

                $this->update($version, $variable);
                if ($variable->refresh()->variable_value !== $version) {
                    return;
                }

                if ($backup === null) {
                    $this->reinstall($server);

                    return;
                }
                $serverId = $server->id;
                $backupId = $backup->id;
                $oldVersion = $old ?? '';
                dispatch(static fn () => Startup::continuePackUpdate($serverId, $backupId, $oldVersion, 0))->delay(now()->addSeconds(20));
                Notification::make()->title('Backing up first')->body('The update starts by itself when the backup is done, usually within a few minutes. Watch the Console.')->success()->send();
                redirect(Console::getUrl());
            });
    }

    private function packUpdateAvailable(): bool
    {
        $info = $this->installInfo();
        [$same] = $this->packVersions();

        return ($info['pack_slug'] ?? '') !== '' && $same !== null && $same['id'] !== ($info['pack_version_id'] ?? '');
    }

    /**
     * Runs on the queue every 20 s after "Update modpack" until the backup is done (at most 30 minutes):
     * a good backup starts the reinstall; a failed or stuck one puts the old pack version back, so nothing changes.
     */
    public static function continuePackUpdate(int $serverId, int $backupId, string $oldVersion, int $attempt): void
    {
        $server = Server::query()->find($serverId);
        $backup = Backup::query()->find($backupId);
        if (!$server) {
            return;
        }

        if ($backup && $backup->completed_at === null && $attempt < 90) {
            dispatch(static fn () => Startup::continuePackUpdate($serverId, $backupId, $oldVersion, $attempt + 1))->delay(now()->addSeconds(20));

            return;
        }

        if ($backup && $backup->completed_at !== null && $backup->is_successful) {
            Container::getInstance()->make(ReinstallServerService::class)->handle($server);
            self::logQueued(fn () => Activity::event('server:settings.reinstall')->subject($server)->property(['reason' => 'modpack update'])->log());

            return;
        }

        // The backup failed, vanished or took too long: undo the version change.
        $server->serverVariables()
            ->whereHas('variable', fn (Builder $query) => $query->where('env_variable', 'MC_MODPACK_VERSION'))
            ->update(['variable_value' => $oldVersion]);
        self::logQueued(fn () => Activity::event('server:startup.edit')->subject($server)->property(['variable' => 'MC_MODPACK_VERSION', 'old' => null, 'new' => $oldVersion, 'reason' => 'modpack update cancelled: the backup did not complete'])->log());
    }

    /** Activity from the queue has no request (and so no IP); the log entry is a bonus, never a reason to fail. */
    private static function logQueued(callable $log): void
    {
        try {
            $log();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can(SubuserPermission::StartupRead, Filament::getTenant()), 403);
    }

    public static function canAccess(): bool
    {
        return parent::canAccess() && user()?->can(SubuserPermission::StartupRead, Filament::getTenant());
    }

    public function update(null|string|bool $state, ServerVariable $serverVariable): void
    {
        abort_unless(user()?->can(SubuserPermission::StartupUpdate, $this->getRecord()), 403);
        abort_unless($serverVariable->server_id === $this->getRecord()->id, 403);
        abort_unless($serverVariable->variable->user_viewable, 403);

        if (!$serverVariable->variable->user_editable) {
            return;
        }

        if (is_bool($state)) {
            $state = $state ? '1' : '0';
        }

        $original = $serverVariable->variable_value;

        try {
            $validator = Validator::make(
                ['variable_value' => $state],
                ['variable_value' => $serverVariable->variable->rules]
            );

            if ($validator->fails()) {
                Notification::make()
                    ->title(trans('server/startup.validation_fail', ['variable' => $serverVariable->variable->shortName()]))
                    ->body(implode(', ', $validator->errors()->all()))
                    ->danger()
                    ->send();

                return;
            }

            ServerVariable::query()->updateOrCreate([
                'server_id' => $this->getRecord()->id,
                'variable_id' => $serverVariable->variable->id,
            ], [
                'variable_value' => $state ?? '',
            ]);

            if ($original !== $state) {
                Activity::event('server:startup.edit')
                    ->property([
                        'variable' => $serverVariable->variable->env_variable,
                        'old' => EggVariable::forActivity($serverVariable->variable->env_variable, $original),
                        'new' => EggVariable::forActivity($serverVariable->variable->env_variable, $state),
                    ])
                    ->log();
            }

            Notification::make()
                ->title(trans('server/startup.update', ['variable' => $serverVariable->variable->shortName()]))
                ->body(fn () => $original . ' -> ' . $state)
                ->success()
                ->send();
        } catch (Exception $e) {
            Notification::make()
                ->title(trans('server/startup.fail', ['variable' => $serverVariable->variable->shortName()]))
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getTitle(): string
    {
        return trans('server/startup.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('server/startup.title');
    }
}
