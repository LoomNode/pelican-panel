<?php

namespace App\Traits\Filament;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Filament\Server\Pages\Console;
use App\Filament\Server\Pages\Startup;
use App\Models\Backup;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Backups\InitiateBackupService;
use App\Services\Servers\ReinstallServerService;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Minecraft mods and modpack updates (LoomNode, 2026-11-10; shared by Game Settings and the Mods page
 * since 2026-11-13). Only for a server whose egg has the stage 2 Minecraft settings (MC_MODS, MC_MODPACK).
 * The Panel only writes settings and starts a reinstall: the egg's installer does the downloading
 * (eggs/minecraft-install.sh) and records what it installed in .loomnode/install.
 */
trait ManagesMinecraftMods
{
    public const MODRINTH = 'https://api.modrinth.com/v2/';

    /** Mods the installer never installs: they tunnel the server through someone else's relay (Kyle, 11-10). */
    public const TUNNEL_MODS = ['e4mc', 'qANg5Jrr'];

    public const LOADERS = ['fabric' => 'Fabric', 'neoforge' => 'NeoForge', 'forge' => 'Forge'];

    /** @var array<string, string>|null */
    private ?array $installInfo = null;

    abstract public function getRecord(): Server;

    protected static function hasMinecraftMods(Server $server): bool
    {
        $env = $server->egg->variables()->pluck('env_variable')->all();

        return in_array('MC_MODS', $env, true) && in_array('MC_MODPACK', $env, true);
    }

    protected function minecraftVariable(string $env): ?ServerVariable
    {
        $server = $this->getRecord();
        $server->ensureVariablesExist();

        /** @var ?ServerVariable $variable */
        $variable = $server->serverVariables()
            ->whereHas('variable', fn (Builder $query) => $query->where('env_variable', $env)->where('user_viewable', true)->where('user_editable', true))
            ->first();

        return $variable;
    }

