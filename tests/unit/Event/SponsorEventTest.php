<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Tests\Unit\Event;

use Flarum\User\User;
use FoF\GitHubSponsors\Event\SponsorAdded;
use FoF\GitHubSponsors\Event\SponsorRemoved;
use Mockery;
use PHPUnit\Framework\TestCase;

class SponsorEventTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSponsorAddedEventContainsUser()
    {
        $user = $this->createMockUser(1, 'test@example.com');
        $event = new SponsorAdded($user);

        $this->assertSame($user, $event->user);
        $this->assertNull($event->sponsorData);
    }

    public function testSponsorAddedEventContainsSponsorData()
    {
        $user = $this->createMockUser(1, 'test@example.com');
        $sponsorData = (object) [
            'email' => 'test@example.com',
            'databaseId' => 12345,
        ];

        $event = new SponsorAdded($user, $sponsorData);

        $this->assertSame($user, $event->user);
        $this->assertSame($sponsorData, $event->sponsorData);
        $this->assertEquals('test@example.com', $event->sponsorData->email);
        $this->assertEquals(12345, $event->sponsorData->databaseId);
    }

    public function testSponsorRemovedEventContainsUser()
    {
        $user = $this->createMockUser(1, 'test@example.com');
        $event = new SponsorRemoved($user);

        $this->assertSame($user, $event->user);
    }

    public function testSponsorAddedEventWithNullSponsorData()
    {
        $user = $this->createMockUser(1, 'test@example.com');
        $event = new SponsorAdded($user, null);

        $this->assertSame($user, $event->user);
        $this->assertNull($event->sponsorData);
    }

    public function testSponsorDataStructure()
    {
        $user = $this->createMockUser(1, 'sponsor@example.com');
        $sponsorData = (object) [
            'email' => 'sponsor@example.com',
            'databaseId' => 99999,
            'login' => 'testuser',
            'name' => 'Test User',
        ];

        $event = new SponsorAdded($user, $sponsorData);

        $this->assertIsObject($event->sponsorData);
        $this->assertObjectHasProperty('email', $event->sponsorData);
        $this->assertObjectHasProperty('databaseId', $event->sponsorData);
        $this->assertObjectHasProperty('login', $event->sponsorData);
        $this->assertObjectHasProperty('name', $event->sponsorData);
    }

    /**
     * @return User&\Mockery\MockInterface
     */
    private function createMockUser(int $id, string $email): User
    {
        /** @var User&\Mockery\MockInterface $user */
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('setAttribute')->andReturnSelf();
        $user->shouldReceive('getAttribute')->andReturnUsing(function ($key) use ($id, $email) {
            if ($key === 'id') return $id;
            if ($key === 'email') return $email;
            if ($key === 'username') return "user$id";
            return null;
        });

        $user->id = $id;
        $user->email = $email;
        $user->username = "user$id";

        return $user;
    }
}
