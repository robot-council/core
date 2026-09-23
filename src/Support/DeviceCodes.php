<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RobotCouncil\Access\Ability;
use RobotCouncil\Access\Tokens;
use RobotCouncil\Models\DeviceCode;
use RobotCouncil\Support\Contracts\DrawsUserCodes;
use RuntimeException;

/**
 * The device-code half of enrollment: issuing a code, finding one by the code a developer typed,
 * recording a decision, and exchanging an approved code for an installation.
 *
 * Every state change here is a single conditional update whose WHERE clause carries the whole
 * precondition, so the database decides the race rather than a read followed by a write. A second
 * decision and a second exchange each match no rows, which is how one approval can only ever
 * produce one installation.
 */
final class DeviceCodes
{
    /**
     * The longest requested IP the package stores.
     *
     * 45 characters, which is what an IPv6 address carrying an embedded IPv4 needs.
     */
    public const int MAX_REQUESTED_IP = 45;

    /**
     * How many times to redraw a user code that collides with a live one before giving up, so a
     * saturated alphabet fails loudly instead of looping.
     */
    private const int USER_CODE_ATTEMPTS = 12;

    /**
     * @param  Credentials  $credentials  The configured lifetimes.
     * @param  DrawsUserCodes  $userCodes  What draws the code a developer reads and types.
     */
    public function __construct(
        private readonly Credentials $credentials,
        private readonly DrawsUserCodes $userCodes
    ) {}

    /**
     * The SHA-256 of a value, as the table stores it.
     *
     * @param  string  $value  The secret to hash.
     * @return string The hash, in lowercase hexadecimal.
     */
    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Reduce what a developer typed to the canonical user code.
     *
     * The alphabet is letters only, so spaces, the dash the page prints, and a lowercase reading
     * of the code all normalize away rather than failing the lookup.
     *
     * @param  string  $input  What the developer typed.
     * @return string The canonical form, which may be any length.
     */
    public static function normalizeUserCode(string $input): string
    {
        // The cast cannot be killed by a test and is not dead. `preg_replace()` is declared
        // `string|null` because it returns null on a PCRE failure, and `strtoupper()` takes a
        // string -- so removing the cast fails `composer analyse` with `Parameter #1 $string of
        // function strtoupper expects string`, measured. No input reaches the null: this pattern is
        // a single negated character class with no backtracking to exhaust.
        // @pest-mutate-ignore: RemoveStringCast
        return strtoupper((string) preg_replace('/[^A-Za-z]/', '', $input));
    }

    /**
     * Record an enrollment request and return the code the helper polls with.
     *
     * @param  array<array-key, mixed>  $requestedAbilities  The abilities asked for. Narrowed
     *                                                       here to the requestable ones, so a
     *                                                       caller need not have validated it.
     * @param  string  $harness  The harness the requester claims to be.
     * @param  string  $machineLabel  The machine label the requester claims.
     * @param  string  $codeChallenge  The SHA-256 of the verifier the helper holds.
     * @param  string|null  $requestedIp  The address the request arrived from.
     * @return IssuedDeviceCode The stored row and the plaintext device code.
     *
     * @throws InvalidArgumentException When the harness, the machine label, or the requested IP is
     *                                  outside what the package stores.
     * @throws RuntimeException When no free user code was found in the allowed attempts.
     */
    public function issue(
        array $requestedAbilities,
        string $harness,
        string $machineLabel,
        string $codeChallenge,
        ?string $requestedIp
    ): IssuedDeviceCode {
        // Bounded here as well as in `DeviceCodeController`, because this is a public method on an
        // injectable service and both values reach other developers' agents: `AgentSessions::start()`
        // writes them into the change feed, which every session reads. `CLAUDE.md` records the
        // measurement that makes the length half real -- a 34-character write into this table's
        // `varchar(32)` harness passed every local SQLite run and failed only CI's `postgres` job.
        MachineIdentity::ensure($harness, $machineLabel);

        // **Bounded here for the same reason the three below are, and it was the one left out.**
        // `DeviceCodeController` validates `requested_abilities.*` against the requestable list,
        // and a rule in a controller protects the endpoint and nothing else -- this is a public
        // method on an injectable service, so a host, a seeder, or a test reaches it directly.
        // Dropping rather than throwing, because that is what `Access\Ability::granted()` already
        // does with the same value at approval time, and a store that refused what the approval
        // would silently drop would disagree with it (#170).
        $requestedAbilities = Ability::requestableFrom($requestedAbilities);

        // The column is `varchar(45)`, which is what an IPv6 address with an embedded IPv4 needs.
        // The endpoint passes `$request->ip()` and a host may pass anything.
        if ($requestedIp !== null && mb_strlen($requestedIp) > self::MAX_REQUESTED_IP) {
            throw new InvalidArgumentException(sprintf(
                'A requested IP is limited to %d characters, and this one is %d.',
                self::MAX_REQUESTED_IP,
                mb_strlen($requestedIp)
            ));
        }

        // 256 bits, well past the 128 the flow calls for, and stored only as its hash
        $deviceCode = bin2hex(random_bytes(32));

        $record = DeviceCode::query()->create([
            'device_code_hash' => self::hash($deviceCode),

            // The hash of the challenge, which is itself the hash of the verifier. The row
            // therefore holds nothing that can be replayed even against the challenge.
            'challenge_hash' => self::hash($codeChallenge),
            'user_code' => $this->freshUserCode(),
            'requested_abilities' => $requestedAbilities,
            'harness' => $harness,
            'machine_label' => $machineLabel,
            'requested_ip' => $requestedIp,
            'expires_at' => $this->credentials->deviceCodeExpiry(),
        ]);

        return new IssuedDeviceCode($record, $deviceCode);
    }

