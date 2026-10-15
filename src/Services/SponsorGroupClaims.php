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

use Flarum\Settings\SettingsRepositoryInterface;
use FoF\GitHubSponsors\Sponsor;
use FoF\GitHubSponsors\SponsorStatus;

/**
 * Answers, for other supporter sources, which users this extension wants in a
 * group. Sources that share groups (fof/open-collective, ...) ask each other before
 * removing anyone, so a membership another source still justifies is never removed,
 * and a monthly supporter is never also put in the one-time group.
 *
 * The contract is duck-typed so sources need no shared package: an invokable
 * registered under the {@see self::TAG} container tag, taking user IDs and a group
 * ID and returning the subset of those user IDs it wants in that group.
 */
class SponsorGroupClaims
{
    public const TAG = 'fof.supporter-groups';

    public function __construct(protected SettingsRepositoryInterface $settings)
    {
    }

    /**
     * @param array<int> $userIds
     *
     * @return array<int>
     */
    public function __invoke(array $userIds, int $groupId): array
    {
        $statuses = array_map(fn (SponsorStatus $status) => $status->value, $this->statusesFor($groupId));

        if (!$userIds || !$statuses) {
            return [];
        }

        return Sponsor::query()
            ->whereIn('user_id', $userIds)
            ->whereIn('status', $statuses)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The statuses this extension puts in the group, per its settings.
     *
     * @return array<SponsorStatus>
     */
    public function statusesFor(int $groupId): array
    {
        $recurring = (int) $this->settings->get('fof-github-sponsors.group_id');
        $onetime = (int) $this->settings->get('fof-github-sponsors.onetime_group_id');
        $statuses = [];

        if ($groupId === $recurring) {
            $statuses[] = SponsorStatus::Recurring;
        }

        if ($onetime && $groupId === $onetime) {
            $statuses[] = SponsorStatus::OneTime;
        }

        return $statuses;
    }
}
