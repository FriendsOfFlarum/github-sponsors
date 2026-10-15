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

use FoF\GitHubSponsors\Event\RecurringSponsorshipStopped;
use FoF\GitHubSponsors\Services\SponsorGroupClaims;
use FoF\GitHubSponsors\Services\SponsorSynchronizer;
use FoF\GitHubSponsors\Tests\fixtures\FakeOtherSupporterSource;
use FoF\GitHubSponsors\Sponsor;
use FoF\GitHubSponsors\SponsorStatus;
use FoF\GitHubSponsors\Tests\fixtures\FakeGitHubProvider;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;

/**
 * Runs the sync command end to end against a real database. Only GitHub's HTTP
 * responses are faked.
 */
class UpdateCommandTest extends SyncTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Most tests describe an established install; the baseline run has its own tests.
        $this->setting(SponsorSynchronizer::BASELINE_SETTING, '2026-01-01T00:00:00+00:00');
    }

    #[Test]
    public function a_new_monthly_sponsor_joins_the_monthly_group(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github')]);

        $this->sync();

        $this->assertTrue($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertSame(['RecurringSponsorshipStarted:3'], $this->eventLog());
        $this->assertSame(SponsorStatus::Recurring, Sponsor::find(3)->status);
        $this->assertSame([self::RECURRING_GROUP], Sponsor::find(3)->managedGroupIds());
    }

    #[Test]
    public function a_onetime_sponsor_joins_the_onetime_group(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'alice@example.com', 'onetime')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::ONETIME_GROUP));
        $this->assertSame(['OneTimeSponsorshipStarted:2'], $this->eventLog());
    }

    #[Test]
    public function a_past_monthly_sponsor_counts_as_a_onetime_sponsor(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'alice@example.com', 'past')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::ONETIME_GROUP));
        $this->assertFalse($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertSame(SponsorStatus::OneTime, Sponsor::find(2)->status);
    }

    #[Test]
    public function status_events_carry_the_transition_account_and_groups(): void
    {
        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[3, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github', null, 'past')]);

        $this->sync();

        $event = $this->events[0];
        $this->assertInstanceOf(RecurringSponsorshipStopped::class, $event);
        $this->assertSame(SponsorStatus::Recurring, $event->previous);
        $this->assertSame(SponsorStatus::OneTime, $event->current);
        $this->assertSame('bob-on-github', $event->account->login ?? null);
        $this->assertSame([self::RECURRING_GROUP], $event->oldGroupIds);
        $this->assertSame([self::ONETIME_GROUP], $event->newGroupIds);
    }

    #[Test]
    public function a_stopped_monthly_sponsor_moves_to_the_onetime_group(): void
    {
        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[3, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github', null, 'past')]);

        $this->sync();

        $this->assertFalse($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(3, self::ONETIME_GROUP));
        $this->assertSame(['RecurringSponsorshipStopped:3'], $this->eventLog());
    }

    #[Test]
    public function a_onetime_sponsor_who_subscribes_moves_to_the_monthly_group(): void
    {
        $this->known(2, SponsorStatus::OneTime, [self::ONETIME_GROUP]);
        $this->members([[2, self::ONETIME_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'alice@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(2, self::ONETIME_GROUP));
        $this->assertSame(['RecurringSponsorshipStarted:2'], $this->eventLog());
        $this->assertSame(SponsorStatus::OneTime, $this->events[0]->previous);
    }

    #[Test]
    public function a_sponsor_who_disappears_ends_their_sponsorship_and_is_forgotten(): void
    {
        $this->known(2, SponsorStatus::OneTime, [self::ONETIME_GROUP]);
        $this->members([[2, self::ONETIME_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github')]);

        $this->sync();

        $this->assertFalse($this->inGroup(2, self::ONETIME_GROUP));
        $this->assertContains('SponsorshipEnded:2', $this->eventLog());
        $this->assertNull(Sponsor::find(2));
    }

    #[Test]
    public function a_hand_added_supporter_who_stops_keeps_supporter_and_is_not_also_made_contributor(): void
    {
        // Known as monthly from before adoption existed, so the membership is not owned.
        $this->known(4, SponsorStatus::Recurring, []);
        $this->members([[4, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(4001, 'carol-gh', 'carol@example.com', 'past')]);

        $this->sync();

        $this->assertTrue($this->inGroup(4, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(4, self::ONETIME_GROUP));
        $this->assertSame(['RecurringSponsorshipStopped:4'], $this->eventLog());
    }

    #[Test]
    public function a_sponsor_added_by_hand_is_adopted(): void
    {
        $this->members([[4, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(4001, 'carol-gh', 'carol@example.com')]);

        $output = $this->sync();

        $this->assertStringContainsString('#4 carol: none → monthly, took over Supporter', $output);
        $this->assertSame([self::RECURRING_GROUP], Sponsor::find(4)->managedGroupIds());
        $this->assertSame(['RecurringSponsorshipStarted:4', 'SponsorMembershipAdopted:4'], $this->eventLog());
        $this->assertSame([self::RECURRING_GROUP], $this->events[1]->groupIds);
    }

    #[Test]
    public function an_adopted_membership_is_moved_when_the_sponsorship_stops(): void
    {
        $this->members([[4, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(4001, 'carol-gh', 'carol@example.com')]);
        $this->sync();

        FakeGitHubProvider::sponsors([$this->sponsor(4001, 'carol-gh', 'carol@example.com', 'past')]);
        $this->runCommand(['command' => 'fof:github-sponsors:update']);

        $this->assertFalse($this->inGroup(4, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(4, self::ONETIME_GROUP));
    }

    #[Test]
    public function a_new_monthly_sponsor_leaves_the_onetime_group_even_if_added_by_hand(): void
    {
        $this->members([[4, self::ONETIME_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(4001, 'carol-gh', 'carol@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(4, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(4, self::ONETIME_GROUP));
    }

    #[Test]
    public function a_monthly_membership_another_source_still_wants_is_kept(): void
    {
        FakeOtherSupporterSource::$wants = [self::RECURRING_GROUP => [3]];
        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[3, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github', null, 'past')]);

        $this->sync();

        $this->assertTrue($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(3, self::ONETIME_GROUP));
        $this->assertSame([], Sponsor::find(3)->managedGroupIds(), 'ownership passes to the source that still wants it');
        $this->assertSame(['RecurringSponsorshipStopped:3'], $this->eventLog());
    }

    #[Test]
    public function a_onetime_sponsor_another_source_has_as_monthly_is_not_put_in_the_onetime_group(): void
    {
        FakeOtherSupporterSource::$wants = [self::RECURRING_GROUP => [2]];
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'alice@example.com', 'onetime')]);

        $this->sync();

        $this->assertFalse($this->inGroup(2, self::ONETIME_GROUP));
        $this->assertSame(SponsorStatus::OneTime, Sponsor::find(2)->status);
    }

    #[Test]
    public function a_onetime_membership_another_source_still_wants_is_kept(): void
    {
        FakeOtherSupporterSource::$wants = [self::ONETIME_GROUP => [2]];
        $this->known(2, SponsorStatus::OneTime, [self::ONETIME_GROUP]);
        $this->members([[2, self::ONETIME_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::ONETIME_GROUP));
        $this->assertNull(Sponsor::find(2));
        $this->assertContains('SponsorshipEnded:2', $this->eventLog());
    }

    #[Test]
    public function the_monthly_group_wins_over_another_sources_onetime_claim(): void
    {
        FakeOtherSupporterSource::$wants = [self::ONETIME_GROUP => [2]];
        $this->members([[2, self::ONETIME_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'alice@example.com')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(2, self::ONETIME_GROUP));
    }

    #[Test]
    public function this_extension_tells_other_sources_whom_it_wants_in_each_group(): void
    {
        $this->known(2, SponsorStatus::Recurring, []);
        $this->known(3, SponsorStatus::OneTime, []);
        $this->known(4, null, [self::RECURRING_GROUP]);

        $claims = $this->app()->getContainer()->make(SponsorGroupClaims::class);

        $this->assertSame([2], $claims([2, 3, 4], self::RECURRING_GROUP));
        $this->assertSame([3], $claims([2, 3, 4], self::ONETIME_GROUP));
        $this->assertSame([], $claims([2, 3, 4], 999));
        $this->assertContains(SponsorGroupClaims::class, array_map(fn ($c) => $c::class, iterator_to_array($this->app()->getContainer()->tagged(SponsorGroupClaims::TAG))));
    }

    #[Test]
    public function a_non_sponsor_added_by_hand_is_left_alone(): void
    {
        $this->members([[4, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github')]);

        $this->sync();

        $this->assertTrue($this->inGroup(4, self::RECURRING_GROUP));
        $this->assertNull(Sponsor::find(4));
    }

    #[Test]
    public function a_sponsor_missing_from_their_group_is_restored_with_a_groups_event(): void
    {
        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github')]);

        $this->sync();

        $this->assertTrue($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertSame(['SponsorGroupsChanged:3'], $this->eventLog());
    }

    #[Test]
    public function a_stale_membership_record_is_forgotten_without_being_reported(): void
    {
        $this->known(5, null, [self::RECURRING_GROUP]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github')]);

        $output = $this->sync();

        $this->assertStringNotContainsString('#5 dave', $output);
        $this->assertNull(Sponsor::find(5));
        $this->assertSame(['RecurringSponsorshipStarted:3'], $this->eventLog());
    }

    #[Test]
    public function with_one_group_for_both_a_stopped_sponsorship_changes_status_only(): void
    {
        $this->setting('fof-github-sponsors.onetime_group_id', (string) self::RECURRING_GROUP);
        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[3, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github', null, 'past')]);

        $this->sync();

        $this->assertTrue($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertSame(['RecurringSponsorshipStopped:3'], $this->eventLog());
    }

    #[Test]
    public function without_a_onetime_group_a_stopped_sponsorship_just_leaves_the_monthly_group(): void
    {
        $this->setting('fof-github-sponsors.onetime_group_id', '');
        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[3, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github', null, 'past')]);

        $this->sync();

        $this->assertFalse($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertFalse($this->inGroup(3, self::ONETIME_GROUP));
        $this->assertSame(SponsorStatus::OneTime, Sponsor::find(3)->status);
    }

    #[Test]
    public function a_sponsor_is_matched_by_a_confirmed_email_regardless_of_case(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'Alice@Example.COM')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertSame('alice-gh', $this->events[0]->account->login ?? null);
    }

    #[Test]
    public function an_unconfirmed_account_with_a_sponsors_email_gets_nothing(): void
    {
        // Anyone can register with a sponsor's public GitHub email; without confirming it, it proves nothing.
        FakeGitHubProvider::sponsors([$this->sponsor(6001, 'eve', 'eve@example.com')]);

        $this->sync();

        $this->assertFalse($this->inGroup(6, self::RECURRING_GROUP));
        $this->assertNull(Sponsor::find(6));
    }

    #[Test]
    public function an_organization_sponsor_is_matched_by_its_email(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(9001, 'carol-co', 'carol@example.com', 'monthly', 'Organization')]);

        $this->sync();

        $this->assertTrue($this->inGroup(4, self::RECURRING_GROUP));
    }

    #[Test]
    public function sponsors_on_later_pages_are_included(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(2001, 'alice-gh', 'alice@example.com')], [$this->sponsor(1002, 'bob-on-github')]);

        $this->sync();

        $this->assertTrue($this->inGroup(2, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(3, self::RECURRING_GROUP));
    }

    #[Test]
    public function a_dry_run_changes_nothing(): void
    {
        $this->known(5, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[5, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github')]);

        $output = $this->sync(['--dry-run' => true]);

        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('#3 bob: none → monthly, +Supporter', $output);
        $this->assertFalse($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertTrue($this->inGroup(5, self::RECURRING_GROUP));
        $this->assertNull(Sponsor::find(3));
        $this->assertSame([], $this->eventLog());
    }

    #[Test]
    public function the_first_sync_records_statuses_without_status_events(): void
    {
        $this->setting(SponsorSynchronizer::BASELINE_SETTING, '');
        $this->known(3, null, [self::RECURRING_GROUP]);
        $this->members([[2, self::ONETIME_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github'), $this->sponsor(2001, 'alice-gh', 'alice@example.com', 'past')]);

        $output = $this->sync();

        $this->assertStringContainsString('First sync', $output);
        $this->assertTrue($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertSame(SponsorStatus::Recurring, Sponsor::find(3)->status);
        $this->assertSame(SponsorStatus::OneTime, Sponsor::find(2)->status);
        // No status events, but restoring bob's membership and adopting alice's
        // hand-added one are still recorded.
        $this->assertSame(['SponsorMembershipAdopted:2', 'SponsorGroupsChanged:3'], $this->eventLog());
        $this->assertNotEmpty($this->database()->table('settings')->where('key', SponsorSynchronizer::BASELINE_SETTING)->value('value'));
    }

    #[Test]
    public function a_dry_run_does_not_record_the_baseline(): void
    {
        $this->setting(SponsorSynchronizer::BASELINE_SETTING, '');
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github')]);

        $this->sync(['--dry-run' => true]);

        $this->assertEmpty($this->database()->table('settings')->where('key', SponsorSynchronizer::BASELINE_SETTING)->value('value'));
    }

    #[Test]
    public function a_rejected_token_changes_nothing_and_says_why(): void
    {
        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[3, self::RECURRING_GROUP]]);
        FakeGitHubProvider::reset([new Response(401, [], json_encode(['message' => 'Bad credentials']))]);

        $output = $this->sync();

        $this->assertStringContainsString('GitHub rejected the token', $output);
        $this->assertTrue($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertSame([], $this->eventLog());
    }

    #[Test]
    public function no_sponsors_at_all_needs_force_to_end_known_sponsorships(): void
    {
        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[3, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors();

        $this->assertStringContainsString('--force', $this->sync());
        $this->assertTrue($this->inGroup(3, self::RECURRING_GROUP));
    }

    #[Test]
    public function no_sponsors_at_all_with_force_ends_known_sponsorships(): void
    {
        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[3, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors();

        $this->sync(['--force' => true]);

        $this->assertFalse($this->inGroup(3, self::RECURRING_GROUP));
        $this->assertSame(['SponsorshipEnded:3'], $this->eventLog());
    }

    #[Test]
    public function log_lines_carry_hours_and_minutes_once(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github')]);

        $output = $this->sync();

        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2} \d{2}, \d{4} @ \d{2}:\d{2} [AP]M \| Retrieving/m', $output);
        $this->assertDoesNotMatchRegularExpression('/@ \d{2}:\d{2} [AP]M \| [A-Z][a-z]{2} \d{2}, \d{4} @/', $output);
    }
}
