<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * One-time sign-in links, for an integration that signs its customers in itself (a billing
 * site with its own login) and wants to send them into the panel without a second password.
 *
 * A link is a random token, kept only as a hash in the cache: it works once, for a minute,
 * for one user, and only for a user who isn't an admin, so an application key can never
 * turn into an admin session this way.
 */
class SignInLinkService
{
    public const TTL_SECONDS = 60;

    private const PREFIX = 'sign-in-link:';

    /**
     * @return array{url: string, expires_at: string}
     */
    public function create(User $user, string $redirectTo = '/'): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expires = Carbon::now()->addSeconds(self::TTL_SECONDS);

        Cache::put(self::PREFIX . hash('sha256', $token), [
            'user' => $user->id,
            'to' => $redirectTo,
        ], $expires);

        // From APP_URL, not route(): the integration calls the API on the panel's private
        // address, and the link is for the customer's browser.
        return [
            'url' => rtrim((string) config('app.url'), '/') . '/auth/sign-in-link/' . $token,
            'expires_at' => $expires->toAtomString(),
        ];
    }

    /**
     * The link's user and where to send them, or null if the token is unknown, expired or
     * already used. Claiming is an atomic add, so two requests racing with the same token
     * can't both get in.
     *
     * @return array{user: User, to: string}|null
     */
    public function consume(string $token): ?array
    {
        $key = self::PREFIX . hash('sha256', $token);
        $data = Cache::get($key);
        if (!is_array($data) || !Cache::add($key . ':used', true, self::TTL_SECONDS * 2)) {
            return null;
        }
        Cache::forget($key);

        $user = User::query()->find($data['user'] ?? null);
        if (!$user || $user->isAdmin()) {
            return null;
        }

        return ['user' => $user, 'to' => (string) ($data['to'] ?? '/')];
    }
}
