<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Tests\fixtures;

use Flarum\Foundation\AbstractServiceProvider;
use FoF\GitHubSponsors\Services\SponsorGroupClaims;

/**
 * Stands in for another supporter source (such as fof/open-collective) sharing the
 * same groups: it claims whichever users a test puts in {@see self::$wants}.
 */
class FakeOtherSupporterSource extends AbstractServiceProvider
{
    /** @var array<int, array<int>> User IDs wanted, by group ID */
    public static array $wants = [];

    public function register(): void
    {
        $this->container->bind(self::class.'.claims', fn () => fn (array $userIds, int $groupId) => array_values(array_intersect($userIds, self::$wants[$groupId] ?? [])));
        $this->container->tag([self::class.'.claims'], SponsorGroupClaims::TAG);
    }
}
