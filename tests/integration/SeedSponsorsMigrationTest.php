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

use Flarum\Group\Group;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Upgrading moves the managed-user list from settings into the sponsors table.
 */
class SeedSponsorsMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-github-sponsors');

        $this->prepareDatabase([
            Group::class => [
                ['id' => 10, 'name_singular' => 'Supporter', 'name_plural' => 'Supporters'],
                ['id' => 11, 'name_singular' => 'Contributor', 'name_plural' => 'Contributors'],
            ],
            User::class => [
                ['id' => 2, 'username' => 'alice', 'email' => 'alice@example.com'],
                ['id' => 3, 'username' => 'bob', 'email' => 'bob@example.com'],
            ],
            'settings' => [
                ['key' => 'fof-github-sponsors.group_id', 'value' => '10'],
                // 99 no longer exists and must be skipped rather than break the foreign key.
                ['key' => 'fof-github-sponsors.users', 'value' => '[2,3,99]'],
            ],
        ]);
    }

    protected function migration(): array
    {
        return require __DIR__.'/../../migrations/2026_10_15_000001_seed_fof_github_sponsors_sponsors_from_settings.php';
    }

    #[Test]
    public function managed_users_become_sponsor_rows_owning_the_group(): void
    {
        $this->app();
        $this->migration()['up']($this->database()->getSchemaBuilder());

        $rows = $this->database()->table('fof_github_sponsors_sponsors')->orderBy('user_id')->get();

        $this->assertSame([2, 3], $rows->pluck('user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([10], json_decode($rows[0]->managed_group_ids, true));
        $this->assertSame([10], json_decode($rows[1]->managed_group_ids, true));
        $this->assertNull($rows[0]->status);
        $this->assertFalse($this->database()->table('settings')->where('key', 'fof-github-sponsors.users')->exists());
    }

    #[Test]
    public function rolling_back_restores_the_settings_list(): void
    {
        $this->app();
        $this->migration()['up']($this->database()->getSchemaBuilder());
        $this->migration()['down']($this->database()->getSchemaBuilder());

        $setting = fn (string $key) => json_decode($this->database()->table('settings')->where('key', $key)->value('value'), true);

        $this->assertSame([2, 3], $setting('fof-github-sponsors.users'));
    }
}
