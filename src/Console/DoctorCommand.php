<?php

declare(strict_types=1);

namespace RobotCouncil\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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
#[Signature('robot-council:doctor')]
final class DoctorCommand extends Command
{
    /**
     * Report every check.
     *
     * @param  Doctor  $doctor  The checks.
     * @return int Zero when nothing failed, one when anything did.
     */
    public function handle(Doctor $doctor): int
    {
        $diagnoses = $doctor->examine();

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
