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
 * Event fired when a user is removed from the sponsors group.
 */
class SponsorRemoved
{
    public function __construct(public User $user)
    {
    }
}
