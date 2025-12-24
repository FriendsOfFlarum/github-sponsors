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
use PHPUnit\Framework\TestCase;

class SponsorMatcherTest extends TestCase
{
    private SponsorMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new SponsorMatcher();
    }

    public function testGetSponsorEmails()
    {
        $sponsors = [
            (object) ['sponsor' => (object) ['email' => 'test1@example.com', 'databaseId' => 1]],
            (object) ['sponsor' => (object) ['email' => 'test2@example.com', 'databaseId' => 2]],
            (object) ['sponsor' => (object) ['email' => null, 'databaseId' => 3]], // No email
        ];

        $emails = $this->matcher->getSponsorEmails($sponsors);

        $this->assertCount(2, $emails);
        $this->assertTrue($emails->contains('test1@example.com'));
        $this->assertTrue($emails->contains('test2@example.com'));
    }

    public function testGetSponsorIds()
    {
        $sponsors = [
            (object) ['sponsor' => (object) ['email' => 'test1@example.com', 'databaseId' => 123]],
            (object) ['sponsor' => (object) ['email' => 'test2@example.com', 'databaseId' => 456]],
        ];

        $ids = $this->matcher->getSponsorIds($sponsors);

        $this->assertCount(2, $ids);
        $this->assertContains(123, $ids);
        $this->assertContains(456, $ids);
    }

    public function testGetSponsorIdsFiltersNull()
    {
        $sponsors = [
            (object) ['sponsor' => (object) ['email' => 'test1@example.com', 'databaseId' => 123]],
            (object) ['sponsor' => (object) ['email' => 'test2@example.com', 'databaseId' => null]],
        ];

        $ids = $this->matcher->getSponsorIds($sponsors);

        $this->assertCount(1, $ids);
        $this->assertContains(123, $ids);
    }

    public function testGetSponsorEmailsRemovesDuplicates()
    {
        $sponsors = [
            (object) ['sponsor' => (object) ['email' => 'test@example.com', 'databaseId' => 1]],
            (object) ['sponsor' => (object) ['email' => 'test@example.com', 'databaseId' => 2]],
        ];

        $emails = $this->matcher->getSponsorEmails($sponsors);

        $this->assertCount(1, $emails);
    }
}
