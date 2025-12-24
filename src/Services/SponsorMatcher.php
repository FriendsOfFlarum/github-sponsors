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

use Flarum\User\LoginProvider;
use Flarum\User\User;
use Illuminate\Support\Collection;

class SponsorMatcher
{
    /**
     * Match GitHub sponsors to Flarum users.
     *
     * Uses two matching strategies:
     * 1. Direct email matching between sponsor emails and Flarum user emails
     * 2. GitHub OAuth provider matching (sponsor GitHub ID -> login_providers -> Flarum user)
     *
     * @param array $sponsors Array of sponsor data from GitHub API
     *
     * @return Collection<User>
     */
    public function matchSponsorsToUsers(array $sponsors): Collection
    {
        $sponsors = collect($sponsors);

        $sponsorUsersIds = $sponsors->pluck('sponsor.databaseId')->all();
        $sponsorUsersEmails = $sponsors
            ->pluck('sponsor.email')
            ->merge(
                LoginProvider::query()
                    ->where('provider', 'github')
                    ->whereIn('identifier', $sponsorUsersIds)
                    ->join('users', 'login_providers.user_id', '=', 'users.id')
                    ->pluck('users.email')
            )
            ->filter()
            ->unique();

        return User::query()->whereIn('email', $sponsorUsersEmails)->get();
    }

    /**
     * Get all sponsor emails from the sponsor data.
     *
     * @param array $sponsors Array of sponsor data from GitHub API
     *
     * @return Collection<string>
     */
    public function getSponsorEmails(array $sponsors): Collection
    {
        return collect($sponsors)
            ->pluck('sponsor.email')
            ->filter()
            ->unique();
    }

    /**
     * Get all sponsor GitHub database IDs from the sponsor data.
     *
     * @param array $sponsors Array of sponsor data from GitHub API
     *
     * @return array<int>
     */
    public function getSponsorIds(array $sponsors): array
    {
        return collect($sponsors)
            ->pluck('sponsor.databaseId')
            ->filter()
            ->unique()
            ->all();
    }
}
