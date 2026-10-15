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
use FoF\GitHubSponsors\Event\SponsorAdded;
use FoF\GitHubSponsors\Event\SponsorRemoved;
use FoF\GitHubSponsors\Tests\fixtures\FakeGitHubProvider;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs the sync command end to end against a real database. Only GitHub's HTTP
 * responses are faked.
 */
class UpdateCommandTest extends ConsoleTestCase
{
    protected const GROUP = 10;

    /** @var array<int, object> */
    protected array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-github-sponsors');
        $this->extend((new Extend\ServiceProvider())->register(FakeGitHubProvider::class));
        FakeGitHubProvider::reset();

        $this->setting('fof-github-sponsors.api_token', 'token');
        $this->setting('fof-github-sponsors.account_type', 'organization');
        $this->setting('fof-github-sponsors.login', 'flarum');
        $this->setting('fof-github-sponsors.group_id', (string) self::GROUP);

        $this->prepareDatabase([
            Group::class => [['id' => self::GROUP, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters']],
            User::class  => [
                ['id' => 2, 'username' => 'alice', 'email' => 'alice@example.com', 'is_email_confirmed' => 1],
                ['id' => 3, 'username' => 'bob', 'email' => 'bob@example.com', 'is_email_confirmed' => 1],
                ['id' => 4, 'username' => 'carol', 'email' => 'carol@example.com', 'is_email_confirmed' => 1],
                ['id' => 5, 'username' => 'mallory', 'email' => 'dave@example.com', 'is_email_confirmed' => 0],
            ],
            // bob signed in with GitHub (account 1002) and uses a different email there.
            'login_providers' => [['id' => 1, 'user_id' => 3, 'provider' => 'github', 'identifier' => '1002', 'created_at' => Carbon::now()]],
        ]);
    }

    protected function sponsor(int $githubId, string $login, ?string $email = null, string $type = 'User'): array
    {
        return ['isOneTimePayment' => false, 'sponsorEntity' => ['__typename' => $type, 'databaseId' => $githubId, 'login' => $login, 'email' => $email]];
    }

    /**
     * @param array<int> $userIds
     */
    protected function managed(array $userIds): void
    {
        $this->setting('fof-github-sponsors.users', json_encode($userIds));
    }

    protected function members(int ...$userIds): void
    {
        $this->prepareDatabase(['group_user' => array_map(fn ($id) => ['user_id' => $id, 'group_id' => self::GROUP], $userIds)]);
    }

    protected function sync(array $options = []): string
    {
        $this->app()->getContainer()->make(Dispatcher::class)->listen(
            [SponsorAdded::class, SponsorRemoved::class],
            fn (object $event) => $this->events[] = $event
        );

        return $this->runCommand(['command' => 'fof:github-sponsors:update'] + $options);
    }

    protected function inGroup(int $userId): bool
    {
        return $this->database()->table('group_user')->where(['user_id' => $userId, 'group_id' => self::GROUP])->exists();
    }

    #[Test]
    public function a_sponsor_is_matched_through_their_linked_github_account(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github', 'bob-public@example.org')]);

        $this->sync();

        $this->assertTrue($this->inGroup(3));
        $this->assertSame('bob-on-github', $this->events[0]->sponsorData->login ?? null);
    }

    #[Test]
    public function a_sponsor_is_matched_by_a_confirmed_email_regardless_of_case(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'Alice@Example.COM')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2));
    }

    #[Test]
    public function an_unconfirmed_account_with_a_sponsors_email_gets_nothing(): void
    {
        // Anyone can register with a sponsor's public GitHub email; without confirming it, it proves nothing.
        FakeGitHubProvider::sponsors([$this->sponsor(4001, 'dave', 'dave@example.com')]);

        $this->sync();

        $this->assertFalse($this->inGroup(5));
    }

    #[Test]
    public function an_organization_sponsor_is_matched_by_its_email(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(9001, 'carol-co', 'carol@example.com', 'Organization')]);

        $this->sync();

        $this->assertTrue($this->inGroup(4));
    }

    #[Test]
    public function sponsors_on_later_pages_are_included(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'alice@example.com')], [$this->sponsor(1002, 'bob-on-github')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2));
        $this->assertTrue($this->inGroup(3));
    }

    #[Test]
    public function a_former_sponsor_is_removed_but_hand_added_members_stay(): void
    {
        $this->managed([3]);
        $this->members(3, 4);
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'alice@example.com')]);

        $this->sync();

        $this->assertFalse($this->inGroup(3));
        $this->assertTrue($this->inGroup(4));
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $this->managed([3]);
        $this->members(3);
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'alice@example.com')]);

        $this->assertStringContainsString('DRY RUN', $this->sync(['--dry-run' => true]));
        $this->assertFalse($this->inGroup(2));
        $this->assertTrue($this->inGroup(3));
        $this->assertSame([], $this->events);
    }

    #[Test]
    public function a_rejected_token_changes_nothing_and_says_why(): void
    {
        $this->managed([3]);
        $this->members(3);
        FakeGitHubProvider::reset([new Response(401, [], json_encode(['message' => 'Bad credentials']))]);

        $output = $this->sync();

        $this->assertStringContainsString('GitHub rejected the token', $output);
        $this->assertTrue($this->inGroup(3));
    }

    #[Test]
    public function no_sponsors_at_all_needs_force_to_remove_managed_members(): void
    {
        $this->managed([3]);
        $this->members(3);
        FakeGitHubProvider::sponsors();

        $this->assertStringContainsString('--force', $this->sync());
        $this->assertTrue($this->inGroup(3));
    }

    #[Test]
    public function no_sponsors_at_all_with_force_removes_managed_members(): void
    {
        $this->managed([3]);
        $this->members(3);
        FakeGitHubProvider::sponsors();

        $this->sync(['--force' => true]);

        $this->assertFalse($this->inGroup(3));
    }

    #[Test]
    public function log_lines_carry_hours_and_minutes_once(): void
    {
        FakeGitHubProvider::sponsors();

        $output = $this->sync();

        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2} \d{2}, \d{4} @ \d{2}:\d{2} [AP]M \| Retrieving/m', $output);
        $this->assertDoesNotMatchRegularExpression('/@ \d{2}:\d{2} [AP]M \| [A-Z][a-z]{2} \d{2}, \d{4} @/', $output);
    }
}
