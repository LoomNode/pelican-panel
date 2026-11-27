<?php

namespace App\Filament\Server\Pages;

use App\Enums\ContainerStatus;
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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Sleep;
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
 *
 * Options tab (2026-11-27): each mod's own options as dropdowns. The running game writes the option lists
 * to a file when sent OPTIONS_COMMAND; the choices that differ from a mod's default are kept in the hidden
 * setting DST_MOD_OPTIONS (format at encodeOptions()). The tab is there only when the egg has that setting.
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

    /** The hidden setting with the mod options: not viewable or editable in Game Settings, only here. */
    private const OPTIONS = 'DST_MOD_OPTIONS';

    private const OPTIONS_FILE = 'loomnode_mod_options';

    private const OPTIONS_MAX_LENGTH = 60000;

    private const OPTIONS_MAX_BYTES = 2097152;

    /**
     * Sent to the running game's console: it writes every mod's name, version and option list as JSON to
     * save/loomnode_mod_options. A fixed line; nothing from a user ever goes into it.
     */
    public const OPTIONS_COMMAND = 'local t={} for _,n in ipairs(KnownModIndex:GetModNames()) do local i=KnownModIndex:GetModInfo(n) if i then t[n]={name=i.name,version=i.version,options=i.configuration_options} end end local ok,s=pcall(json.encode_compliant or json.encode,t) TheSim:SetPersistentString("loomnode_mod_options",ok and s or "ERR "..tostring(s),false)';

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

    /**
     * The Options tab's dropdowns: m<mod ID>.o<option number> => c<choice number>.
     *
     * @var array<string, mixed>
     */
    public array $optionsData = [];

    /** Which option lists the dropdowns were built from; a save against other lists is refused. */
    public string $optionsVersion = '';

    private ?bool $hasOptions = null;

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
        abort_unless(in_array($env, [...self::SETTINGS, self::OPTIONS, 'DST_WORLD', 'DST_MODS_OWN'], true), 403);

        $server = $this->getRecord();
        $server->ensureVariablesExist();

        /** @var ?ServerVariable $variable */
        $variable = $server->serverVariables()
            ->whereHas('variable', fn (Builder $query) => $query->where('env_variable', $env))
            ->first();

        return $variable;
    }

    /**
     * Saves a setting the way Game Settings does: the setting's own rules, the activity log. It writes the
     * server's value directly, so it also saves a setting the egg hides from Game Settings (DST_MOD_OPTIONS),
     * behind the same permission.
     */
    private function setVariable(string $env, string $value): bool
    {
        abort_unless($this->canEdit() && in_array($env, [...self::SETTINGS, self::OPTIONS], true), 403);
        $variable = $this->variable($env);
        abort_unless($variable !== null, 403);

        $original = (string) $variable->variable_value;
        if ($original === $value) {
            return true;
        }

        $validator = Validator::make(['variable_value' => $value], ['variable_value' => $variable->variable->rules]);
        if ($validator->fails()) {
            Notification::make()
                ->title(match ($env) {
                    'DST_MODS' => 'The mod list is full',
                    self::OPTIONS => 'The mod options can\'t be saved',
                    default => 'That collection can\'t be saved',
                })
                ->body($env === 'DST_MODS' ? 'Remove a mod first, or put your mods in a Steam Workshop collection and use the Collection tab.' : implode(', ', $validator->errors()->all()))
                ->danger()
                ->send();

            return false;
        }

        $variable->forceFill(['variable_value' => $value])->saveOrFail();
        Activity::event('server:startup.edit')
            ->property([
                'variable' => $env,
                'old' => self::forLog(EggVariable::forActivity($env, $original)),
                'new' => self::forLog(EggVariable::forActivity($env, $value)),
            ])
            ->log();

        return true;
    }

    /** The options setting can be long; the activity log keeps its start. */
    private static function forLog(?string $value): ?string
    {
        return $value !== null && strlen($value) > 2000 ? substr($value, 0, 2000) . '…' : $value;
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

    /** @return array{add: string[], remove: string[], collection: bool, options: bool} */
    private function pending(): array
    {
        $started = $this->started();
        $mods = $this->currentMods();

        return [
            'add' => array_values(array_diff($mods, $started['mods'])),
            'remove' => array_values(array_diff($started['mods'], $mods)),
            'collection' => $this->collection() !== $started['collection'],
            'options' => $this->optionsPending(),
        ];
    }

    private function pendingCount(): int
    {
        $pending = $this->pending();

        return count($pending['add']) + count($pending['remove']) + (int) $pending['collection'] + (int) $pending['options'];
    }

    private function pendingSummary(): string
    {
        $pending = $this->pending();
        $parts = array_filter([
            $pending['add'] !== [] ? count($pending['add']) . (count($pending['add']) === 1 ? ' mod' : ' mods') . ' to add' : null,
            $pending['remove'] !== [] ? count($pending['remove']) . ' to take out' : null,
            $pending['collection'] ? ($this->collection() === '' ? 'the collection to take out' : 'a collection to add') : null,
            $pending['options'] ? 'mod options changed' : null,
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

    private function state(): ContainerStatus
    {
        try {
            return $this->getRecord()->retrieveStatus();
        } catch (Throwable) {
            return ContainerStatus::Missing;
        }
    }

    /** When the server's current run began, from the node's uptime; null when it isn't up or the node can't say. */
    private function startedAt(): ?int
    {
        if (!$this->state()->isStartingOrRunning()) {
            return null;
        }
        $server = $this->getRecord();
        [$measured, $uptime] = Cache::remember('loomnode:dst-uptime:' . $server->id, now()->addSeconds(15), function () use ($server) {
            try {
                $details = Container::getInstance()->make(DaemonServerRepository::class)->setServer($server)->getDetails();
            } catch (Throwable) {
                $details = [];
            }

            // uptime is in milliseconds
            return [now()->getTimestamp(), (int) Arr::get($details, 'utilization.uptime', 0)];
        });

        return $uptime > 0 ? $measured - intdiv($uptime, 1000) : null;
    }

    // ---- Mod options: the stored setting ---------------------------------------------------------------

    private function hasOptions(): bool
    {
        return $this->hasOptions ??= $this->getRecord()->egg->variables()->where('env_variable', self::OPTIONS)->exists();
    }

    private function storedOptions(): string
    {
        return $this->hasOptions() ? (string) $this->variable(self::OPTIONS)?->variable_value : '';
    }

    /**
     * One option value as it is stored: n + a number without exponent, b1 / b0, or s + the string as hex.
     * Null for anything that can't be stored (not a number, boolean or string; not finite; too long).
     */
    public static function encodeOptionValue(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'b1' : 'b0';
        }
        if (is_string($value)) {
            return strlen($value) <= 400 ? 's' . bin2hex($value) : null;
        }
        if (is_int($value)) {
            return 'n' . $value;
        }
        if (!is_float($value) || !is_finite($value)) {
            return null;
        }
        // the shortest text that reads back as the same number (0.05, 3.0, 1.0E-7), then the exponent written out
        if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?(?:E([+-]?[0-9]+))?$/', var_export($value, true), $m)) {
            return null;
        }
        $whole = $m[2];
        $fraction = $m[3] ?? '';
        $exponent = (int) ($m[4] ?? 0);
        if (abs($exponent) > 30) {
            return null;
        }
        if ($exponent > 0) {
            $fraction = str_pad($fraction, $exponent, '0');
            $whole .= substr($fraction, 0, $exponent);
            $fraction = substr($fraction, $exponent);
        } elseif ($exponent < 0) {
            $whole = str_pad($whole, 1 - $exponent, '0', STR_PAD_LEFT);
            $fraction = substr($whole, $exponent) . $fraction;
            $whole = substr($whole, 0, $exponent);
        }
        $whole = ltrim($whole, '0') ?: '0';
        $fraction = rtrim($fraction, '0');
        $number = $whole . ($fraction !== '' ? '.' . $fraction : '');

        return 'n' . ($m[1] === '-' && $number !== '0' ? '-' : '') . $number;
    }

    /** The value a stored token stands for; null when it isn't one. */
    public static function decodeOptionValue(string $token): int|float|bool|string|null
    {
        if ($token === 'b1' || $token === 'b0') {
            return $token === 'b1';
        }
        if (preg_match('/^n(-?[0-9]+)(\.[0-9]+)?\z/', $token, $m)) {
            return !isset($m[2]) && strlen($m[1]) <= 15 ? (int) $m[1] : (float) substr($token, 1);
        }
        if (preg_match('/^s((?:[0-9a-f]{2}){0,400})\z/', $token, $m)) {
            return (string) hex2bin($m[1]);
        }

        return null;
    }

    /**
     * The options setting from choices: one line, entries joined by ";", each
     * <mod ID>:<option name as hex>:<n number | b1 | b0 | s string as hex>, sorted by mod ID, options in the
     * order given. What can't be stored (an ID that isn't 1 to 12 digits, a name over 200 bytes, a value
     * that isn't a number, boolean or string) is left out.
     *
     * @param  array<int|string, array<int|string, mixed>>  $choices  [mod ID][option name] => value
     */
    public static function encodeOptions(array $choices): string
    {
        uksort($choices, fn ($a, $b) => [strlen(ltrim((string) $a, '0')), (string) $a] <=> [strlen(ltrim((string) $b, '0')), (string) $b]);
        $entries = [];
        foreach ($choices as $id => $options) {
            if (!preg_match('/^[0-9]{1,12}\z/', (string) $id) || !is_array($options)) {
                continue;
            }
            foreach ($options as $name => $value) {
                $name = (string) $name;
                $token = self::encodeOptionValue($value);
                if ($name !== '' && strlen($name) <= 200 && $token !== null) {
                    $entries[] = $id . ':' . bin2hex($name) . ':' . $token;
                }
            }
        }

        return implode(';', $entries);
    }

    /**
     * The options setting as choices. A value that isn't in the stored format as a whole counts as empty.
     *
     * @return array<string, array<string, int|float|bool|string>> [mod ID][option name] => value (PHP turns all-digit keys into integers)
     */
    public static function decodeOptions(?string $stored): array
    {
        $stored = (string) $stored;
        if ($stored === '' || strlen($stored) > self::OPTIONS_MAX_LENGTH) {
            return [];
        }
        $choices = [];
        foreach (explode(';', $stored) as $position => $entry) {
            if ($entry === '' && $position > 0 && $position === substr_count($stored, ';')) {
                // one ";" at the very end is fine
                continue;
            }
            $value = null;
            if (preg_match('/^([0-9]{1,12}):((?:[0-9a-f]{2}){1,200}):(n-?[0-9]+(?:\.[0-9]+)?|b[01]|s(?:[0-9a-f]{2}){0,400})\z/', $entry, $m)) {
                $value = self::decodeOptionValue($m[3]);
            }
            if ($value === null) {
                return [];
            }
            $choices[$m[1]][(string) hex2bin($m[2])] = $value;
        }

        return $choices;
    }

    /** True from a saved options change until the server has started again. */
    private function optionsPending(): bool
    {
        if (!$this->hasOptions()) {
            return false;
        }
        $key = 'loomnode:dst-options-changed:' . $this->getRecord()->id;
        $marker = Cache::get($key);
        if (!is_array($marker) || !is_int($marker['at'] ?? null)) {
            return false;
        }
        $startedAt = $this->startedAt();
        if ($startedAt !== null && $startedAt > $marker['at']) {
            Cache::forget($key);

            return false;
        }

        return true;
    }

    /** Remembers that the options changed, and what they were when the server last started with them. */
    private function markOptionsChanged(string $from, string $to): void
    {
        $key = 'loomnode:dst-options-changed:' . $this->getRecord()->id;
        $marker = $this->optionsPending() ? Cache::get($key) : null;
        $from = is_array($marker) && is_string($marker['from'] ?? null) ? $marker['from'] : $from;
        if ($from === $to) {
            Cache::forget($key);
        } else {
            Cache::forever($key, ['at' => now()->getTimestamp(), 'from' => $from]);
        }
    }

    // ---- Mod options: what the game says each mod has --------------------------------------------------

    /**
     * The option lists from the file the game wrote ("KLEI     1 " and then JSON). Mod authors decide what
     * is in it and the server's owner can overwrite it, so nothing in it is trusted: every type is checked,
     * counts and lengths are capped, and what doesn't fit is left out. Null when it isn't that file at all.
     *
     * @return array<string, array{name: string, version: string, options: array<int, array{name: string, label: string, hover: string, default: string, choices: array<int, array{label: string, token: string}>}>}>|null by mod ID; \"default\" and \"token\" are values as stored
     */
    public static function parseOptionsFile(string $content): ?array
    {
        $start = strpos($content, '{');
        if ($start === false || strlen($content) > self::OPTIONS_MAX_BYTES) {
            return null;
        }
        try {
            $data = json_decode(substr($content, $start), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }

        $mods = [];
        foreach ($data as $key => $mod) {
            if (count($mods) >= 200) {
                break;
            }
            if (!is_array($mod) || !preg_match('/^workshop-([0-9]{1,12})\z/', (string) $key, $m)) {
                continue;
            }
            $id = $m[1];
            $options = [];
            $names = [];
            $list = $mod['options'] ?? null;
            foreach (is_array($list) && array_is_list($list) ? $list : [] as $option) {
                if (count($options) >= 300) {
                    break;
                }
                $name = is_array($option) ? ($option['name'] ?? null) : null;
                $choiceList = is_array($option) ? ($option['options'] ?? null) : null;
                $default = is_array($option) ? self::encodeOptionValue($option['default'] ?? null) : null;
                // no name or no choices: a heading between options in the game's own screen
                if (!is_string($name) || $name === '' || strlen($name) > 200 || isset($names[$name]) || $default === null || !is_array($choiceList) || !array_is_list($choiceList)) {
                    continue;
                }
                $choices = [];
                foreach ($choiceList as $choice) {
                    if (count($choices) >= 200) {
                        break;
                    }
                    $token = is_array($choice) ? self::encodeOptionValue($choice['data'] ?? null) : null;
                    if ($token === null) {
                        continue;
                    }
                    $label = self::cut($choice['description'] ?? null, 200);
                    if ($label === '') {
                        $value = self::decodeOptionValue($token);
                        $label = is_bool($value) ? ($value ? 'Yes' : 'No') : self::cut(is_string($value) ? $value : substr($token, 1), 200);
                    }
                    $choices[] = ['label' => $label, 'token' => $token];
                }
                if ($choices === []) {
                    continue;
                }
                $names[$name] = true;
                $options[] = [
                    'name' => $name,
                    'label' => self::cut($option['label'] ?? null, 200) ?: self::cut($name, 200),
                    'hover' => self::cut($option['hover'] ?? null, 500),
                    'default' => $default,
                    'choices' => $choices,
                ];
            }
            $mods[$id] = [
                'name' => self::cut($mod['name'] ?? null, 200) ?: 'Mod ' . $id,
                'version' => self::cut($mod['version'] ?? null, 40),
                'options' => $options,
            ];
        }

        return $mods;
    }

    /** Text from the game's file as one plain line of at most $length characters; anything but text or a number is empty. */
    private static function cut(mixed $text, int $length): string
    {
        if (!is_string($text) && !is_int($text) && !is_float($text)) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $text)), 0, $length);
    }

    /** The world's save folder, where the game writes the file; null when the world's name isn't a plain one. */
    private function saveFolder(): ?string
    {
        $world = trim((string) $this->variable('DST_WORLD')?->variable_value);
        $world = $world === '' ? 'world' : $world;

        return preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,31}\z/', $world) ? '/DoNotStarveTogether/config/' . $world . '/Master/save' : null;
    }

    /** @return array<string, mixed>|null the option lists in the file on the server, whenever it was written */
    private function readOptionsFile(): ?array
    {
        $folder = $this->saveFolder();
        if ($folder === null) {
            return null;
        }
        try {
            $content = (string) Container::getInstance()->make(DaemonFileRepository::class)->setServer($this->getRecord())
                ->getContent($folder . '/' . self::OPTIONS_FILE, self::OPTIONS_MAX_BYTES);
        } catch (Throwable) {
            return null;
        }

        return self::parseOptionsFile($content);
    }

    /** When and how big the file is now, to see that the game wrote it again; null when it isn't there. */
    private function optionsFileStamp(): ?string
    {
        try {
            $listing = Container::getInstance()->make(DaemonFileRepository::class)->setServer($this->getRecord())->getDirectory((string) $this->saveFolder());
        } catch (Throwable) {
            return null;
        }
        foreach ($listing as $file) {
            if (is_array($file) && ($file['name'] ?? null) === self::OPTIONS_FILE) {
                return json_encode([$file['modified'] ?? null, $file['size'] ?? null]) ?: null;
            }
        }

        return null;
    }

    /**
     * Has the running game write its option lists, and reads them: about 1 to 3 seconds, at most about 4.
     * Only ever called for a server in the running state.
     *
     * @return array<string, mixed>|null
     */
    private function askGame(): ?array
    {
        $server = $this->getRecord();
        // Only for someone who can change the settings (Kyle 2026-11-27): a look-only user never sends the game anything.
        if (!$this->canEdit() || $this->saveFolder() === null || $this->state() !== ContainerStatus::Running) {
            return null;
        }
        $before = $this->optionsFileStamp();
        try {
            $server->send(self::OPTIONS_COMMAND);
        } catch (Throwable) {
            return null;
        }
        for ($try = 0; $try < 8; $try++) {
            Sleep::for(500)->milliseconds();
            $stamp = $this->optionsFileStamp();
            if ($stamp === null || $stamp === $before) {
                continue;
            }
            $mods = $this->readOptionsFile();
            if ($mods !== null) {
                return $mods;
            }
        }

        return null;
    }

    /**
     * The option lists this page works with, kept for 10 minutes: "live" when the running game wrote them
     * for this page, not live when they are the file left from an earlier run. Null when there are none.
     *
     * @return array{at: int, live: bool, mods: array<string, mixed>}|null
     */
    private function optionLists(): ?array
    {
        $lists = Cache::get('loomnode:dst-options:' . $this->getRecord()->id);

        return is_array($lists) && is_array($lists['mods'] ?? null) ? $lists : null;
    }

    /**
     * Gets the option lists when the ones kept won't do. A running server is asked (again) when nothing is
     * kept, what is kept is older than its last start or lacks a mod it started with, or on Refresh. A
     * server that isn't running is never sent anything: the file from its last run is read as it is.
     */
    private function loadOptions(bool $force = false): void
    {
        if (!$this->hasOptions()) {
            return;
        }
        $key = 'loomnode:dst-options:' . $this->getRecord()->id;
        $lists = $this->optionLists();
        $age = $lists === null ? 0 : now()->getTimestamp() - (int) $lists['at'];

        if ($this->state() !== ContainerStatus::Running) {
            if ($lists !== null && !$force) {
                return;
            }
            $mods = $this->readOptionsFile();
        } else {
            $startedAt = $this->startedAt();
            $known = $lists === null ? [] : array_map('strval', array_keys($lists['mods']));
            $missing = array_diff(array_filter($this->started()['mods'], fn ($id) => strlen($id) <= 12), $known) !== [];
            $current = $lists !== null && ($startedAt === null || $lists['at'] >= $startedAt);
            $complete = $lists !== null && ($lists['live'] ?? false) && !$missing;
            // a list that isn't live, or lacks a mod, is asked for again after a minute, not at every click
            if (!$force && $current && ($complete || $age < 60)) {
                return;
            }
            $mods = $this->askGame();
            if ($mods !== null) {
                Cache::put($key, ['at' => now()->getTimestamp(), 'live' => true, 'mods' => $mods], now()->addMinutes(10));

                return;
            }
            $mods = $this->readOptionsFile();
        }

        if ($mods === null) {
            Cache::forget($key);
        } else {
            Cache::put($key, ['at' => now()->getTimestamp(), 'live' => false, 'mods' => $mods], now()->addMinutes(10));
        }
    }

    /** @return string[]|null every mod the server has: its list and its collection; null when Steam can't say what the collection holds */
    private function serverMods(): ?array
    {
        $mods = $this->currentMods();
        if ($this->collection() !== '') {
            $inCollection = self::steamCollection($this->collection());
            if ($inCollection === null) {
                return null;
            }
            $mods = [...$mods, ...$inCollection];
        }

        return array_values(array_unique($mods));
    }

    /**
     * The mods that get a section: on the server, known to the game, with at least one option.
     *
     * @param  array<int|string, mixed>  $mods  the option lists
     * @return array<string, array<string, mixed>> by "m<ID>"
     */
    private function modsWithOptions(array $mods): array
    {
        $shown = [];
        foreach ($this->serverMods() ?? $this->currentMods() as $id) {
            if (isset($mods[$id]) && $mods[$id]['options'] !== []) {
                $shown['m' . $id] = $mods[$id];
            }
        }

        return $shown;
    }

    /** Sets the dropdowns: the saved choice, else the mod's default. */
    private function fillOptions(): void
    {
        $lists = $this->optionLists();
        $stored = self::decodeOptions($this->storedOptions());
        $state = [];
        foreach ($this->modsWithOptions($lists['mods'] ?? []) as $key => $mod) {
            foreach ($mod['options'] as $number => $option) {
                $saved = $stored[substr($key, 1)][$option['name']] ?? null;
                $tokens = array_column($option['choices'], 'token');
                $at = $saved === null ? false : array_search(self::encodeOptionValue($saved), $tokens, true);
                $at = $at === false ? array_search($option['default'], $tokens, true) : $at;
                $state[$key]['o' . $number] = $at === false ? null : 'c' . $at;
            }
        }
        $this->optionsData = $state;
        $this->optionsVersion = $lists === null ? '' : md5(serialize($lists['mods']));
    }

    /**
     * Saves the dropdowns: only choices that differ from a mod's default, and only a choice the mod offers.
     * What is stored for a mod whose options aren't known right now stays as it is; what is stored for a mod
     * the server no longer has goes.
     */
    protected function saveOptions(): void
    {
        abort_unless($this->canEdit() && $this->hasOptions(), 403);
        $this->loadOptions();
        $lists = $this->optionLists();
        if ($lists === null) {
            Notification::make()->title('The mods\' options aren\'t known right now')->body('Nothing was saved. Start the server, then press Refresh.')->danger()->send();

            return;
        }
        if (md5(serialize($lists['mods'])) !== $this->optionsVersion) {
            $this->fillOptions();
            Notification::make()->title('The mods\' options changed')->body('Nothing was saved. Check your choices and save again.')->warning()->send();

            return;
        }

        $original = $this->storedOptions();
        $stored = self::decodeOptions($original);
        $onServer = $this->serverMods();
        $shown = $this->modsWithOptions($lists['mods']);
        $choices = [];
        foreach ($stored as $id => $options) {
            if (!isset($shown['m' . $id]) && ($onServer === null || in_array((string) $id, $onServer, true))) {
                $choices[$id] = $options;
            }
        }
        foreach ($shown as $key => $mod) {
            $id = substr($key, 1);
            foreach ($mod['options'] as $number => $option) {
                $picked = $this->optionsData[$key]['o' . $number] ?? null;
                $choice = is_string($picked) && preg_match('/^c([0-9]{1,3})\z/', $picked, $m) ? ($option['choices'][(int) $m[1]] ?? null) : null;
                if ($choice !== null) {
                    if ($choice['token'] !== $option['default']) {
                        $choices[$id][$option['name']] = self::decodeOptionValue($choice['token']);
                    }
                } elseif ($picked !== null && $picked !== '' && isset($stored[$id][$option['name']])) {
                    // not one of this option's choices: ignored, what was saved stays
                    $choices[$id][$option['name']] = $stored[$id][$option['name']];
                }
            }
        }

        $value = self::encodeOptions($choices);
        if (strlen($value) > self::OPTIONS_MAX_LENGTH) {
            Notification::make()->title('Too many options to save')->body('Nothing was saved. Put some options back to their default: only changed ones take room.')->danger()->send();

            return;
        }
        if (!$this->setVariable(self::OPTIONS, $value)) {
            return;
        }
        $this->fillOptions();
        if ($value === $original) {
            Notification::make()->title('Nothing to save')->body('The options are as they were.')->success()->send();

            return;
        }
        $this->markOptionsChanged($original, $value);
        Notification::make()->title('Mod options saved')->body('They load at the next restart.')->success()->send();
    }

    /** Takes a removed mod's choices out of the options setting, unless the collection still brings the mod. */
    private function dropOptions(string $id): void
    {
        if (!$this->hasOptions() || in_array($id, $this->collection() === '' ? [] : (self::steamCollection($this->collection()) ?? [$id]), true)) {
            return;
        }
        $stored = self::decodeOptions($this->storedOptions());
        if (isset($stored[$id])) {
            unset($stored[$id]);
            $this->setVariable(self::OPTIONS, self::encodeOptions($stored));
        }
    }

    /**
     * The Options tab: a note or two, one fold-out section per mod, Save.
     *
     * @return array<Component>
     */
    private function optionsComponents(): array
    {
        $lists = $this->optionLists();
        $state = $this->state();
        $running = $state === ContainerStatus::Running;
        if ($lists === null) {
            [$heading, $description] = match (true) {
                $running && !$this->canEdit() => ['The mods\' options haven\'t been read yet', 'They show here once someone who can change this server\'s settings has opened this tab.'],
                $running => ['The game didn\'t answer', 'Its mods\' options couldn\'t be read. Give it a moment if it has just started, then press Refresh.'],
                $state->isStartingOrRunning() => ['The server is still starting', 'Its mods\' options show here once it is up. Press Refresh then.'],
                default => ['Start the server to see its mods\' options', 'The game lists them once it runs with the mods.'],
            };

            return [EmptyState::make($heading)->description($description)->icon(TablerIcon::Adjustments)->key('optionsEmpty')->footer([$this->refreshOptionsAction()])];
        }

        $components = [];
        // The start script leaves modoverrides.lua alone while this Game Settings switch is on, so nothing chosen here is used.
        if (in_array(strtolower(trim((string) $this->variable('DST_MODS_OWN')?->variable_value)), ['1', 'true', 'on'], true)) {
            $components[] = Text::make('"I set up mod options myself" is on in Game Settings, so the choices here are not used. Turn it off there to use them.')->color('warning')->key('optionsOwn');
        }
        if (!$running) {
            $components[] = Text::make('These options are from the last time the server ran.')->color('gray')->key('optionsOld');
        } elseif (!$lists['live'] && !$this->canEdit()) {
            $components[] = Text::make('These options are from the last time they were read.')->color('gray')->key('optionsOld');
        } elseif (!$lists['live']) {
            $components[] = Text::make('The game didn\'t answer just now, so these options are from an earlier run. Press Refresh to ask again.')->color('gray')->key('optionsOld');
        }
        $waiting = array_slice(array_values(array_filter($this->currentMods(), fn ($id) => !isset($lists['mods'][$id]))), 0, 20);
        $titles = $waiting === [] ? [] : (self::steamDetails($waiting) ?? []);
        foreach ($waiting as $id) {
            $title = mb_substr(trim((string) ($titles[$id]['title'] ?? '')), 0, 80) ?: $id;
            $components[] = Text::make($title . ': ' . ($state->isOffline() ? 'start' : 'restart') . ' the server once so it downloads; its options show here after that.')
                ->color('gray')
                ->key('optionsWaiting' . $id);
        }

        $shown = $this->modsWithOptions($lists['mods']);
        if ($shown === []) {
            $components[] = EmptyState::make($waiting !== [] ? 'Restart the server once so the new mods load; their options show here after that' : 'None of this server\'s mods have options')
                ->description($waiting !== [] ? null : 'A mod with options gets a section here.')
                ->icon(TablerIcon::Adjustments)
                ->key('optionsEmpty')
                ->footer([$this->refreshOptionsAction()]);

            return $components;
        }

        $canEdit = $this->canEdit();
        $first = true;
        foreach ($shown as $key => $mod) {
            $fields = [];
            foreach ($mod['options'] as $number => $option) {
                $choices = [];
                $hasDefault = false;
                foreach ($option['choices'] as $at => $choice) {
                    $isDefault = !$hasDefault && $choice['token'] === $option['default'];
                    $hasDefault = $hasDefault || $isDefault;
                    $choices['c' . $at] = $choice['label'] . ($isDefault ? ' (default)' : '');
                }
                $fields[] = Select::make($key . '.o' . $number)
                    ->label($option['label'])
                    ->options($choices)
                    // the browser's own dropdown: a page can hold a few hundred of these
                    ->native()
                    ->helperText($option['hover'] !== '' ? $option['hover'] : null)
                    ->placeholder('The mod\'s own default')
                    ->selectablePlaceholder(!$hasDefault)
                    ->disabled(!$canEdit);
            }
            $components[] = Section::make($mod['name'])
                ->description(count($fields) === 1 ? '1 option' : count($fields) . ' options')
                ->key('options' . $key)
                ->collapsible()
                ->collapsed(!$first)
                ->columns(['default' => 1, 'md' => 2])
                ->schema($fields);
            $first = false;
        }
        $components[] = Actions::make([$this->saveOptionsAction(), $this->refreshOptionsAction()])->key('optionsActions');

        return $components;
    }

    public function saveOptionsAction(): Action
    {
        return Action::make('saveOptions')
            ->label('Save')
            ->icon(TablerIcon::DeviceFloppy)
            ->button()
            ->visible(fn () => $this->canEdit() && $this->hasOptions())
            ->action(fn () => $this->saveOptions());
    }

    public function refreshOptionsAction(): Action
    {
        return Action::make('refreshOptions')
            ->label('Refresh')
            ->icon(TablerIcon::Refresh)
            ->button()
            ->outlined()
            ->color('gray')
            ->visible(fn () => $this->hasOptions())
            ->action(function () {
                abort_unless($this->hasOptions(), 403);
                $this->loadOptions(true);
                $this->fillOptions();
            });
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
                    . ($this->hasOptions()
                        ? 'A mod\'s own options are on the Options tab once the server has started with it.'
                        : 'Every mod runs with its normal options; to set options yourself, see "Mods: I set up mod options myself" in Game Settings.'))
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
                    'options' => Tab::make('options')->label('Options')->icon(TablerIcon::Adjustments)->visible(fn () => $this->hasOptions()),
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
            Group::make(fn () => $this->optionsComponents())
                ->key('modOptions')
                ->statePath('optionsData')
                ->visible(fn () => $this->activeTab === 'options' && $this->hasOptions()),
            EmbeddedTable::make()->visible(fn () => $this->activeTab !== 'options'),
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
        if ($this->activeTab === 'options') {
            if ($this->hasOptions()) {
                $this->loadOptions();
                $this->fillOptions();
            } else {
                $this->activeTab = 'browse';
            }
        }
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
            $this->dropOptions($id);
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
                Cache::forget('loomnode:dst-uptime:' . $server->id);
                Notification::make()->title($running ? 'Restarting' : 'Starting')->body('The mods download now. This page catches up once the server has started.')->success()->send();
                $this->redirect(Console::getUrl(), navigate: true);
            });
    }

    private function canPower(): bool
    {
        return (bool) user()?->can($this->running() ? SubuserPermission::ControlRestart : SubuserPermission::ControlStart, $this->getRecord());
    }
}
