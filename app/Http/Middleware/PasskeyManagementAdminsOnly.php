<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adding or removing passkeys is for admins (config/passkeys.php): customers' passkeys come
 * from the site they signed up on, through the Application API.
 */
class PasskeyManagementAdminsOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Passkeys are managed where you signed up.');

        return $next($request);
    }
}
