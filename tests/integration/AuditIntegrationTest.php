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

use Flarum\Audit\AuditLogger;
use FoF\GitHubSponsors\SponsorStatus;
use FoF\GitHubSponsors\Services\SponsorSynchronizer;
use FoF\GitHubSponsors\Tests\fixtures\FakeGitHubProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * With flarum/audit enabled, every change the sync makes lands in the audit log.
 */
class AuditIntegrationTest extends SyncTestCase
{
    protected function extensions(): array
    {
        return ['flarum-audit', 'fof-github-sponsors'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Keeps Audit's own "extension enabled" entry out of the assertions.
        AuditLogger::$testMode = true;

        $this->setting(SponsorSynchronizer::BASELINE_SETTING, '2026-01-01T00:00:00+00:00');
    }

    protected function tearDown(): void
    {
        AuditLogger::$testMode = false;

        parent::tearDown();
    }

    /** @return array<int, array{action: string, actor_id: int|null, client: string, payload: array<string, mixed>}> */
    protected function auditEntries(): array
    {
        return $this->database()->table('audit_log')
            ->where('action', 'like', 'fof_github_sponsors.%')
            ->orderBy('id')
            ->get()
            ->map(fn ($row) => ['action' => $row->action, 'actor_id' => $row->actor_id, 'client' => $row->client, 'payload' => json_decode($row->payload, true)])
            ->all();
    }

    #[Test]
    public function a_stopped_monthly_sponsorship_is_logged_with_its_group_move(): void
    {
        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[3, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github', null, 'past')]);

        $this->sync();

        // assertEquals: MySQL's JSON column does not preserve payload key order.
        $this->assertEquals([[
            'action'   => 'fof_github_sponsors.recurring_stopped',
            'actor_id' => null,
            'client'   => 'cli',
            'payload'  => [
                'user_id'         => 3,
                'previous_status' => 'recurring',
                'current_status'  => 'onetime',
                'old_group_ids'   => [self::RECURRING_GROUP],
                'new_group_ids'   => [self::ONETIME_GROUP],
            ],
        ]], $this->auditEntries());
    }

    #[Test]
    public function each_transition_and_repair_has_its_own_action(): void
    {
        $this->known(4, SponsorStatus::OneTime, [self::ONETIME_GROUP]);
        $this->members([[4, self::ONETIME_GROUP]]);
        $this->known(5, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        FakeGitHubProvider::sponsors([
            $this->sponsor(1002, 'bob-on-github'),
            $this->sponsor(5001, 'dave-gh', 'dave@example.com'),
            $this->sponsor(2001, 'alice-gh', 'alice@example.com', 'onetime'),
        ]);

        $this->sync();

        $byUser = [];

        foreach ($this->auditEntries() as $entry) {
            $byUser[$entry['payload']['user_id']] = $entry['action'];
        }

        ksort($byUser);

        $this->assertSame([
            2 => 'fof_github_sponsors.onetime_started',
            3 => 'fof_github_sponsors.recurring_started',
            4 => 'fof_github_sponsors.sponsorship_ended',
            5 => 'fof_github_sponsors.groups_changed',
        ], $byUser);
    }

    #[Test]
    public function an_adopted_membership_is_logged(): void
    {
        $this->members([[4, self::RECURRING_GROUP]]);
        FakeGitHubProvider::sponsors([$this->sponsor(4001, 'carol-gh', 'carol@example.com')]);

        $this->sync();

        $adopted = array_values(array_filter($this->auditEntries(), fn ($entry) => $entry['action'] === 'fof_github_sponsors.membership_adopted'));
        $this->assertCount(1, $adopted);
        $this->assertEquals(['user_id' => 4, 'new_group_ids' => [self::RECURRING_GROUP]], $adopted[0]['payload']);
    }

    #[Test]
    public function a_dry_run_logs_nothing(): void
    {
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github')]);

        $this->sync(['--dry-run' => true]);

        $this->assertSame([], $this->auditEntries());
    }
}
