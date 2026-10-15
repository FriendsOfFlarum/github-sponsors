<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Tests\Unit\Services;

use FoF\GitHubSponsors\Services\SponsorMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SponsorMatcherTest extends TestCase
{
    protected function sponsor(?int $id, ?string $email): object
    {
        return (object) ['sponsorEntity' => (object) ['databaseId' => $id, 'login' => 'someone', 'email' => $email]];
    }

    #[Test]
    public function emails_are_lowercased_unique_and_skip_missing_ones(): void
    {
        $emails = (new SponsorMatcher())->getSponsorEmails([$this->sponsor(1, 'Alice@Example.com'), $this->sponsor(2, 'alice@example.com'), $this->sponsor(3, null)]);

        $this->assertSame(['alice@example.com'], $emails->all());
    }

    #[Test]
    public function ids_are_unique_strings_for_the_login_providers_lookup(): void
    {
        $ids = (new SponsorMatcher())->getSponsorIds([$this->sponsor(42, null), $this->sponsor(42, null), $this->sponsor(null, 'x@example.com'), $this->sponsor(7, null)]);

        $this->assertSame(['42', '7'], $ids);
    }
}
