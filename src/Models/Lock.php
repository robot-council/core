<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RobotCouncil\Support\PresenceTimestamp;

/**
 * An advisory lease over a name.
 *
 * Advisory is the whole of it: nothing in the package enforces what a lock guards. It is a claim
 * one session makes that the fleet can see and that expires on its own, so a holder that stopped
 * answering cannot block everyone else past its lease.
 *
 * A row with a null `holder_id` is a free name that has been held before, and it is kept for the
 * fence it carries.
 *
 * Nothing mass-assigns this model. Every write is an `insertOrIgnore` or a conditional `update`
 * with a literal array, both of which bypass fillability, so there is no `#[Fillable]` to keep
 * honest.
 *
 * @property int $id
 * @property string $name
 * @property int|null $holder_id
 * @property int|null $previous_holder_id
 * @property int $fence
 * @property Carbon|null $acquired_at
 * @property Carbon|null $expires_at
 *
 * **Every instant on this row is on `Support\PresenceClock`, not the application's clock** (#149),
 * which means a host that is not on UTC sees its existing rows reinterpreted once, at the upgrade.
 * Rows written before carry wall-clock digits and are read as UTC afterwards, so **west of UTC
 * every held lease reads as already lapsed** and its name is takeable while its holder still
 * believes it owns it -- the very thing #149 removes, delivered once. `#51` made the same note for
 * presence and could call the direction safe; here it is not, so it is named rather than glossed.
 *
 * It needs no migration, for two reasons. `robot-council:doctor` already fails a host that is not
 * on UTC, so the population this can reach is one it is already telling to change; and a lease
 * cannot outlast `locks.max_ttl_seconds`, so the window closes on its own within minutes. A host
 * off UTC that cannot drain its fleet should expect one takeover window at the deploy.
 */
#[Table(name: 'robot_council_locks')]
final class Lock extends Model
{
    /**
     * The longest name a lock may carry.
     */
    public const int MAX_NAME = 191;

    /**
     * The attribute casts.
     *
     * Public rather than protected, because Pest's `strict()` preset forbids protected methods in
     * the package's namespaces, and PHP allows a subclass to widen a parent's visibility.
     *
     * @return array<string, string> The casts Eloquent applies to this model's attributes.
     */
    public function casts(): array
    {
        return [
            'holder_id' => 'integer',
            'previous_holder_id' => 'integer',
            'fence' => 'integer',
            // Not `datetime`: that hydrates in the application's timezone, while every write here
            // is on `Support\PresenceClock`, so a host off UTC would read back an instant wrong by
            // its offset. `Support\Locks` re-reads `expires_at` and compares it in PHP as well as
            // in SQL, and `Mcp\Tools\LockTool` and `Http\Controllers\LockController` derive
            // `expires_in` from it, so a relabelled value is wrong in exactly the paths that
            // decide or report whether a lease is still held (#149).
            //
            // **`acquired_at` is not cast for the `where` that reads it.** A binding never runs a
            // cast -- `Eloquent\Builder` applies none, and `Connection::prepareBindings()` formats
            // the Carbon it is handed -- so the renewal ceiling would behave identically without
            // this line. It is cast because the column is written on the presence clock and
            // anything that hydrates it later must not read it as the host's.
            //
            // **`created_at` and `updated_at` are deliberately NOT cast, and casting them is a
            // trap rather than the tidy extension it looks like.** `HasAttributes::getDates()`
            // returns both whenever a model uses timestamps, whatever its casts, and
            // `setAttribute()` tests `isDateAttribute()` in an `elseif` chain that runs *before*
            // the class-cast branch -- so an assignment is first flattened by `fromDateTime()`
            // into naive digits **in the Carbon's own zone**, and `PresenceTimestamp::set()` then
            // re-parses those digits in the **application's**. The two cancel only where the two
            // zones agree. `$lock->updated_at = PresenceClock::now()` would therefore store an
            // instant wrong by the host's offset, on the one column
            // `robot-council:prune-locks` measures retention on. Nothing hydrates either column
            // today, every write is a query-builder `insertOrIgnore` or `update` that bypasses
            // `setAttribute` entirely, and `Models\AgentSession` leaves its own two alone for the
            // same reason.
            'acquired_at' => PresenceTimestamp::class,
            'expires_at' => PresenceTimestamp::class,
        ];
    }

    /**
     * Whether the lease is still running.
     *
     * @param  Carbon  $now  The moment to judge it at.
     * @return bool True while somebody holds it and the lease has not lapsed.
     */
    public function isHeldAt(Carbon $now): bool
    {
        return $this->holder_id !== null
            && $this->expires_at instanceof Carbon
            && $this->expires_at->greaterThan($now);
    }
}
