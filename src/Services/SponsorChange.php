<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Services;

use Flarum\User\User;
use FoF\GitHubSponsors\SponsorStatus;

/**
 * What one sync changed (or, in a dry run, would change) for one user.
 */
class SponsorChange
{
    /**
     * @param array<int> $addedGroupIds
     * @param array<int> $removedGroupIds
     * @param array<int> $adoptedGroupIds Existing memberships the sync took over
     */
    public function __construct(
        public readonly User $user,
        public readonly ?SponsorStatus $previous,
        public readonly ?SponsorStatus $current,
        public readonly array $addedGroupIds,
        public readonly array $removedGroupIds,
        public readonly array $adoptedGroupIds = []
    ) {
    }

    public function statusChanged(): bool
    {
        return $this->previous !== $this->current;
    }
}
