<?php

namespace App\Models;

use App\Contracts\Validatable;
use App\Traits\HasValidation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $egg_id
 * @property string $name
 * @property string $description
 * @property string $env_variable
 * @property string $default_value
 * @property bool $user_viewable
 * @property bool $user_editable
 * @property string[] $rules
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property int|null $sort
 * @property-read Egg|null $egg
 * @property-read bool $required
 * @property-read Collection<int, ServerVariable> $serverVariable
 * @property-read int|null $server_variable_count
 *
 * @method static \Database\Factories\EggVariableFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereDefaultValue($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereEggId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereEnvVariable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereRules($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereSort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereUserEditable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EggVariable whereUserViewable($value)
 *
 * @property string|null $server_value This variable is only present on the object if you've loaded this model using the server relationship.
 */
class EggVariable extends Model implements Validatable
{
    use HasFactory;
    use HasValidation { getRules as getValidationRules; }

    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'egg_variable';

    /**
     * Reserved environment variable names.
     */
    public const RESERVED_ENV_NAMES = ['P_SERVER_UUID', 'P_SERVER_ALLOCATION_LIMIT', 'SERVER_MEMORY', 'SERVER_IP', 'SERVER_PORT', 'ENV', 'HOME', 'USER', 'STARTUP', 'MODIFIED_STARTUP', 'SERVER_UUID', 'UUID', 'INTERNAL_IP', 'HOSTNAME', 'TERM', 'LANG', 'PWD', 'TZ', 'TIMEZONE'];

    /**
     * Fields that are not mass assignable.
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /** @var array<string, string[]> */
    public static array $validationRules = [
        'egg_id' => ['exists:eggs,id'],
        'sort' => ['nullable'],
        'name' => ['required', 'string', 'between:1,255'],
        'description' => ['string'],
        'default_value' => ['string'],
        'user_viewable' => ['boolean'],
        'user_editable' => ['boolean'],
        'rules' => ['array'],
        'rules.*' => ['string'],
    ];

    /**
     * Implement language verification by overriding Eloquence's gather rules function.
     *
     * @return array<string, string[]>
     */
    public static function getRules(): array
    {
        $rules = self::getValidationRules();

        $rules['env_variable'] = ['required', 'alphaDash', 'between:1,255', 'notIn:' . implode(',', EggVariable::RESERVED_ENV_NAMES)];

        return $rules;
    }

    protected $attributes = [
        'user_editable' => 0,
        'user_viewable' => 0,
        'rules' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'egg_id' => 'integer',
            'user_viewable' => 'bool',
            'user_editable' => 'bool',
            'rules' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRequiredAttribute(): bool
    {
        return in_array('required', $this->rules);
    }

    /**
     * A variable that holds a secret: a password, token or key (STEAM_ACC and GSLT are GSL tokens).
     * PASS counts only as a whole word part (RCON_PASS, SRCDS_BETAPASS), so VH_PASSIVEMOBS doesn't;
     * PW/PWD likewise (ADMIN_PW). KEY counts at the end of a word part (API_KEY, LICENSEKEY).
     */
    public static function isSecret(string $envVariable): bool
    {
        return (bool) preg_match('/PASSWORD|PASSWD|PASSPHRASE|(^|_)[A-Z]*PASS($|_)|(^|_)PWD?($|_)|SECRET|TOKEN|GSLT|WEBHOOK|KEY($|_)|^STEAM_ACC$/i', $envVariable);
    }

    /**
     * A value as the activity log keeps it. The log is shown to everyone who can see the
     * server's activity and sent to webhooks, so a secret is kept only as set or empty
     * (LoomNode, 2026-10-22).
     */
    public static function forActivity(string $envVariable, ?string $value): ?string
    {
        return static::isSecret($envVariable) && $value !== null && $value !== '' ? '******' : $value;
    }

    /**
     * A name "Group · Setting" puts the setting under a fold-out Group on Game Settings, shown
     * as just "Setting". A name without the separator has no group (LoomNode, 2026-11-01).
     */
    public const GROUP_SEPARATOR = ' · ';

    public function groupName(): ?string
    {
        return str_contains($this->name, self::GROUP_SEPARATOR) ? strstr($this->name, self::GROUP_SEPARATOR, true) : null;
    }

    public function shortName(): string
    {
        $group = $this->groupName();

        return $group === null ? $this->name : substr($this->name, strlen($group) + strlen(self::GROUP_SEPARATOR));
    }

    public function egg(): HasOne
    {
        return $this->hasOne(Egg::class);
    }

    /**
     * Return server variables associated with this variable.
     */
    public function serverVariable(): HasMany
    {
        return $this->hasMany(ServerVariable::class, 'variable_id');
    }
}