    /**
     * Saves one of the Minecraft settings the way Game Settings does (the setting's own rules, the
     * activity log), without the "old -> new" notice. False when it didn't pass; the reason is shown.
     */
    protected function setMinecraftVariable(string $env, string $value): bool
    {
        $server = $this->getRecord();
        abort_unless(user()?->can(SubuserPermission::StartupUpdate, $server), 403);
        $variable = $this->minecraftVariable($env);
        abort_unless($variable !== null, 403);

        $original = (string) $variable->variable_value;
        if ($original === $value) {
            return true;
        }

        $validator = Validator::make(['variable_value' => $value], ['variable_value' => $variable->variable->rules]);
        if ($validator->fails()) {
            Notification::make()
                ->title(trans('server/startup.validation_fail', ['variable' => $variable->variable->shortName()]))
                ->body(implode(', ', $validator->errors()->all()))
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

    /**
     * What the egg's installer recorded in .loomnode/install: type, loader, minecraft, pack, pack_slug,
     * pack_version, pack_version_id, mods. Empty when the server hasn't been installed by the stage 2 egg
     * or the node can't be reached. Kept for 20 seconds, so a page that asks on every click stays quick.
     *
     * @return array<string, string>
     */
    protected function installInfo(): array
    {
        if ($this->installInfo !== null) {
            return $this->installInfo;
        }

        $server = $this->getRecord();

        return $this->installInfo = Cache::remember('loomnode:install:' . $server->id, now()->addSeconds(20), function () use ($server) {
            $info = [];
            try {
                $content = Container::getInstance()->make(DaemonFileRepository::class)->setServer($server)->getContent('.loomnode/install', 4096);
                foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
                    if (preg_match('/^([a-z_]+)=(.*)$/', $line, $m)) {
                        $info[$m[1]] = trim($m[2]);
                    }
                }
            } catch (Throwable) {
                // not installed by the stage 2 egg, or the node is unreachable: the page explains it
            }

            return $info;
        });
    }

    /**
     * Modrinth's answer, cached for 10 minutes; null when it can't be reached.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>|null
     */
    protected static function modrinth(string $path, array $query = []): ?array
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

    protected function reinstallNow(Server $server): void
    {
        try {
            Container::getInstance()->make(ReinstallServerService::class)->handle($server);
        } catch (Throwable $exception) {
            report($exception);
            Notification::make()->title(trans('server/setting.reinstall.notification_fail'))->body($exception->getMessage())->danger()->send();

            return;
        }
        Cache::forget('loomnode:install:' . $server->id);
        Activity::event('server:settings.reinstall')->log();
        Notification::make()->title(trans('server/setting.reinstall.notification_start'))->body('Watch it in the Console, then press Start.')->success()->send();
        redirect(Console::getUrl());
    }

    /**
     * A backup first, then the reinstall once the backup is done (the queue watches it). For installs that
     * can take things out of the world, like removing a mod. Without backup slots it reinstalls right away.
     */
    protected function backupThenReinstall(Server $server, string $backupName): void
    {
        if ($server->backup_limit <= 0) {
            $this->reinstallNow($server);

            return;
        }
        try {
            $backup = Container::getInstance()->make(InitiateBackupService::class)->setIgnoredFiles([])->handle($server, $backupName, true);
            Activity::event('server:backup.start')->subject($backup)->property(['name' => $backup->name, 'locked' => false])->log();
        } catch (Throwable $exception) {
            Notification::make()->title('The backup couldn\'t start, so nothing was installed')->body($exception->getMessage())->danger()->send();

            return;
        }
        $serverId = $server->id;
        $backupId = $backup->id;
        $userId = user()?->id;
        dispatch(static fn () => Startup::continueInstallAfterBackup($serverId, $backupId, $userId, 0))->delay(now()->addSeconds(20));
        Notification::make()->title('Backing up first')->body('The install starts by itself when the backup is done, usually within a few minutes. Watch the Console.')->success()->send();
        redirect(Console::getUrl());
    }

    /**
     * Runs on the queue every 20 s after backupThenReinstall() until the backup is done (at most 30 minutes):
     * a good backup starts the reinstall; after a failed or stuck one nothing is installed and the user is told.
     */
    public static function continueInstallAfterBackup(int $serverId, int $backupId, ?int $userId, int $attempt): void
    {
        $server = Server::query()->find($serverId);
        $backup = Backup::query()->find($backupId);
        if (!$server) {
            return;
        }

        if ($backup && $backup->completed_at === null && $attempt < 90) {
            dispatch(static fn () => Startup::continueInstallAfterBackup($serverId, $backupId, $userId, $attempt + 1))->delay(now()->addSeconds(20));

            return;
        }

        if ($backup && $backup->completed_at !== null && $backup->is_successful) {
            Container::getInstance()->make(ReinstallServerService::class)->handle($server);
            Cache::forget('loomnode:install:' . $server->id);
            self::logQueued(fn () => Activity::event('server:settings.reinstall')->subject($server)->property(['reason' => 'mods changed'])->log());

            return;
        }

        // The backup failed, vanished or took too long: install nothing, the changes stay waiting.
        $user = $userId ? User::query()->find($userId) : null;
        if ($user) {
            self::logQueued(fn () => Notification::make()
                ->title('Mods were not installed on ' . $server->name)
                ->body('The backup didn\'t complete, so nothing was changed. Your changes are still waiting on the Mods page; press Install changes to try again.')
                ->danger()
                ->sendToDatabase($user));
        }
    }

    /**
     * The pack's newest release for this server's Minecraft version and loader, and the newest of all.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    protected function packVersions(): array
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

    protected function packUpdateAvailable(): bool
    {
        $info = $this->installInfo();
        [$same] = $this->packVersions();

        return ($info['pack_slug'] ?? '') !== '' && $same !== null && $same['id'] !== ($info['pack_version_id'] ?? '');
    }

    protected function updatePackAction(): Action
    {
        return Action::make('loomnode_update_pack')
            ->label('Update modpack')
            ->icon(TablerIcon::Refresh)
            ->visible(fn () => self::hasMinecraftMods($this->getRecord())
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

                if (!$this->setMinecraftVariable('MC_MODPACK_VERSION', $version)) {
                    return;
                }

                if ($backup === null) {
                    $this->reinstallNow($server);

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
            Cache::forget('loomnode:install:' . $server->id);
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
}
