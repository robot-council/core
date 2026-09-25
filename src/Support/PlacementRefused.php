<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

use RobotCouncil\Models\PlacementRule;
use RuntimeException;

/**
 * A placement broke at least one hard invariant, and wrote nothing (#320).
 *
 * Thrown from inside the placement's transaction so everything it touched rolls back, and caught
 * at the edge, which answers 422 naming every rule it broke rather than only the first -- a
 * coordinator that fixes one refusal should not discover the next only by trying again.
 */
final class PlacementRefused extends RuntimeException
{
    /**
     * @param  list<PlacementRule>  $rules  Every rule the placement broke, after waivers.
     */
    public function __construct(public readonly array $rules)
    {
        parent::__construct(sprintf(
            'The placement was refused: %s.',
            implode('; ', array_map(static fn (PlacementRule $rule): string => $rule->reads(), $rules))
        ));
    }
}
