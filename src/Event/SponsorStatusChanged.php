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
 * Implemented by every sponsor status event. Listen to this interface to react to
 * all of them; the concrete event is an {@see AbstractSponsorStatusChanged}.
 */
interface SponsorStatusChanged
{
}
