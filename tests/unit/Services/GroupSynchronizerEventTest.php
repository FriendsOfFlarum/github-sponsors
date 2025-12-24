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

use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use FoF\GitHubSponsors\Event\SponsorAdded;
use FoF\GitHubSponsors\Event\SponsorRemoved;
use FoF\GitHubSponsors\Services\GroupSynchronizer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

class GroupSynchronizerEventTest extends TestCase
{
    private GroupSynchronizer $synchronizer;
    /** @var SettingsRepositoryInterface&\Mockery\MockInterface */
    private SettingsRepositoryInterface $settings;
    /** @var Dispatcher&\Mockery\MockInterface */
    private Dispatcher $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = Mockery::mock(SettingsRepositoryInterface::class);
        $this->events = Mockery::mock(Dispatcher::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSponsorAddedEventIsDispatchedWhenUserAdded()
    {
        $user = $this->createMockUser(1, 'new@example.com');
        $this->setupNoGithubOAuthProvider($user);

        // Mock groups relation to indicate user is not in group yet
        $groupsRelation = Mockery::mock(BelongsToMany::class);
        $groupsRelation->shouldReceive('find')->with(1)->andReturn(null);
        $groupsRelation->shouldReceive('attach')->with(1)->once();
        $user->shouldReceive('groups')->andReturn($groupsRelation);

        $group = $this->createMockGroupWithRemovalSupport([]);
        $sponsorUsers = collect([$user]);

        $sponsorData = (object) [
            'sponsor' => (object) [
                'email' => 'new@example.com',
                'databaseId' => 12345,
            ],
        ];

        // Assert that SponsorAdded event is dispatched with correct data
        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function ($event) use ($user) {
                return $event instanceof SponsorAdded
                    && $event->user === $user
                    && $event->sponsorData !== null
                    && $event->sponsorData->email === 'new@example.com'
                    && $event->sponsorData->databaseId === 12345;
            }));

        $this->settings->shouldReceive('get')->with('fof-github-sponsors.users', '[]')->andReturn('[]');
        $this->settings->shouldReceive('set')->once();

        $this->synchronizer = new GroupSynchronizer($this->settings, $this->events);

        $changes = $this->synchronizer->synchronize(
            $group,
            $sponsorUsers,
            ['new@example.com'],
            [12345],
            false,
            [$sponsorData]
        );

