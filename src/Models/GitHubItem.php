<?php

declare(strict_types=1);

namespace RobotCouncil\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One GitHub issue or pull request, as the webhook last reported it.
 *
 * Nothing mass-assigns this model. `Support\GitHubState` writes it with literal arrays.
 *
 * @property int $id
 * @property string $repository
 * @property int $number
 * @property bool $is_pull_request
 * @property string $state
 * @property bool $merged
 * @property bool $draft
 * @property string $title
 * @property string|null $head_ref
 * @property list<string> $labels
 * @property int $checkboxes
 * @property int $checkboxes_ticked
 * @property Carbon $github_updated_at
 */
#[Table(name: 'robot_council_github_items')]
final class GitHubItem extends Model
{
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
            'number' => 'integer',
            'is_pull_request' => 'boolean',
            'merged' => 'boolean',
            'draft' => 'boolean',
            'labels' => 'array',
            'checkboxes' => 'integer',
            'checkboxes_ticked' => 'integer',
            'github_updated_at' => 'datetime',
        ];
    }

    /**
     * The repository-qualified reference, `owner/name#N`, as a task names its issue.
     *
     * @return string The reference.
     */
    public function reference(): string
    {
        return $this->repository.'#'.$this->number;
    }

    /**
     * Whether it is still open.
     *
     * @return bool True while open.
     */
    public function isOpen(): bool
    {
        return $this->state === 'open';
    }
}
