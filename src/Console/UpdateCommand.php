<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Console;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\GitHubSponsors\Api\GitHubSponsorsClient;
use FoF\GitHubSponsors\Services\GroupSynchronizer;
use FoF\GitHubSponsors\Services\SponsorMatcher;
use Illuminate\Console\Command;
use UnexpectedValueException;

class UpdateCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'fof:github-sponsors:update';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Update groups of GitHub sponsors.';

    protected $prefix;

    private SettingsRepositoryInterface $settings;
    private GitHubSponsorsClient $client;
    private SponsorMatcher $matcher;
    private GroupSynchronizer $synchronizer;

    public function __construct(
        SettingsRepositoryInterface $settings,
        GitHubSponsorsClient $client,
        SponsorMatcher $matcher,
        GroupSynchronizer $synchronizer
    ) {
        parent::__construct();

        $this->settings = $settings;
        $this->client = $client;
        $this->matcher = $matcher;
        $this->synchronizer = $synchronizer;
        $this->prefix = Carbon::now()->format('M d, Y @ h:m A');
    }

    public function handle()
    {
        $this->line('');

        // Load and validate settings
        $apiToken = $this->settings->get('fof-github-sponsors.api_token');
        $accountType = $this->settings->get('fof-github-sponsors.account_type');
        $login = strtolower($this->settings->get('fof-github-sponsors.login'));
        $groupId = $this->settings->get('fof-github-sponsors.group_id');

        $this->validateSettings($apiToken, $accountType, $login, $groupId);

        $group = Group::find((int) $groupId);

        $this->info('Retrieving GitHub sponsors...');

        // Fetch sponsors from GitHub
        $result = $this->client->fetchSponsors($apiToken, $accountType, $login);
        $sponsors = $result['sponsors'];
        $maintainerName = $result['maintainer'];

        $this->info("|> ".count($sponsors)." sponsors of {$maintainerName} ($accountType)");

        // Match sponsors to Flarum users
        $sponsorUsers = $this->matcher->matchSponsorsToUsers($sponsors);
        $this->info("|> -> {$sponsorUsers->count()} registered");

        // Synchronize group memberships
        $this->info('Applying group changes...');

        $changes = $this->synchronizer->synchronize(
            $group,
            $sponsorUsers,
            $this->matcher->getSponsorEmails($sponsors)->all(),
            $this->matcher->getSponsorIds($sponsors)
        );

        $this->outputUsers($changes['removed'], '-');
        $this->outputUsers($changes['added'], '+');

        $this->info('Done.');
    }

    /**
     * Validate configuration settings.
     *
     * @throws UnexpectedValueException
     */
    private function validateSettings(?string $apiToken, ?string $accountType, ?string $login, ?string $groupId): void
    {
        if (!isset($apiToken) || empty($apiToken)) {
            throw new UnexpectedValueException('GitHub API key must be provided');
        }

        if ($accountType != 'user' && $accountType != 'organization') {
            throw new UnexpectedValueException('Account type must be provided');
        }

        if (empty($login)) {
            throw new UnexpectedValueException('User or organization login must be provided');
        }

        $group = isset($groupId) ? Group::find((int) $groupId) : null;
        if (!isset($group)) {
            throw new UnexpectedValueException("Invalid group ID: '$groupId'");
        }
    }

    protected function outputUsers($users, $prefix)
    {
        foreach ($users as $user) {
            $this->outputUser($user, $prefix);
        }
    }

    protected function outputUser($user, $prefix)
    {
        $this->info("|> $prefix #{$user->id} {$user->username}");
    }

    public function info($string, $verbosity = null)
    {
        parent::info($this->prefix.' | '.$string, $verbosity);
    }
}
