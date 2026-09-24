<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

/**
 * What an idle lane is waiting for: the closed vocabulary of `On what` for a held lane (#334).
 *
 * **Closed, because #314 found free text is what a hand-maintained board drifted on.** Every hold
 * reads `<party> — <what>`, and the `<what>` is one of these, so a board row always says something
 * a reader can act on and never a sentence only its writer understood. Each case belongs to one kind
 * of party: a developer can owe a decision, a ticket can only land.
 *
 * A new reason is a new case, which is the point: extending the vocabulary is a reviewed change
 * rather than a note somebody typed.
 */
enum HoldReason: string
{
    /**
     * The developer is freeing this seat so it can take tickets.
     */
    case ClearingSeat = 'clearing_seat';

    /**
     * The developer owes a decision the lane cannot proceed without.
     */
    case Decision = 'decision';

    /**
     * The developer owes an action outside the repository -- a credential, an account, a deploy.
     */
    case Action = 'action';

    /**
     * The named ticket has to land before this lane can take its next one.
     */
    case TicketLands = 'ticket_lands';

    /**
     * The named ticket's decision has to be recorded first.
     */
    case TicketDecided = 'ticket_decided';

    /**
     * Whether this reason is about a developer or a ticket.
     *
     * @return HoldParty The kind of party it names.
     */
    public function party(): HoldParty
    {
        return match ($this) {
            self::ClearingSeat, self::Decision, self::Action => HoldParty::Developer,
            self::TicketLands, self::TicketDecided => HoldParty::Ticket,
        };
    }

    /**
     * How the reason reads after the party, as the board renders `<party> — <what>`.
     *
     * @return string The phrase.
     */
    public function reads(): string
    {
        return match ($this) {
            self::ClearingSeat => 'clearing this seat to take tickets',
            self::Decision => 'a decision',
            self::Action => 'an action only they can take',
            self::TicketLands => 'that ticket to land',
            self::TicketDecided => "that ticket's decision",
        };
    }

    /**
     * Every reason's stored value.
     *
     * @return list<string> The values, in declaration order.
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }
}
