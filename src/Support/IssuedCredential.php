<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A token as it exists for the one response that carries it: the row it was issued to, beside the
 * plaintext nothing stores and nothing reads back. Sanctum keeps only the token's SHA-256.
 *
 * The abilities travel with it rather than being read back off the principal, because the principal
 * a request arrives with was loaded before any of this ran: reporting from it can name an ability an
 * admin revoked a moment ago, and the machine would then believe it holds one its token does not.
 *
 * @template TOwner of Model
 */
final class IssuedCredential
{
    /**
     * @param  TOwner  $owner  The installation or agent session the token authenticates as.
     * @param  string  $plainTextToken  The bearer token, returned once and never again.
     * @param  list<string>  $abilities  What this token carries, as it was minted.
     * @param  int|null  $feedCursor  Where this principal reads the feed from, where that means
     *                                anything. Null for an installation credential, which reads no
     *                                feed. For a renewal it is the position the session has
     *                                ACKNOWLEDGED, restated rather than moved, which is what lets
     *                                a restarted process recover instead of guessing (#86).
     *                                For a session being started it is the id
     *                                of that session's own enrollment event, so everything at or
     *                                below it is history the session did not ask for and everything
     *                                above it happened after the session existed. A consequence
     *                                worth knowing: paging is `id > cursor`, so a session never sees
     *                                its own enrollment event, while every other session does.
     */
    public function __construct(
        public readonly Model $owner,
        public readonly string $plainTextToken,
        public readonly array $abilities,
        public readonly ?int $feedCursor = null
    ) {}
}
