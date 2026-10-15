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

/**
 * A user is no longer a sponsor at all, for example once their GitHub account no longer matches or the sponsorship disappears.
 */
class SponsorshipEnded extends AbstractSponsorStatusChanged
{
}
