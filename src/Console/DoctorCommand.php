<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RobotCouncil\Support\Diagnosis;
use RobotCouncil\Support\DiagnosisStatus;
use RobotCouncil\Support\Doctor;

/**
 * Reports what is wrong with this application's configuration of the package.
 *
 * Every fault it looks for is invisible until something else goes wrong, and three were found on
 * the deployed application by accident rather than by looking (#103). Safe to run at any time: it
 * writes nothing and prints no secret.
 *
 * **An undetermined check does not fail the command.** An operator cannot fix what the package
 * cannot see, so those are reported with what would make them reachable and the exit code is left
 * to the checks that actually concluded something.
 */
#[Description("Report what is wrong with this application's robot-council configuration")]
#[Signature('robot-council:doctor {--only=* : Run only these checks, by the name each is reported under. Comma-separated, or repeat the option.}')]
final class DoctorCommand extends Command
{
    /**
     * Report every check, or only the ones named.
     *
     * **`--only` exists so something other than a person can gate on one answer.** A deploy that
     * wants to refuse a drifted migration set should not also fail because the queue has a backlog
     * or a Slack webhook is unset -- `robot-council/robot-council#7` is the case, and running all
     * eleven checks made the gate answer a much broader question than it asked.
     *
     * The exit code then reflects only what ran. A failure among the checks `--only` excluded is
     * not the caller's question and must not fail the command.
     *
     * @param  Doctor  $doctor  The checks.
     * @return int Zero when nothing that ran failed, one when anything did.
     */
    public function handle(Doctor $doctor): int
    {
        try {
            $diagnoses = $doctor->examine($this->requestedChecks());
        } catch (InvalidArgumentException $invalidArgumentException) {
            // Exits non-zero rather than examining nothing and succeeding. A gate that silently
            // stopped gating is indistinguishable from a healthy deployment.
            $this->components->error($invalidArgumentException->getMessage());

            return self::FAILURE;
        }

        foreach ($diagnoses as $diagnosis) {
            $this->report($diagnosis);
        }

        $failed = array_values(array_filter(
            $diagnoses,
            static fn (Diagnosis $diagnosis): bool => $diagnosis->status === DiagnosisStatus::Failed
        ));

        $undetermined = array_values(array_filter(
            $diagnoses,
            static fn (Diagnosis $diagnosis): bool => $diagnosis->status === DiagnosisStatus::Undetermined
        ));

        $this->newLine();

        if ($failed === []) {
            $this->components->info(sprintf(
                'Nothing failed. %d check(s) passed, %d could not be determined.',
                \count($diagnoses) - \count($undetermined),
                \count($undetermined)
            ));

            return self::SUCCESS;
        }

        $this->components->error(sprintf(
            '%d check(s) failed, %d could not be determined.',
            \count($failed),
            \count($undetermined)
        ));

        return self::FAILURE;
    }

    /**
     * The checks named by `--only`, flattened.
     *
     * Accepts both shapes an operator is likely to reach for -- the option repeated, and one
     * comma-separated list -- because guessing wrong costs a failed deploy to discover.
     *
     * @return list<string> The names asked for, or empty for all of them.
     */
    private function requestedChecks(): array
    {
        $names = [];

        // Declared `--only=*`, so Symfony always hands back an array; each entry may be null when
        // the option was passed without a value.
        foreach ($this->option('only') as $value) {
            if (! \is_string($value)) {
                continue;
            }

            foreach (explode(',', $value) as $name) {
                $name = trim($name);

                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * Print one diagnosis.
     *
     * The three statuses are rendered differently on purpose: an operator scanning the output has
     * to be able to tell "looked and found nothing wrong" from "could not look" without reading
     * the sentence, because those are the two that get confused.
     *
     * @param  Diagnosis  $diagnosis  The check to print.
     */
    private function report(Diagnosis $diagnosis): void
    {
        match ($diagnosis->status) {
            DiagnosisStatus::Passed => $this->components->twoColumnDetail($diagnosis->check, 'PASS'),
            DiagnosisStatus::Failed => $this->components->twoColumnDetail($diagnosis->check, 'FAIL'),
            DiagnosisStatus::Undetermined => $this->components->twoColumnDetail($diagnosis->check, 'UNKNOWN'),
        };

        if ($diagnosis->status !== DiagnosisStatus::Passed) {
            $this->line('    '.$diagnosis->detail);
        }
    }
}
