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
use FoF\GitHubSponsors\SponsorStatus;

/**
 * A user's sponsorship changed. Group IDs are the user's full group list before and
 * after this sync; they are equal when the extension changed no membership.
 */
abstract class AbstractSponsorStatusChanged implements SponsorStatusChanged
{
    /**
     * @param object|null $account     The sponsor's GitHub account (__typename, databaseId, login, email); null when the sponsorship ended
     * @param array<int>  $oldGroupIds
     * @param array<int>  $newGroupIds
     */
    public function __construct(
        public readonly User $user,
        public readonly ?SponsorStatus $previous,
        public readonly ?SponsorStatus $current,
        public readonly ?object $account,
        public readonly array $oldGroupIds,
        public readonly array $newGroupIds
    ) {
    }
}
