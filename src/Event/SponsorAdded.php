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
 * Event fired when a user is added to the sponsors group.
 */
class SponsorAdded
{
    /**
     * The Flarum user who was added.
     *
     * @var User
     */
    public $user;

    /**
     * The GitHub sponsor data from the API.
     *
     * @var object|null
     */
    public $sponsorData;

    /**
     * @param User        $user        The Flarum user who was added as a sponsor
     * @param object|null $sponsorData The GitHub sponsor data (contains email, databaseId, etc.)
     */
    public function __construct(User $user, ?object $sponsorData = null)
    {
        $this->user = $user;
        $this->sponsorData = $sponsorData;
    }
}
