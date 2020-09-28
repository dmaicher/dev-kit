<?php

declare(strict_types=1);

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Action;

use App\Action\Exception\CannotDetermineNextRelease;
use App\Action\Exception\NoPullRequestsMergedSinceLastRelease;
use App\Command\AbstractCommand;
use App\Domain\Exception\NoBranchesAvailable;
use App\Domain\Value\Branch;
use App\Domain\Value\NextRelease;
use App\Domain\Value\Project;
use App\Domain\Value\Repository;
use App\Github\Api\Branches;
use App\Github\Api\PullRequests;
use App\Github\Api\Releases;
use App\Github\Api\Statuses;
use App\Github\Domain\Value\PullRequest;
use App\Github\Exception\LatestReleaseNotFound;

final class DetermineNextRelease
{
    private Releases $releases;
    private Branches $branches;
    private Statuses $statuses;
    private PullRequests $pullRequests;

    public function __construct(
        Releases $releases,
        Branches $branches,
        Statuses $statuses,
        PullRequests $pullRequests
    ) {
        $this->releases = $releases;
        $this->branches = $branches;
        $this->statuses = $statuses;
        $this->pullRequests = $pullRequests;
    }

    public function __invoke(Project $project): NextRelease
    {
        $repository = $project->repository();

        try {
            $branch = $project->stableBranch();
        } catch (NoBranchesAvailable $e) {
            throw CannotDetermineNextRelease::forProject(
                $project,
                $e
            );
        }

        try {
            $currentRelease = $this->releases->latest($repository);
        } catch (LatestReleaseNotFound $e) {
            throw CannotDetermineNextRelease::forProject(
                $project,
                $e
            );
        }

        $pullRequests = $this->findPullRequestsSince(
            $repository,
            $branch,
            $currentRelease->publishedAt(),
            AbstractCommand::SONATA_CI_BOT
        );

        if ([] === $pullRequests) {
            throw NoPullRequestsMergedSinceLastRelease::forProject(
                $project,
                $currentRelease->publishedAt()
            );
        }

        $branchToRelease = $this->branches->get(
            $repository,
            $branch->name()
        );

        $combinedStatus = $this->statuses->combined(
            $repository,
            $branchToRelease->commit()->sha()
        );

        return NextRelease::fromValues(
            $project,
            $currentRelease->tag(),
            $combinedStatus,
            $pullRequests
        );
    }

    /**
     * @return PullRequest[]
     */
    private function findPullRequestsSince(Repository $repository, Branch $branch, \DateTimeImmutable $date, ?string $filterUsername = null): array
    {
        $mergedPullRequests = $this->pullRequests->all(
            $repository,
            [
                'state' => 'merged',
                'base' => $branch->name(),
            ]
        );

        return array_reduce($mergedPullRequests, static function (array $pullRequests, PullRequest $pullRequest) use ($date, $filterUsername): array {
            if (null !== $filterUsername
                && $filterUsername === $pullRequest->user()->login()
            ) {
                return $pullRequests;
            }

            if (!$pullRequest->isMerged()) {
                return $pullRequests;
            }

            $mergedAt = $pullRequest->mergedAt();
            if ($mergedAt instanceof \DateTimeImmutable
                && $mergedAt <= $date
            ) {
                return $pullRequests;
            }

            $pullRequests[] = $pullRequest;

            return $pullRequests;
        }, []);
    }
}
