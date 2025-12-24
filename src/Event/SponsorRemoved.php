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
    /**
     * The Flarum user who was removed.
     *
     * @var User
     */
    public $user;

    /**
     * @param User $user The Flarum user who was removed from sponsors
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }
}
