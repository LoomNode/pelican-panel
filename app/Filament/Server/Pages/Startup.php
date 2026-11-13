<?php

namespace App\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Filament\Components\Actions\PreviewStartupAction;
use App\Filament\Components\Forms\Fields\StartupVariable;
use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Traits\Filament\ManagesMinecraftMods;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;

class Startup extends ServerFormPage
{
    use ManagesMinecraftMods;

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

    // Minecraft (LoomNode, 2026-11-10): "Update modpack" sits here next to the Modpack settings; the mods
    // themselves have their own page (Mods). Both share ManagesMinecraftMods.

    /** @return array<Action> */
    protected function getDefaultHeaderActions(): array
    {
        return [$this->updatePackAction()];
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
