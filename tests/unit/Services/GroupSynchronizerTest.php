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
use Flarum\User\LoginProvider;
use Flarum\User\User;
use FoF\GitHubSponsors\Services\GroupSynchronizer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

class GroupSynchronizerTest extends TestCase
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
        $this->events->shouldReceive('dispatch')->andReturn(null);
        $this->synchronizer = new GroupSynchronizer($this->settings, $this->events);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testGetUsersToRemoveFiltersCorrectly()
    {
        // Create mock users
        $activeUser = $this->createMockUser(1, 'active@example.com');
        $inactiveUser = $this->createMockUser(2, 'inactive@example.com');
        $oauthUser = $this->createMockUser(3, 'oauth@example.com');

        // Setup login providers for OAuth matching
        $this->setupGithubOAuthProvider($activeUser, '12345');
        $this->setupGithubOAuthProvider($oauthUser, '67890');
        $this->setupNoGithubOAuthProvider($inactiveUser);

        // Create mock group
        $group = $this->createMockGroup([
            $activeUser,
            $inactiveUser,
            $oauthUser,
        ]);

        // Test data: active sponsors
        $sponsorEmails = ['active@example.com'];
        $sponsorIds = [67890]; // oauthUser's GitHub ID
        $managedUsers = collect([1, 2, 3]); // All three users are managed

        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->synchronizer);
        $method = $reflection->getMethod('getUsersToRemove');
        $method->setAccessible(true);

        // Execute
        $usersToRemove = $method->invoke(
            $this->synchronizer,
            $group,
            $managedUsers,
            $sponsorEmails,
            $sponsorIds
        );

        // Assert: Only inactive user should be removed
        $this->assertCount(1, $usersToRemove);
        $this->assertEquals(2, $usersToRemove->first()->id);
    }

    public function testGetUsersToRemoveNoDuplicates()
    {
        // Create a user with multiple login providers
        $user = $this->createMockUser(1, 'user@example.com');

        // Setup no matching GitHub provider
        $this->setupNoGithubOAuthProvider($user);

        // Create mock group
        $group = $this->createMockGroup([$user]);

        // Test data
        $sponsorEmails = [];
        $sponsorIds = [];
        $managedUsers = collect([1]);

        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->synchronizer);
        $method = $reflection->getMethod('getUsersToRemove');
        $method->setAccessible(true);

        // Execute
        $usersToRemove = $method->invoke(
            $this->synchronizer,
            $group,
            $managedUsers,
            $sponsorEmails,
            $sponsorIds
        );

        // Assert: User should appear only once
        $this->assertCount(1, $usersToRemove);
        $this->assertEquals(1, $usersToRemove->first()->id);
    }

    public function testGetUsersToRemoveMatchesByEmail()
    {
        $user = $this->createMockUser(1, 'sponsor@example.com');
        $this->setupNoGithubOAuthProvider($user);

        $group = $this->createMockGroup([$user]);

        $sponsorEmails = ['sponsor@example.com'];
        $sponsorIds = [];
        $managedUsers = collect([1]);

        $reflection = new \ReflectionClass($this->synchronizer);
        $method = $reflection->getMethod('getUsersToRemove');
        $method->setAccessible(true);

        $usersToRemove = $method->invoke(
            $this->synchronizer,
            $group,
            $managedUsers,
            $sponsorEmails,
            $sponsorIds
        );

        // User should NOT be removed (matched by email)
        $this->assertCount(0, $usersToRemove);
    }

    public function testGetUsersToRemoveMatchesByGithubOAuth()
    {
        $user = $this->createMockUser(1, 'user@example.com');
        $this->setupGithubOAuthProvider($user, '12345');

        $group = $this->createMockGroup([$user]);

        $sponsorEmails = [];
        $sponsorIds = [12345];
        $managedUsers = collect([1]);

        $reflection = new \ReflectionClass($this->synchronizer);
        $method = $reflection->getMethod('getUsersToRemove');
        $method->setAccessible(true);

        $usersToRemove = $method->invoke(
            $this->synchronizer,
            $group,
            $managedUsers,
            $sponsorEmails,
            $sponsorIds
        );

        // User should NOT be removed (matched by GitHub OAuth)
        $this->assertCount(0, $usersToRemove);
    }

    public function testGetUsersToRemoveMatchesByEither()
    {
        // User matched by BOTH email and OAuth
        $user = $this->createMockUser(1, 'sponsor@example.com');
        $this->setupGithubOAuthProvider($user, '12345');

        $group = $this->createMockGroup([$user]);

        $sponsorEmails = ['sponsor@example.com'];
        $sponsorIds = [12345];
        $managedUsers = collect([1]);

        $reflection = new \ReflectionClass($this->synchronizer);
        $method = $reflection->getMethod('getUsersToRemove');
        $method->setAccessible(true);

        $usersToRemove = $method->invoke(
            $this->synchronizer,
            $group,
            $managedUsers,
            $sponsorEmails,
            $sponsorIds
        );

        // User should NOT be removed (matched by both)
        $this->assertCount(0, $usersToRemove);
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

    private function setupGithubOAuthProvider(User $user, string $identifier): void
    {
        $providersQuery = Mockery::mock(Builder::class);
        $providersQuery->shouldReceive('where')
            ->with('provider', 'github')
            ->andReturnSelf();
        $providersQuery->shouldReceive('whereIn')
            ->with('identifier', Mockery::type('array'))
            ->andReturnSelf();
        $providersQuery->shouldReceive('exists')
            ->andReturn(true);

        $providersRelation = Mockery::mock(HasMany::class);
        $providersRelation->shouldReceive('where')
            ->andReturn($providersQuery);

        $user->shouldReceive('loginProviders')
            ->andReturn($providersRelation);
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
}
