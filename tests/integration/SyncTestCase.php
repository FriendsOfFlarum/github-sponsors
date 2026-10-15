<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Tests\integration;

use Carbon\Carbon;
use Flarum\Extend;
use Flarum\Group\Group;
use Flarum\Testing\integration\ConsoleTestCase;
use Flarum\User\User;
use FoF\GitHubSponsors\Event\SponsorGroupsChanged;
use FoF\GitHubSponsors\Event\SponsorMembershipAdopted;
use FoF\GitHubSponsors\Event\SponsorStatusChanged;
use FoF\GitHubSponsors\Sponsor;
use FoF\GitHubSponsors\SponsorStatus;
use FoF\GitHubSponsors\Tests\fixtures\FakeGitHubProvider;
use FoF\GitHubSponsors\Tests\fixtures\FakeOtherSupporterSource;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Shared fixtures for running the sync command: two groups, five users, faked
 * GitHub responses and a record of the events fired.
 */
abstract class SyncTestCase extends ConsoleTestCase
{
    protected const RECURRING_GROUP = 10;
    protected const ONETIME_GROUP = 11;

    /** @var array<int, object> */
    protected array $events = [];

    /** @return array<int, string> */
    protected function extensions(): array
    {
        return ['fof-github-sponsors'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension(...$this->extensions());
        $this->extend((new Extend\ServiceProvider())->register(FakeGitHubProvider::class)->register(FakeOtherSupporterSource::class));
        FakeGitHubProvider::reset();
        FakeGitHubProvider::$privateNodes = [];
        FakeOtherSupporterSource::$wants = [];

        $this->setting('fof-github-sponsors.api_token', 'token');
        $this->setting('fof-github-sponsors.account_type', 'organization');
        $this->setting('fof-github-sponsors.login', 'flarum');
        $this->setting('fof-github-sponsors.group_id', (string) self::RECURRING_GROUP);
        $this->setting('fof-github-sponsors.onetime_group_id', (string) self::ONETIME_GROUP);

        $this->prepareDatabase([
            Group::class => [
                ['id' => self::RECURRING_GROUP, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters'],
                ['id' => self::ONETIME_GROUP, 'name_singular' => 'Contributor', 'name_plural' => 'Contributors'],
            ],
            User::class => [
                ['id' => 2, 'username' => 'alice', 'email' => 'alice@example.com', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'bob', 'email' => 'bob@example.com', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'carol', 'email' => 'carol@example.com', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'dave', 'email' => 'dave@example.com', 'is_email_confirmed' => 1],
                ['id' => 6, 'username' => 'mallory', 'email' => 'eve@example.com', 'is_email_confirmed' => 0],
            ],
            // bob signed in with GitHub (account 1002) and uses a different email there.
            'login_providers' => [['id' => 1, 'user_id' => 3, 'provider' => 'github', 'identifier' => '1002', 'created_at' => Carbon::now()]],
        ]);
    }

    /**
     * A sponsorship node as GitHub returns it.
     *
     * @param 'monthly'|'past'|'onetime' $kind
     */
    protected function sponsor(int $githubId, string $login, ?string $email = null, string $kind = 'monthly', string $type = 'User'): array
    {
        return [
            'isActive'         => $kind === 'monthly',
            'isOneTimePayment' => $kind === 'onetime',
            'sponsorEntity'    => ['__typename' => $type, 'databaseId' => $githubId, 'login' => $login, 'email' => $email],
        ];
    }

    /**
     * A sponsor the extension already knows about.
     *
     * @param array<int> $managedGroupIds Groups the extension added them to
     */
    protected function known(int $userId, ?SponsorStatus $status, array $managedGroupIds): void
    {
        $this->prepareDatabase([
            Sponsor::class => [['user_id' => $userId, 'status' => $status?->value, 'managed_group_ids' => $managedGroupIds, 'status_changed_at' => $status ? Carbon::now() : null]],
        ]);
    }

    /**
     * @param array<int, array{0: int, 1: int}> $memberships [user ID, group ID] pairs
     */
    protected function members(array $memberships): void
    {
        $this->prepareDatabase([
            'group_user' => array_map(fn ($pair) => ['user_id' => $pair[0], 'group_id' => $pair[1]], $memberships),
        ]);
    }

    protected function sync(array $options = []): string
    {
        $this->app()->getContainer()->make(Dispatcher::class)->listen(
            [SponsorStatusChanged::class, SponsorGroupsChanged::class, SponsorMembershipAdopted::class],
            fn (object $event) => $this->events[] = $event
        );

        return $this->runCommand(['command' => 'fof:github-sponsors:update'] + $options);
    }

    protected function inGroup(int $userId, int $groupId): bool
    {
        return $this->database()->table('group_user')->where(['user_id' => $userId, 'group_id' => $groupId])->exists();
    }

    /** @return array<int, string> e.g. ["RecurringSponsorshipStarted:2"] */
    protected function eventLog(): array
    {
        return array_map(fn ($event) => (new \ReflectionClass($event))->getShortName().':'.$event->user->id, $this->events);
    }
}
