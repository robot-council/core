<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use Illuminate\Validation\ValidationException;
use RobotCouncil\Models\AgentSession;

/**
 * Who a directive expects to act, resolved from the ids a poster supplied.
 *
 * A directive reaches every agent whether or not it names anybody, and naming does not change that
 * (#135). What it changes is that the event records which sessions are expected to act, so an
 * instruction meant for one agent stops asking every idle agent to decide for itself whether it is
 * the addressee -- which is the judgement the feed's visibility rule exists to avoid asking of a
 * process that may have shell access.
 *
 * **Every id is resolved to a row, and the row's own key is what gets recorded.** Echoing the input
 * back would make the event a record of what the poster claimed rather than of what the server
 * found, and the two differ the moment an id is a string, a float, or a duplicate. Reading the key
 * off the model is also what makes the refusal below meaningful: an id that resolved to nothing
 * never reaches the recorded list, so there is no path where an unknown id is written.
 *
 * Shared by the HTTP controller and the MCP tool, because two copies of a validation rule are two
 * places for it to drift, and this one decides what a security-relevant field means.
 */
final class DirectiveTargets
{
    /**
     * How many sessions one directive may name.
     *
     * Policy rather than capacity. The column is JSON and holds far more, but a directive naming
     * more agents than this is a directive for the fleet, which is what omitting the field already
     * says. The bound also keeps a single row from growing without limit, since nothing else caps
     * how many sessions a fleet has.
     *
     * A constant's declaration is never an executed line, so no test is selected to cover it and an
     * off-by-one mutant here is reported as uncovered rather than run. The value is pinned by a test
     * that also checks the README states the same number, and the boundary either side of it has
     * its own test.
     */
    // @pest-mutate-ignore: IncrementInteger, DecrementInteger
    public const int MAX = 50;

    /**
     * Resolve whatever a poster supplied into the session keys to record.
     *
     * **Takes `mixed` and holds its own bounds**, rather than trusting the two validators in front
     * of it. This is a public method on a `final` class a host can resolve and call, and what it
     * decides is who a directive says must act -- so the rule that "a bound a validation rule
     * states is not a bound the package holds" applies here as much as to any store. An absent or
     * empty argument is an empty list, which is what a directive for the whole fleet looks like.
     *
     * @param  mixed  $supplied  The ids the poster named.
     * @return list<int> The resolved session keys, deduplicated and ordered.
     *
     * @throws ValidationException When too many are named, when any is not a whole number, or when
     *                             any names no session or one that has gone.
     */
    public static function resolve(mixed $supplied): array
    {
        if (! \is_array($supplied) || $supplied === []) {
            return [];
        }

        if (\count($supplied) > self::MAX) {
            throw ValidationException::withMessages([
                'targets' => sprintf('The targets field may not name more than %d sessions.', self::MAX),
            ]);
        }

        // `filter_var` rather than `is_int`, because that is exactly what Laravel's `integer` rule
        // tests -- so a value the validator in front of this admitted cannot be refused here, and
        // a direct caller is held to the same set rather than a narrower one.
        $wanted = [];

        foreach ($supplied as $id) {
            $integer = filter_var($id, FILTER_VALIDATE_INT);

            // The `=== false` is what narrows `int|false` for Larastan, and no input can tell it
            // from the comparison beside it, because `false < 1` is true for every value that
            // reaches it. An equivalent mutant rather than a gap.
            // @pest-mutate-ignore: FalseToTrue
            if ($integer === false || $integer < 1) {
                throw ValidationException::withMessages([
                    'targets' => 'The targets field must name session ids.',
                ]);
            }

            $wanted[] = $integer;
        }

        // Deduplicated before the read so that naming one session twice costs one row rather than
        // two, and so the recorded list cannot depend on how many times a poster repeated an id.
        //
        // `array_values` is for the declared `list<int>` and nothing else: the two uses below are a
        // `whereKey()` and a `count()`, and neither reads a key. No input can tell the wrapped and
        // unwrapped forms apart.
        // @pest-mutate-ignore: UnwrapArrayValues
        $wanted = array_values(array_unique($wanted));

        // **Ordered in the query rather than sorted afterwards.** `get()` promises no order, so a
        // `sort()` on the result was the only thing making the recorded list deterministic -- and
        // no test could show it, because SQLite happens to return rows by key anyway. Asking the
        // database for the order it is going to be read in makes the guarantee one mechanism
        // instead of two, and one a test can fail.
        $sessions = AgentSession::query()->whereKey($wanted)->orderBy('id')->get();

        $resolved = [];

        foreach ($sessions as $session) {
            // `canBeAssigned()` is the same predicate a reassignment uses to decide whether a
            // session can still be handed work. A directive naming a session that has gone is
            // naming nobody, and it is refused rather than recorded as a target nothing will read.
            if (TaskList::canBeAssigned($session)) {
                $resolved[] = $session->id;
            }
        }

        if (\count($resolved) !== \count($wanted)) {
            throw ValidationException::withMessages([
                'targets' => 'The targets field must name sessions that can still be worked.',
            ]);
        }

        return $resolved;
    }
}
