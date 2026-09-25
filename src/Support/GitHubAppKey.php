<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use OpenSSLAsymmetricKey;
use SensitiveParameter;

/**
 * The GitHub App's identity: its id, its private key, and the short-lived JWT the key signs (#383).
 *
 * **Signed with `ext-openssl` rather than a JWT library.** An RS256 JWT is two base64url JSON
 * objects and one `openssl_sign()`; a library would be a dependency every host inherits for a
 * dozen lines. `ext-openssl` is already a requirement of `laravel/framework`, so it adds nothing.
 *
 * **Nothing here makes a request**, so `Support\GitHubApp` stays the one file that talks to GitHub,
 * and doctor can ask whether the key parses without asking GitHub anything.
 *
 * **The key never leaves this class except as a signature.** Every parameter that carries it is
 * `#[SensitiveParameter]`, so a stack trace through here prints `Object(SensitiveParameterValue)`
 * in its place, and no failure message names what was configured -- only that it did not parse.
 */
final class GitHubAppKey
{
    /**
     * How far before now a JWT says it was issued, in seconds.
     *
     * GitHub refuses a JWT issued in its future, so a server whose clock runs ahead of GitHub's by
     * a few seconds would otherwise fail every request. GitHub's own guidance is sixty.
     */
    public const int ISSUED_BEFORE_SECONDS = 60;

    /**
     * How long after now a JWT expires, in seconds. GitHub refuses one more than ten minutes out;
     * nine leaves a minute for the same clock drift.
     */
    public const int EXPIRES_AFTER_SECONDS = 540;

    /**
     * The shape of an App id: GitHub's numeric id, at most what a bigint holds.
     */
    public const string APP_ID = '/^[1-9][0-9]{0,17}$/D';

    /**
     * @param  Repository  $config  The host's configuration, read on each call so a changed key is
     *                              used at once.
     */
    public function __construct(private readonly Repository $config) {}

    /**
     * Whether an App id or a key is configured at all.
     *
     * True when EITHER is set, not both, so a half-configured App reads as configured and broken
     * rather than as a deliberate choice to fetch nothing.
     *
     * @return bool Whether anything is configured.
     */
    public function configured(): bool
    {
        return $this->configuredId() || $this->configuredKey();
    }

    /**
     * Whether an App id is configured, whatever its shape.
     *
     * @return bool Whether one is set.
     */
    public function configuredId(): bool
    {
        $id = $this->config->get('robot-council.github.app.id');

        return (\is_string($id) && trim($id) !== '') || \is_int($id);
    }

    /**
     * Whether a private key is configured, whether or not it parses.
     *
     * @return bool Whether one is set.
     */
    public function configuredKey(): bool
    {
        $key = $this->config->get('robot-council.github.app.private_key');

        return \is_string($key) && trim($key) !== '';
    }

    /**
     * The App id, when it is one.
     *
     * @return string|null The id, or null when it is unset or not a numeric id.
     */
    public function appId(): ?string
    {
        $id = $this->config->get('robot-council.github.app.id');

        if (\is_int($id)) {
            $id = (string) $id;
        }

        if (! \is_string($id) || preg_match(self::APP_ID, trim($id)) !== 1) {
            return null;
        }

        return trim($id);
    }

    /**
     * The private key, parsed.
     *
     * @return OpenSSLAsymmetricKey|null The key, or null when it is unset, does not parse, or is
     *                                   not an RSA key, which RS256 needs.
     */
    public function privateKey(): ?OpenSSLAsymmetricKey
    {
        $configured = $this->config->get('robot-council.github.app.private_key');

        if (! \is_string($configured)) {
            return null;
        }

        $pem = self::pem($configured);

        if ($pem === null) {
            return null;
        }

        $key = openssl_pkey_get_private($pem);

        // OpenSSL queues an error for a key it could not read, and the next unrelated OpenSSL call
        // anywhere in the process would report it. Drained so this failure stays this one's.
        self::drainOpenSslErrors();

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        return \is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA ? $key : null;
    }

    /**
     * A JWT for the App, valid for a few minutes around the moment given.
     *
     * @param  DateTimeInterface  $at  The moment it is signed at.
     * @return string|null The JWT, or null when the id or the key is unusable.
     */
    public function jwt(DateTimeInterface $at): ?string
    {
        $id = $this->appId();
        $key = $this->privateKey();

        if ($id === null || ! $key instanceof OpenSSLAsymmetricKey) {
            return null;
        }

        $now = $at->getTimestamp();

        // Build the two JSON halves. `iss` is a number, because the id is one and GitHub's own
        // examples send it as one
        $header = self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = self::base64Url((string) json_encode([
            'iat' => $now - self::ISSUED_BEFORE_SECONDS,
            'exp' => $now + self::EXPIRES_AFTER_SECONDS,
            'iss' => (int) $id,
        ]));

        // Sign both with the key, SHA-256 over RSA, which is what RS256 names
        $signature = '';
        $signed = openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256);

        self::drainOpenSslErrors();

        if (! $signed || ! \is_string($signature)) {
            return null;
        }

        return $header.'.'.$payload.'.'.self::base64Url($signature);
    }

    /**
     * The PEM a configured value holds, in either of the two forms accepted.
     *
     * **Base64 of the whole PEM is the documented form**, because most environment editors mangle a
     * multi-line value. **A PEM pasted whole is accepted too**, since a secrets manager that keeps
     * newlines has no reason to encode it, and a `\n` written as two characters -- what a one-line
     * `.env` entry holds -- is read as the newline it stands for. The two cannot be confused: `-` is
     * not in the base64 alphabet, so a value beginning `-----BEGIN` is never base64.
     *
     * @param  string  $value  What was configured.
     * @return string|null The PEM, or null when the value is neither form.
     */
    public static function pem(#[SensitiveParameter] string $value): ?string
    {
        $value = trim($value);

        // A PEM pasted whole, possibly with its newlines written as `\n`
        if (str_starts_with($value, '-----BEGIN')) {
            return trim(str_replace('\n', "\n", $value));
        }

        // Base64 of a PEM, with any line breaks the encoder added removed first
        $decoded = base64_decode((string) preg_replace('/\s+/', '', $value), true);

        if (! \is_string($decoded) || ! str_starts_with(trim($decoded), '-----BEGIN')) {
            return null;
        }

        return trim($decoded);
    }

    /**
     * Base64url without padding, as a JWT encodes each part.
     *
     * @param  string  $bytes  What to encode.
     * @return string The encoding.
     */
    private static function base64Url(#[SensitiveParameter] string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Empty OpenSSL's error queue.
     */
    private static function drainOpenSslErrors(): void
    {
        // Read until empty. The queue is bounded by OpenSSL, so this ends.
        while (openssl_error_string() !== false) {
        }
    }
}