        // Verify the user was added
        $this->assertCount(1, $changes['added']);
        $this->assertEquals(1, $changes['added']->first()->id);
    }

    public function testSponsorRemovedEventIsDispatchedWhenUserRemoved()
    {
        $user = $this->createMockUser(1, 'removed@example.com');
        $this->setupNoGithubOAuthProvider($user);

        $group = $this->createMockGroupWithRemovalSupport([$user]);
        $sponsorUsers = collect([]);

        // Assert that SponsorRemoved event is dispatched
        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(function ($event) use ($user) {
                return $event instanceof SponsorRemoved
                    && $event->user === $user;
            }));

        $this->settings->shouldReceive('get')->with('fof-github-sponsors.users', '[]')->andReturn('[1]');
        $this->settings->shouldReceive('set')->once();

        $this->synchronizer = new GroupSynchronizer($this->settings, $this->events);

        $changes = $this->synchronizer->synchronize(
            $group,
            $sponsorUsers,
            [],
            [],
            false,
            []
        );

        // Verify the user was removed
        $this->assertCount(1, $changes['removed']);
        $this->assertEquals(1, $changes['removed']->first()->id);
    }

    public function testNoEventsDispatchedInDryRunMode()
    {
        $user = $this->createMockUser(1, 'new@example.com');
        $this->setupNoGithubOAuthProvider($user);

        // Mock groups relation to indicate user is not in group yet
        $groupsRelation = Mockery::mock(BelongsToMany::class);
        $groupsRelation->shouldReceive('find')->with(1)->andReturn(null);
        $user->shouldReceive('groups')->andReturn($groupsRelation);

        $group = $this->createMockGroup([]);
        $sponsorUsers = collect([$user]);

        // Assert that NO events are dispatched in dry-run mode
        $this->events->shouldReceive('dispatch')->never();

        $this->settings->shouldReceive('get')->with('fof-github-sponsors.users', '[]')->andReturn('[]');

        $this->synchronizer = new GroupSynchronizer($this->settings, $this->events);

        $changes = $this->synchronizer->synchronize(
            $group,
            $sponsorUsers,
            ['new@example.com'],
            [12345],
            true, // dry-run mode
            []
        );

        // Verify the change was calculated but not applied
        $this->assertCount(1, $changes['added']);
    }

    public function testMultipleEventsDispatchedForMultipleChanges()
    {
        // User to add
        $newUser = $this->createMockUser(1, 'new@example.com');
        $this->setupNoGithubOAuthProvider($newUser);
        $groupsRelation1 = Mockery::mock(BelongsToMany::class);
        $groupsRelation1->shouldReceive('find')->with(1)->andReturn(null);
        $groupsRelation1->shouldReceive('attach')->with(1)->once();
        $newUser->shouldReceive('groups')->andReturn($groupsRelation1);

        // User to remove
        $oldUser = $this->createMockUser(2, 'old@example.com');
        $this->setupNoGithubOAuthProvider($oldUser);

        $group = $this->createMockGroupWithRemovalSupport([$oldUser]);

        $sponsorData = (object) [
            'sponsor' => (object) [
                'email' => 'new@example.com',
                'databaseId' => 12345,
            ],
        ];

        // Expect both SponsorRemoved and SponsorAdded events
        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(SponsorRemoved::class));

        $this->events->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(SponsorAdded::class));

        $this->settings->shouldReceive('get')->with('fof-github-sponsors.users', '[]')->andReturn('[2]');
        $this->settings->shouldReceive('set')->once();

        $this->synchronizer = new GroupSynchronizer($this->settings, $this->events);

        $changes = $this->synchronizer->synchronize(
            $group,
            collect([$newUser]),
            ['new@example.com'],
            [12345],
            false,
            [$sponsorData]
        );

        // Verify both changes occurred
        $this->assertCount(1, $changes['removed']);
        $this->assertEquals(2, $changes['removed']->first()->id);
        $this->assertCount(1, $changes['added']);
        $this->assertEquals(1, $changes['added']->first()->id);
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

    private function setupNoGithubOAuthProvider(User $user): void
    {
        $providersQuery = Mockery::mock(Builder::class);
        $providersQuery->shouldReceive('where')
            ->with('provider', 'github')
            ->andReturnSelf();
        $providersQuery->shouldReceive('whereIn')
            ->with('identifier', Mockery::type('array'))
            ->andReturnSelf();
        $providersQuery->shouldReceive('where')
            ->with('provider', 'github')
            ->andReturnSelf();
        $providersQuery->shouldReceive('where')
            ->with('identifier', Mockery::any())
            ->andReturnSelf();
        $providersQuery->shouldReceive('exists')
            ->andReturn(false);

        $providersRelation = Mockery::mock(HasMany::class);
        $providersRelation->shouldReceive('where')
            ->andReturn($providersQuery);

        $user->shouldReceive('loginProviders')
            ->andReturn($providersRelation);
    }

    /**
     * @return Group&\Mockery\MockInterface
     */
    private function createMockGroup(array $users): Group
    {
        /** @var Group&\Mockery\MockInterface $group */
        $group = Mockery::mock(Group::class)->makePartial();
        $group->shouldReceive('setAttribute')->andReturnSelf();
        $group->shouldReceive('getAttribute')->andReturnUsing(function ($key) {
            if ($key === 'id') return 1;
            if ($key === 'name_singular') return 'Test Group';
            return null;
        });

        $group->id = 1;
        $group->name_singular = 'Test Group';

        $usersQuery = Mockery::mock(Builder::class);
        $usersQuery->shouldReceive('whereIn')
            ->with('users.id', Mockery::type(Collection::class))
            ->andReturnSelf();
        $usersQuery->shouldReceive('get')
            ->andReturn(new EloquentCollection($users));

        $usersRelation = Mockery::mock(BelongsToMany::class);
        $usersRelation->shouldReceive('whereIn')
            ->andReturn($usersQuery);

        $group->shouldReceive('users')
            ->andReturn($usersRelation);

        return $group;
    }

    /**
     * @return Group&\Mockery\MockInterface
     */
    private function createMockGroupWithRemovalSupport(array $users): Group
    {
        /** @var Group&\Mockery\MockInterface $group */
        $group = Mockery::mock(Group::class)->makePartial();
        $group->shouldReceive('setAttribute')->andReturnSelf();
        $group->shouldReceive('getAttribute')->andReturnUsing(function ($key) {
            if ($key === 'id') return 1;
            if ($key === 'name_singular') return 'Test Group';
            return null;
        });

        $group->id = 1;
        $group->name_singular = 'Test Group';

        // Mock for getUsersToRemove - needs whereIn and get
        $usersQueryForGet = Mockery::mock(Builder::class);
        $usersQueryForGet->shouldReceive('whereIn')
            ->with('users.id', Mockery::type(Collection::class))
            ->andReturnSelf();
        $usersQueryForGet->shouldReceive('get')
            ->andReturn(new EloquentCollection($users));

        // Mock for removeUsersFromGroup - needs detach
        $usersRelation = Mockery::mock(BelongsToMany::class);
        $usersRelation->shouldReceive('whereIn')
            ->andReturn($usersQueryForGet);
        $usersRelation->shouldReceive('detach')
            ->with(Mockery::type(Collection::class))
            ->andReturn(count($users));

        $group->shouldReceive('users')
            ->andReturn($usersRelation);

        return $group;
    }
}