    /**
     * Find the live request a developer's typed code refers to.
     *
     * @param  string  $input  What the developer typed.
     * @return DeviceCode|null The request, or null when no live code matches.
     */
    public function findByUserCode(string $input): ?DeviceCode
    {
        $userCode = self::normalizeUserCode($input);

        if (\strlen($userCode) !== UserCodes::LENGTH) {
            return null;
        }

        // Newest first. Drawing checks for a live collision before inserting, which is a read
        // followed by a write and so not atomic: two requests drawing the same code in the same
        // instant both succeed, at a probability of one in twenty to the eighth per pair. The
        // column carries an index rather than a unique constraint, because a code may be redrawn
        // once an earlier one has expired and been pruned.
        return $this->live()->where('user_code', $userCode)->latest('id')->first();
    }

    /**
     * Approve a request, granting the abilities the server computed.
     *
     * @param  DeviceCode  $code  The request to approve.
     * @param  string  $decidedBy  The approving developer's key in the host's users table.
     * @param  array<array-key, mixed>  $granted  The abilities to grant. Narrowed here to the
     *                                            requestable ones, so what is recorded is what
     *                                            the device-code flow could have produced.
     * @return bool True when this call was the one that decided it.
     */
    public function approve(DeviceCode $code, string $decidedBy, array $granted): bool
    {
        return $this->undecided($code)->update([
            'approved_at' => Carbon::now(),
            'decided_by' => $decidedBy,

            // Encoded here because a query-builder update writes its values straight through,
            // without the model's casts. Narrowed for the reason `issue()` records: the only
            // in-package caller already passes `Ability::granted()`'s answer, so this changes
            // nothing for the enrollment flow and stops a direct caller writing an ability the
            // device-code flow can never produce -- `coordinator:direct` above all, which
            // `Models\Installation::abilities()` would then honor (#170)
            'granted_abilities' => json_encode(Ability::requestableFrom($granted), JSON_THROW_ON_ERROR),
        ]) === 1;
    }

    /**
     * Deny a request.
     *
     * @param  DeviceCode  $code  The request to deny.
     * @param  string  $decidedBy  The denying developer's key in the host's users table.
     * @return bool True when this call was the one that decided it.
     */
    public function deny(DeviceCode $code, string $decidedBy): bool
    {
        return $this->undecided($code)->update([
            'denied_at' => Carbon::now(),
            'decided_by' => $decidedBy,
        ]) === 1;
    }

