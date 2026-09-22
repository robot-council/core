<?php

declare(strict_types=1);

namespace RobotCouncil\Support;

/**
 * One `robot-council:doctor` check and what it concluded.
 *
 * `detail` carries what the reader has to act on, and for an `Undetermined` it says what would
 * make the check reachable rather than leaving the reader to guess why it is silent.
 *
 * **Nothing here may hold a secret.** Every check that touches one reports whether it is set and
 * never what it is, and `DoctorTest` proves that by planting a credential and searching the
 * command's output for it rather than by reading the code (#103).
 */
final class Diagnosis
{
    /**
     * @param  string  $check  What was examined, as a short name the output lists.
     * @param  DiagnosisStatus  $status  What the check concluded.
     * @param  string  $detail  What to do about it, or what would make it determinable.
     */
    public function __construct(
        public string $check,
        public DiagnosisStatus $status,
        public string $detail
    ) {}

    /**
     * A check that looked and found nothing wrong.
     *
     * @param  string  $check  What was examined.
     * @param  string  $detail  What it found.
     * @return self The diagnosis.
     */
    public static function passed(string $check, string $detail): self
    {
        return new self($check, DiagnosisStatus::Passed, $detail);
    }

    /**
     * A check that looked and found a fault.
     *
     * @param  string  $check  What was examined.
     * @param  string  $detail  The fault, and what to do about it.
     * @return self The diagnosis.
     */
    public static function failed(string $check, string $detail): self
    {
        return new self($check, DiagnosisStatus::Failed, $detail);
    }

    /**
     * A check that could not reach its answer.
     *
     * @param  string  $check  What was examined.
     * @param  string  $detail  What would make it determinable.
     * @return self The diagnosis.
     */
    public static function undetermined(string $check, string $detail): self
    {
        return new self($check, DiagnosisStatus::Undetermined, $detail);
    }
}
