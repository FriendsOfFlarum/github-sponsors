<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Api;

use Flarum\Api\Schema;
use FoF\GitHubSponsors\Services\SupporterCounts;

/**
 * Public counts of sponsors a supporters page cannot list by name. Aggregates
 * only: nothing about a private sponsor is ever stored.
 */
class ForumAttributes
{
    public function __construct(protected SupporterCounts $counts)
    {
    }

    public function __invoke(): array
    {
        return [
            Schema\Integer::make('githubSponsorsPrivateRecurring')->get(fn () => $this->counts->get()['private']['recurring']),
            Schema\Integer::make('githubSponsorsPrivateOnetime')->get(fn () => $this->counts->get()['private']['onetime']),
            Schema\Integer::make('githubSponsorsUnmatchedRecurring')->get(fn () => $this->counts->get()['unmatched']['recurring']),
            Schema\Integer::make('githubSponsorsUnmatchedOnetime')->get(fn () => $this->counts->get()['unmatched']['onetime']),
        ];
    }
}
