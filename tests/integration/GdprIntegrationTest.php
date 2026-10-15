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
use Flarum\Audit\AuditLogger;
use Flarum\Gdpr\DataProcessor;
use Flarum\Gdpr\Models\ErasureRequest;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use FoF\GitHubSponsors\Sponsor;
use FoF\GitHubSponsors\SponsorStatus;
use FoF\GitHubSponsors\Data\GitHubSponsor;
use FoF\GitHubSponsors\Services\SponsorSynchronizer;
use FoF\GitHubSponsors\Tests\fixtures\FakeGitHubProvider;
use Illuminate\Contracts\Filesystem\Factory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * With flarum/gdpr enabled, a member's sponsor record is exported with their data
 * and erased with their account.
 */
class GdprIntegrationTest extends SyncTestCase
{
    protected function extensions(): array
    {
        return ['flarum-audit', 'flarum-gdpr', 'fof-github-sponsors'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        AuditLogger::$testMode = true;

        $this->setting(SponsorSynchronizer::BASELINE_SETTING, '2026-01-01T00:00:00+00:00');
        $this->setting('mail_driver', 'log');
        $this->setting('flarum-gdpr.allow-anonymization', true);
        $this->setting('flarum-gdpr.allow-deletion', true);

        $this->known(3, SponsorStatus::Recurring, [self::RECURRING_GROUP]);
        $this->members([[3, self::RECURRING_GROUP]]);

        $this->prepareDatabase([
            'gdpr_erasure' => [
                ['id' => 1, 'user_id' => 3, 'verification_token' => 'tok', 'status' => ErasureRequest::STATUS_USER_CONFIRMED, 'created_at' => Carbon::now(), 'user_confirmed_at' => Carbon::now()],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        AuditLogger::$testMode = false;

        parent::tearDown();
    }

    protected function erase(string $mode): void
    {
        $response = $this->send($this->request('PATCH', '/api/user-erasure-requests/1', [
            'authenticatedAs' => 1,
            'json'            => ['data' => ['attributes' => ['processorComment' => 'Test', 'processedMode' => $mode]]],
        ]));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    protected function dataType(int $userId): GitHubSponsor
    {
        $container = $this->app()->getContainer();

        return new GitHubSponsor(
            User::findOrFail($userId),
            null,
            $container->make(Factory::class),
            $container->make(SettingsRepositoryInterface::class),
            $container->make(UrlGenerator::class),
            $container->make(TranslatorInterface::class)
        );
    }

    #[Test]
    public function the_sponsor_record_is_registered_with_gdpr(): void
    {
        $this->assertArrayHasKey(GitHubSponsor::class, $this->app()->getContainer()->make(DataProcessor::class)->types());
    }

    #[Test]
    public function the_export_includes_the_sponsor_record(): void
    {
        $export = $this->dataType(3)->export();

        $this->assertSame(['github-sponsors/sponsor.json'], array_keys($export));
        $data = json_decode($export['github-sponsors/sponsor.json'], true);
        $this->assertSame('recurring', $data['status']);
        $this->assertSame(['Supporter'], $data['managed_groups']);
        $this->assertNotEmpty($data['status_changed_at']);
    }

    #[Test]
    public function a_member_who_was_never_a_sponsor_has_nothing_to_export(): void
    {
        $this->assertNull($this->dataType(4)->export());
    }

    #[Test]
    public function anonymizing_a_sponsor_erases_their_record_and_the_sync_stays_silent(): void
    {
        $this->erase(ErasureRequest::MODE_ANONYMIZATION);

        $this->assertNull(Sponsor::find(3));

        // GitHub still lists bob; the anonymized account no longer matches (GDPR also
        // removed his GitHub login link).
        FakeGitHubProvider::sponsors([$this->sponsor(1002, 'bob-on-github', 'bob@example.com')]);
        $this->sync();

        $this->assertSame([], $this->eventLog());
        $this->assertNull(Sponsor::find(3));
        $this->assertFalse($this->database()->table('audit_log')->where('action', 'like', 'fof_github_sponsors.%')->exists());
    }

    #[Test]
    public function deleting_a_sponsor_erases_their_record(): void
    {
        $this->erase(ErasureRequest::MODE_DELETION);

        $this->assertNull(User::find(3));
        $this->assertNull(Sponsor::find(3));
    }
}
