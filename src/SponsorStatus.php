<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors;

/**
 * A user's sponsorship as GitHub reports it. "No sponsorship" is null.
 */
enum SponsorStatus: string
{
    case Recurring = 'recurring';
    case OneTime = 'onetime';
}
