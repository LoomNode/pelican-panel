<?php

namespace App\Http\Requests\Api\Application\Users;

use App\Http\Requests\Api\Application\ApplicationApiRequest;
use App\Models\User;
use App\Services\Acl\Api\AdminAcl;

/**
 * Sign-in links and passkeys for a user: write access to users, the same permission that
 * can already set a user's password.
 */
class ManageUserSignInRequest extends ApplicationApiRequest
{
    protected ?string $resource = User::RESOURCE_NAME;

    protected int $permission = AdminAcl::WRITE;
}
