<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * What a `robot-council:doctor` check was able to conclude.
 *
 * **Three states rather than two, and the third is the point.** Three of the wrong readings taken
 * on the deployed application on 2026-09-18 came from instruments that could not see the thing
 * they were reporting on, and reported clean. A check that cannot reach its answer has to say so,
 * because "looked and found nothing wrong" and "could not look" are the same output otherwise --
 * and only one of them is reassuring (#103).
 */
enum DiagnosisStatus: string
{
    /**
     * The check looked and found the configuration correct.
     */
    case Passed = 'passed';

    /**
     * The check looked and found a fault. Any of these makes the command exit non-zero.
     */
    case Failed = 'failed';

    /**
     * The check could not reach its answer from here. It reports what would make it reachable,
     * and does **not** fail the command: an operator cannot fix what the package cannot see.
     */
    case Undetermined = 'undetermined';
}
