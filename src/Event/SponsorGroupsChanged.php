<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Event;

use Flarum\User\User;

/**
 * The sync changed a user's groups without their sponsorship changing: a sponsor
 * re-added to a group they were missing from, or the first sync after install.
 */
class SponsorGroupsChanged
{
    /**
     * @param array<int> $oldGroupIds
     * @param array<int> $newGroupIds
     */
    public function __construct(
        public readonly User $user,
        public readonly array $oldGroupIds,
        public readonly array $newGroupIds
    ) {
    }
}
