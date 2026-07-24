<?php

namespace App\Services;

use RuntimeException;

/**
 * Derives the identifier the agent state store actually uses from the handle a
 * caller supplies.
 *
 * The consult endpoint used the client's `session_key` verbatim as the agent
 * session id, so the request body chose which stored conversation to load. That
 * made the handle a global address: a caller could name any session — including
 * one belonging to another consultation — and read back its retrieved evidence.
 *
 * The handle is now one input to a keyed derivation, never the address itself.
 * Two properties follow:
 *
 *  - the stored id cannot be chosen, constructed, or predicted from the request,
 *    because it depends on a server-held secret; and
 *  - it is scoped to the credential that authenticated the request, so sessions
 *    under different API keys can never collide or be read across.
 *
 * Limitation worth stating plainly: this binds a session to the *credential*,
 * which is the only caller identity Laravel has. While one API key is shared by
 * all OpenWebUI users, holders of that key remain inside a single scope, so this
 * does not separate one end user from another. That needs per-user credentials
 * (or server-minted session tokens the client stores and returns) — a change to
 * the auth model, not to this derivation.
 */
final class ConsultSessionIdentityService
{
    /**
     * Domain separator: keeps these ids from colliding with any other keyed
     * derivation that might later use the same secret.
     */
    private const DOMAIN = 'vascular_consult.session.v1';

    /**
     * @param  string  $clientHandle  Opaque conversation handle from the request body.
     * @param  string  $callerFingerprint  Credential fingerprint set by ValidateApiKey.
     */
    public function resolve(string $clientHandle, string $callerFingerprint): string
    {
        if (trim($clientHandle) === '') {
            throw new RuntimeException('A session handle is required to resolve a consult session.');
        }

        if (trim($callerFingerprint) === '') {
            // Only reachable if the endpoint is exposed without ValidateApiKey.
            // Failing closed beats silently sharing one unscoped namespace.
            throw new RuntimeException('Consult sessions require an authenticated caller.');
        }

        // NUL separators: no combination of handle and caller can be rearranged
        // into another valid pair, since neither value may contain a NUL byte.
        return hash_hmac(
            'sha256',
            implode("\0", [self::DOMAIN, $callerFingerprint, $clientHandle]),
            $this->secret(),
        );
    }

    private function secret(): string
    {
        $secret = (string) config('app.key');

        if ($secret === '') {
            throw new RuntimeException(
                'APP_KEY must be set: consult session identifiers are derived from it.'
            );
        }

        return $secret;
    }
}
