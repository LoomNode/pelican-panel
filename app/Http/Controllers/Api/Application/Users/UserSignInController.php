<?php

namespace App\Http\Controllers\Api\Application\Users;

use App\Http\Controllers\Api\Application\ApplicationApiController;
use App\Http\Requests\Api\Application\Users\ManageUserSignInRequest;
use App\Models\User;
use App\Services\Users\SignInLinkService;
use CBOR\Decoder;
use CBOR\Normalizable;
use Cose\Key\Ec2Key;
use Cose\Key\Key;
use Cose\Key\OkpKey;
use Cose\Key\RsaKey;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Webauthn\CredentialRecord;
use Webauthn\StringStream;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * For an integration that signs its customers in itself (a billing site with its own
 * login): send a customer into the panel without a second password, and let the passkey
 * they made on that site sign in here too. Both need PASSKEYS_RELYING_PARTY_ID set to a
 * domain the two sites share for the passkey part, and neither ever applies to an admin,
 * so an application key can't become an admin session through them.
 */
#[Group('User', weight: 1)]
class UserSignInController extends ApplicationApiController
{
    public function __construct(private SignInLinkService $links)
    {
        parent::__construct();
    }

    /**
     * Create a one-time sign-in link
     *
     * A link that signs this user in once, within a minute, and lands on the given
     * server (by its short id) or the server list. Refused for admins.
     */
    public function link(ManageUserSignInRequest $request, User $user): JsonResponse
    {
        $this->refuseAdmins($user);
        $data = $request->validate(['server' => 'nullable|string|max:191']);

        $to = '/';
        if (!empty($data['server'])) {
            $server = $user->directAccessibleServers()->where('servers.uuid_short', $data['server'])->first();
            if (!$server) {
                throw new UnprocessableEntityHttpException('That user has no access to that server.');
            }
            $to = '/server/' . $server->uuid_short;
        }

        return new JsonResponse($this->links->create($user, $to), 201);
    }

    /**
     * Add a passkey made elsewhere
     *
     * Stores a passkey the user registered on another site under the same relying party
     * (PASSKEYS_RELYING_PARTY_ID), so it signs in here too. Takes its public key (COSE,
     * base64url) and user handle (base64url). Adding one the user already has changes
     * nothing; one that belongs to another user is refused. Refused for admins.
     */
    public function storePasskey(ManageUserSignInRequest $request, User $user, string $credentialId): JsonResponse
    {
        $this->refuseAdmins($user);
        $data = $request->validate([
            'public_key' => 'required|string|max:2048',
            'user_handle' => 'required|string|max:128',
            'counter' => 'sometimes|integer|min:0',
            'name' => 'sometimes|string|max:191',
        ]);

        try {
            $rawId = Base64UrlSafe::decodeNoPadding($credentialId);
            $publicKey = Base64UrlSafe::decodeNoPadding($data['public_key']);
            $userHandle = Base64UrlSafe::decodeNoPadding($data['user_handle']);
        } catch (Throwable) {
            throw new UnprocessableEntityHttpException('The credential id, public key and user handle must be unpadded base64url.');
        }
        if ($rawId === '' || $userHandle === '' || !$this->isPublicKey($publicKey)) {
            throw new UnprocessableEntityHttpException('That is not a usable passkey public key.');
        }

        $model = Passkeys::passkeyModel();
        $existing = $model::query()->where('credential_id', $credentialId)->first();
        if ($existing) {
            if ((string) $existing->user_id !== (string) $user->id) {
                throw new ConflictHttpException('That passkey belongs to another user.');
            }

            return new JsonResponse(['created' => false], 200);
        }

        $record = CredentialRecord::create(
            $rawId, 'public-key', [], 'none', EmptyTrustPath::create(),
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            $publicKey, $userHandle, (int) ($data['counter'] ?? 0),
        );
        $user->passkeys()->create([
            'name' => $data['name'] ?? 'Passkey',
            'credential_id' => $credentialId,
            'credential' => json_decode(WebAuthn::toJson($record), true, flags: JSON_THROW_ON_ERROR),
        ]);

        return new JsonResponse(['created' => true], 201);
    }

    /**
     * Remove a passkey
     */
    public function deletePasskey(ManageUserSignInRequest $request, User $user, string $credentialId): Response
    {
        $this->refuseAdmins($user);
        $deleted = Passkeys::passkeyModel()::query()
            ->where('user_id', $user->id)->where('credential_id', $credentialId)->delete();

        return $deleted ? $this->returnNoContent() : new Response('', 404);
    }

    private function refuseAdmins(User $user): void
    {
        if ($user->isAdmin()) {
            throw new AccessDeniedHttpException('Not for admin accounts.');
        }
    }

    /** A COSE public key the assertion validator can use later: decoded now, not at sign-in. */
    private function isPublicKey(string $bytes): bool
    {
        try {
            $stream = new StringStream($bytes);
            $decoded = Decoder::create()->decode($stream);
            if (!$decoded instanceof Normalizable || !$stream->isEOF()) {
                return false;
            }
            $key = Key::createFromData($decoded->normalize());

            return $key instanceof Ec2Key || $key instanceof RsaKey || $key instanceof OkpKey;
        } catch (Throwable) {
            return false;
        }
    }
}
