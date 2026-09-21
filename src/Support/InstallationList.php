<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Support\Carbon;
use RobotCouncil\Models\AgentSession;
use RobotCouncil\Models\AgentSessionStatus;
use RobotCouncil\Models\Installation;

/**
 * Every installation, as an admin sees it.
 *
 * Held apart from `Installations` the way `TaskList` is held apart from `Tasks`: that class writes
 * and this one reads, so a change to how an installation is displayed cannot reach the paths that
 * revoke one.
 *
 * **Nothing here returns a credential, and there is no column it could come from.** Sanctum stores
 * a token as a hash, the plaintext exists only in the response that issued it, and a `device_code`
 * and its verifier belong to a different table this class never touches. That is what makes #77's
 * "no page shows a token" criterion a property of the reader rather than a habit of the view.
 */
final class InstallationList
{
    /**
     * The most installations one read returns, whatever a caller asks for.
     */
    public const int MAX_PAGE = 200;

    /**
     * @param  AgentLogins  $logins  The GitHub login behind a host user key.
     */
    public function __construct(private readonly AgentLogins $logins) {}

    /**
     * Every installation, newest first, with its sessions.
     *
     * @param  int  $limit  How many to return, clamped to `MAX_PAGE`.
     * @return list<array<string, mixed>> The installations.
     */
    public function everything(int $limit): array
    {
        // Eager-loaded rather than read per row. `Model::preventLazyLoading()` raises on a query
        // that hydrated more than one row, so a host running strict mode would take a
        // `LazyLoadingViolationException` off the first page holding two installations.
        $installations = Installation::query()
            ->with('sessions')
            ->orderByDesc('id')
            ->limit(max(1, min($limit, self::MAX_PAGE)))
            ->get();

        $logins = $this->logins->forUsers($installations->pluck('user_id')->all());

        $now = Carbon::now();

        return array_values($installations->map(fn (Installation $installation): array => [
            'id' => $installation->id,
            'github_login' => $logins[$installation->user_id] ?? null,
            'harness' => $installation->harness,
            'machine_label' => $installation->machine_label,

            // Through the model's own accessor, which drops anything the fixed list no longer
            // holds. A retired ability still sitting in the stored row must not be offered back as
            // a control that revokes it, because the guards no longer check it either.
            'abilities' => $installation->abilities(),

            // Both halves of `isUsable()`, separately. "Revoked" and "expired" are the same to a
            // guard and different to an admin: one is a decision somebody made and the other is
            // the clock, and only the first is worth asking about.
            'revoked' => $installation->revoked_at !== null,
            'expired' => $installation->expires_at->isBefore($now),
            'sessions' => $this->sessionsOf($installation),
        ])->all());
    }

    /**
     * One installation's sessions, oldest first.
     *
     * @param  Installation  $installation  The installation to read.
     * @return list<array<string, mixed>> Its sessions.
     */
    private function sessionsOf(Installation $installation): array
    {
        return array_values($installation->sessions
            ->sortBy('id')
            ->map(static fn (AgentSession $session): array => [
                'id' => $session->id,

                // Read from the row rather than derived from the contact time, for the reason #24
                // records: the row is the decision every conditional update in the package makes.
                'status' => $session->status->value,

                // Decided here rather than by the view comparing a string. `gone` is the one
                // terminal status, and a template that spelled it out would keep rendering a
                // control that does nothing if the enum ever gained another.
                'revocable' => $session->status !== AgentSessionStatus::Gone,

                // Agent-supplied, charset-limited at the edge by `ProjectId`, and escaped by the
                // view like every other string that reached this package from a machine
                'project_id' => $session->project_id,
            ])->all());
    }
}
