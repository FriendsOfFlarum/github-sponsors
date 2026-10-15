<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Providers;

use Flarum\Foundation\AbstractServiceProvider;
use FoF\GitHubSponsors\Services\SponsorGroupClaims;

class SupporterGroupsServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        $this->container->tag([SponsorGroupClaims::class], SponsorGroupClaims::TAG);
    }
}
