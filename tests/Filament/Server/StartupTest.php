<?php

use App\Enums\SubuserPermission;
use App\Filament\Server\Pages\Startup;
use App\Models\EggVariable;
use App\Models\ServerVariable;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(fn () => Filament::setCurrentPanel('server'));
afterEach(fn () => Filament::setCurrentPanel(null));

it('forbids updating a startup variable that belongs to another server', function () {
    [$user, $server] = generateTestAccount();
    $foreign = createServerModel();
    $foreign->ensureVariablesExist();

    /** @var ServerVariable $foreignVariable */
    $foreignVariable = $foreign->serverVariables()->firstOrFail();
    $foreignVariable->update(['variable_value' => 'secret-value']);

    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)
        ->call('update', 'changed', $foreignVariable->id)
        ->assertForbidden();

    expect($foreignVariable->refresh()->variable_value)->toBe('secret-value');
});

it('forbids updating a startup variable the user cannot view', function () {
    [$user, $server] = generateTestAccount();
    $server->ensureVariablesExist();

    /** @var ServerVariable $variable */
    $variable = $server->serverVariables()->firstOrFail();
    $variable->variable->update(['user_viewable' => false, 'user_editable' => true]);
    $variable->update(['variable_value' => 'hidden']);

    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)
        ->call('update', 'changed', $variable->id)
        ->assertForbidden();

    expect($variable->refresh()->variable_value)->toBe('hidden');
});

it('updates a startup variable on the tenant server', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::StartupRead, SubuserPermission::StartupUpdate]);
    $server->ensureVariablesExist();

    /** @var ServerVariable $variable */
    $variable = $server->serverVariables()->firstOrFail();
    $variable->variable->update(['user_viewable' => true, 'user_editable' => true, 'rules' => ['string']]);

    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)
        ->call('update', 'changed', $variable->id)
        ->assertSuccessful();

    expect($variable->refresh()->variable_value)->toBe('changed');
});

it('splits a grouped variable name into its group and short name', function () {
    $variable = new EggVariable(['name' => 'World · Day Type']);
    expect($variable->groupName())->toBe('World')
        ->and($variable->shortName())->toBe('Day Type');

    $plain = new EggVariable(['name' => 'Server Name']);
    expect($plain->groupName())->toBeNull()
        ->and($plain->shortName())->toBe('Server Name');
});

it('shows one section per group, labels without the group, and saves a setting in a closed group', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::StartupRead, SubuserPermission::StartupUpdate]);
    $server->egg->variables()->delete();
    $make = fn (string $name, string $env, int $sort) => $server->egg->variables()->create([
        'name' => $name, 'description' => '', 'env_variable' => $env, 'default_value' => 'x',
        'user_viewable' => true, 'user_editable' => true, 'rules' => ['string'], 'sort' => $sort,
    ]);
    $make('Basics · Server Name', 'T_NAME', 1);
    $make('World · Day Type', 'T_DAY', 80);
    $make('Basics · Password', 'T_PASS', 2);
    $server->refresh()->ensureVariablesExist();

    $this->actingAs($user);
    Filament::setTenant($server);

    $html = livewire(Startup::class)->assertSuccessful()->html();
    expect($html)->toContain('Basics')->toContain('World')->toContain('Day Type')
        ->not->toContain('World · Day Type')
        ->and(strpos($html, 'Password'))->toBeLessThan(strpos($html, 'Day Type'));

    /** @var ServerVariable $day */
    $day = $server->serverVariables()->whereHas('variable', fn ($q) => $q->where('env_variable', 'T_DAY'))->firstOrFail();
    livewire(Startup::class)->call('update', 'onlynight', $day->id)->assertSuccessful();

    expect($day->refresh()->variable_value)->toBe('onlynight');
});

it('keeps the single Variables section for an egg without groups', function () {
    [$user, $server] = generateTestAccount([SubuserPermission::StartupRead]);
    $server->ensureVariablesExist();
    $server->egg->variables()->update(['user_viewable' => true]);

    $this->actingAs($user);
    Filament::setTenant($server);

    livewire(Startup::class)->assertSuccessful()->assertSee(trans('server/startup.variables'))->assertDontSee('server_variables_0');
});