    /**
     * Claim an approved request for exchange, or say why it cannot be claimed.
     *
     * The conditional update is the first query this method runs, so a concurrent exchange that
     * starts after it finds the row already consumed. Only then is the row read, and only to
     * report a reason.
     *
     * @param  string  $deviceCode  The plaintext device code the helper is polling with.
     * @param  string  $verifier  The plaintext verifier the helper holds.
     * @return DeviceCode|DeviceCodeError The claimed request, or why it was refused.
     */
    public function consume(string $deviceCode, string $verifier): DeviceCode|DeviceCodeError
    {
        $codeHash = self::hash($deviceCode);
        $challengeHash = self::hash(self::hash($verifier));

        $claimed = DeviceCode::query()
            ->where('device_code_hash', $codeHash)
            ->where('challenge_hash', $challengeHash)
            ->whereNotNull('approved_at')
            ->whereNull('denied_at')
            ->whereNull('consumed_at')
            ->where('expires_at', '>', PresenceClock::now())
            ->update(['consumed_at' => Carbon::now()]);

        if ($claimed === 1) {
            return DeviceCode::query()->where('device_code_hash', $codeHash)->sole();
        }

        $record = DeviceCode::query()->where('device_code_hash', $codeHash)->first();

        // Whoever does not hold the verifier is told the same thing in every state, so a stolen
        // device code cannot be polled to watch for a developer's approval
        if ($record === null || ! hash_equals($record->challenge_hash, $challengeHash)) {
            return DeviceCodeError::InvalidGrant;
        }

        if ($record->denied_at !== null) {
            return DeviceCodeError::AccessDenied;
        }

        if ($record->consumed_at !== null || $record->expires_at->isPast()) {
            return DeviceCodeError::ExpiredToken;
        }

        return DeviceCodeError::AuthorizationPending;
    }

    /**
     * Delete the requests that have expired.
     *
     * @return int How many rows were deleted.
     */
    public function prune(): int
    {
        $deleted = DeviceCode::query()->where('expires_at', '<=', PresenceClock::now())->delete();

        // Through `Access\Tokens::deleted()` rather than repeating its body. This was the one site
        // that re-implemented it inline, and the copy carried its own mutants and its own comment
        // citing the original as the authority -- one expression with two treatments, which is how
        // the two drift. `Support\Installations` and `Support\SessionPresence` already call it.
        return Tokens::deleted($deleted);
    }

    /**
     * The requests that can still be decided or exchanged.
     *
     * @return Builder<DeviceCode> A query over the unexpired requests.
     */
    private function live(): Builder
    {
        return DeviceCode::query()->where('expires_at', '>', PresenceClock::now());
    }

    /**
     * A query matching one request only while no decision has been made on it.
     *
     * @param  DeviceCode  $code  The request to narrow to.
     * @return Builder<DeviceCode> A query that matches nothing once the request is decided.
     */
    private function undecided(DeviceCode $code): Builder
    {
        return $this->live()
            ->whereKey($code->getKey())
            ->whereNull('approved_at')
            ->whereNull('denied_at')
            ->whereNull('consumed_at');
    }

    /**
     * Draw a user code that no live request already holds.
     *
     * @return string The canonical eight-character code.
     *
     * @throws RuntimeException When every attempt collided.
     */
    private function freshUserCode(): string
    {
        // **A mutation run on this class reports `1 timeout`, and this loop is where it comes from.**
        // `PostIncrementToPostDecrement` turns `$attempt++` into `$attempt--`, so the counter falls
        // away from the bound forever and every iteration issues a query. A mutant that never
        // returns cannot fail an assertion, so only the clock observes it -- and the plugin scores a
        // timeout as a kill, `(tested + timedOut) / total`, which is why the count beside the score
        // has to be read rather than the percentage.
        //
        // Deliberately NOT suppressed with a per-line ignore marker. It is a real behavior change
        // rather than an equivalent, and hiding it would hide that this loop has no bound other
        // than the counter moving the right way. (The marker is described rather than spelled,
        // because the plugin reads the token wherever it appears, comments about it included.)
        for ($attempt = 0; $attempt < self::USER_CODE_ATTEMPTS; $attempt++) {
            $candidate = $this->userCodes->draw();

            if (! $this->live()->where('user_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(sprintf('robot-council could not draw an unused user code in %d attempts.', self::USER_CODE_ATTEMPTS));
    }
}
