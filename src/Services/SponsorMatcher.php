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
use FoF\GitHubSponsors\Api\GitHubSponsorsClient;
use Illuminate\Support\Collection;

class SponsorMatcher
{
    /**
     * Match GitHub sponsors to Flarum users, by the GitHub account linked through
     * OAuth or by the sponsor's public email.
     *
     * An email match requires a confirmed address: anyone can register with a
     * sponsor's public GitHub email, so an unconfirmed one proves nothing.
     *
     * @param array<object> $sponsors Sponsorship nodes from {@see \FoF\GitHubSponsors\Api\GitHubSponsorsClient::fetchSponsors()}
     *
     * @return Collection<int, User>
     */
    public function matchSponsorsToUsers(array $sponsors): Collection
    {
        $ids = $this->getSponsorIds($sponsors);
        $emails = $this->getSponsorEmails($sponsors);

        $byGitHubAccount = $ids
            ? User::query()
                ->whereIn('id', LoginProvider::query()->where('provider', 'github')->whereIn('identifier', $ids)->select('user_id'))
                ->get()
            : new Collection();

        // Compared lowercased in PHP; both spellings are queried so the indexed
        // lookup also matches on case-sensitive databases (PostgreSQL).
        $rawEmails = collect($sponsors)->map(fn ($sponsor) => $sponsor->sponsorEntity->email ?? null)->filter();
        $byEmail = $emails->isNotEmpty()
            ? User::query()
                ->where('is_email_confirmed', true)
                ->whereIn('email', $emails->merge($rawEmails)->unique()->values())
                ->get()
                ->filter(fn (User $user) => $emails->contains(strtolower($user->email)))
            : new Collection();

        return $byGitHubAccount->merge($byEmail)->unique('id')->values();
    }

    /**
     * Split sponsors into active monthly sponsors and everyone else (one-time
     * payments and past monthly sponsors).
     *
     * @param array<object> $sponsors
     *
     * @return array{recurring: array<object>, onetime: array<object>}
     */
    public function categorizeSponsors(array $sponsors): array
    {
        $recurring = [];
        $onetime = [];

        foreach ($sponsors as $sponsor) {
            if (GitHubSponsorsClient::isActiveRecurring($sponsor)) {
                $recurring[] = $sponsor;
            } else {
                $onetime[] = $sponsor;
            }
        }

        return ['recurring' => $recurring, 'onetime' => $onetime];
    }

    /**
     * @param array<object> $sponsors
     *
     * @return Collection<int, lowercase-string&non-falsy-string> Unique
     */
    public function getSponsorEmails(array $sponsors): Collection
    {
        return collect($sponsors)
            ->map(fn ($sponsor) => isset($sponsor->sponsorEntity->email) ? strtolower($sponsor->sponsorEntity->email) : null)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * GitHub account IDs, as strings: `login_providers.identifier` is a string
     * column, and PostgreSQL will not compare it with integers.
     *
     * @param array<object> $sponsors
     *
     * @return array<int, string>
     */
    public function getSponsorIds(array $sponsors): array
    {
        return collect($sponsors)
            ->map(fn ($sponsor) => isset($sponsor->sponsorEntity->databaseId) ? (string) $sponsor->sponsorEntity->databaseId : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
