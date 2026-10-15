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
use FoF\GitHubSponsors\Api\GitHubSponsorsException;
use FoF\GitHubSponsors\Services\GroupSynchronizer;
use FoF\GitHubSponsors\Services\SponsorMatcher;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use UnexpectedValueException;

class UpdateCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'fof:github-sponsors:update
        {--dry-run : Show what changes would be made without applying them}
        {--force : Apply changes even when GitHub reports no sponsors at all}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Update groups of GitHub sponsors.';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected GitHubSponsorsClient $client,
        protected SponsorMatcher $matcher,
        protected GroupSynchronizer $synchronizer,
        protected LoggerInterface $logger
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->line('');

        $dryRun = (bool) $this->option('dry-run');
        $verbose = $this->getOutput()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        try {
            $apiToken = $this->settings->get('fof-github-sponsors.api_token');
            $accountType = $this->settings->get('fof-github-sponsors.account_type');
            $login = strtolower((string) $this->settings->get('fof-github-sponsors.login'));
            $groupId = $this->settings->get('fof-github-sponsors.group_id');

            $this->validateSettings($apiToken, $accountType, $login, $groupId);

            $group = Group::findOrFail((int) $groupId);

            if ($verbose) {
                $this->info('Configuration:');
                $this->info("|> Account type: $accountType");
                $this->info("|> Login: $login");
                $this->info("|> Target group: {$group->name_singular} (ID: {$group->id})");
                $this->line('');
            }

            $this->info('Retrieving GitHub sponsors...');

            $result = $this->client->fetchSponsors($apiToken, $accountType, $login);
            $sponsors = $result['sponsors'];

            $this->info('|> '.count($sponsors)." sponsors of {$result['maintainer']} ($accountType)");

            // An empty result removes every managed member in one run. That is right
            // for an account that truly lost all its sponsors, but far more often it
            // means the API answered oddly, so it needs an explicit --force.
            if (count($sponsors) === 0 && !$this->option('force') && $this->synchronizer->getManagedUsers()->isNotEmpty()) {
                $message = 'GitHub returned no sponsors, which would remove every managed group member. No changes made; re-run with --force if this is correct.';
                $this->error($message);
                $this->logger->error('[fof/github-sponsors] '.$message);

                return 1;
            }

            if ($verbose && count($sponsors) > 0) {
                $this->line('');
                $this->info('Sponsors on GitHub:');

                foreach ($sponsors as $sponsor) {
                    $account = $sponsor->sponsorEntity;
                    $email = ($account->email ?? '') ?: 'no public email';
                    $this->info("|> {$account->login} ({$account->__typename}, ID {$account->databaseId}) | $email");
                }

                $this->line('');
            }

            $sponsorUsers = $this->matcher->matchSponsorsToUsers($sponsors);
            $unmatched = count($sponsors) - $sponsorUsers->count();

            $this->info("|> -> {$sponsorUsers->count()} registered, ".max(0, $unmatched).' not registered');

            if ($verbose && $sponsorUsers->isNotEmpty()) {
                $this->line('');
                $this->info('Matched Flarum users:');
                $this->outputUsers($sponsorUsers, '=');
                $this->line('');
            }

            $this->info($dryRun ? 'Calculating group changes...' : 'Applying group changes...');

            $changes = $this->synchronizer->synchronize(
                $group,
                $sponsorUsers,
                $this->matcher->getSponsorEmails($sponsors)->all(),
                $this->matcher->getSponsorIds($sponsors),
                $dryRun,
                $sponsors
            );

            if ($changes['removed']->count() > 0) {
                $verbose && $this->info('Removing users from group:');
                $this->outputUsers($changes['removed'], '-');
            }

            if ($changes['added']->count() > 0) {
                $verbose && $this->info('Adding users to group:');
                $this->outputUsers($changes['added'], '+');
            }

            if ($changes['removed']->count() === 0 && $changes['added']->count() === 0) {
                $this->info('No changes needed.');
            }

            $this->info('Done.');
        } catch (GitHubSponsorsException $e) {
            $this->handleApiError($e);

            return 1;
        } catch (UnexpectedValueException $e) {
            $this->error($e->getMessage());
            $this->logger->error('[fof/github-sponsors] Configuration error: '.$e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error('An unexpected error occurred: '.$e->getMessage());
            $this->logger->error('[fof/github-sponsors] Unexpected error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return 1;
        }

        return 0;
    }

    /**
     * Explain a GitHub failure in terms an admin can act on.
     */
    protected function handleApiError(GitHubSponsorsException $e): void
    {
        $message = match ($e->kind) {
            GitHubSponsorsException::UNAUTHORIZED     => 'GitHub rejected the token. It may have expired or been revoked.',
            GitHubSponsorsException::FORBIDDEN        => 'The GitHub token is missing a required scope or permission.',
            GitHubSponsorsException::NOT_FOUND        => 'GitHub user or organization not found. Check the account type and login.',
            GitHubSponsorsException::RATE_LIMITED     => 'GitHub rate limit exceeded. The next run will try again.',
            GitHubSponsorsException::NETWORK          => 'Could not reach GitHub.',
            GitHubSponsorsException::INVALID_RESPONSE => 'GitHub returned an unexpected response.',
            default                                   => 'GitHub API request failed.',
        };

        $this->error($message.' ('.$e->getMessage().')');

        if (in_array($e->kind, [GitHubSponsorsException::UNAUTHORIZED, GitHubSponsorsException::FORBIDDEN], true)) {
            $this->line('');
            $this->line('To fix this:');
            $this->line('1. Create a personal access token at https://github.com/settings/tokens with the "read:user", "user:email" and "read:org" scopes');
            $this->line('2. Update the token in your admin settings');
        }

        $this->logger->error('[fof/github-sponsors] '.$message, [
            'kind'      => $e->kind,
            'exception' => $e->getMessage(),
        ]);
    }

    /**
     * @throws UnexpectedValueException
     */
    protected function validateSettings(?string $apiToken, ?string $accountType, ?string $login, ?string $groupId): void
    {
        if (empty($apiToken)) {
            throw new UnexpectedValueException('GitHub API token must be provided');
        }

        if (!in_array($accountType, GitHubSponsorsClient::ACCOUNT_TYPES, true)) {
            throw new UnexpectedValueException('Account type must be "user" or "organization"');
        }

        if (empty($login)) {
            throw new UnexpectedValueException('User or organization login must be provided');
        }

        if (!$groupId || !Group::find((int) $groupId)) {
            throw new UnexpectedValueException("Invalid group ID: '$groupId'");
        }
    }

    /**
     * @param iterable<\Flarum\User\User> $users
     */
    protected function outputUsers(iterable $users, string $prefix): void
    {
        foreach ($users as $user) {
            $this->info("|> $prefix #{$user->id} {$user->username}");
        }
    }

    /**
     * Prefix every non-empty line with the current time, so the appended scheduler
     * log shows when each run happened. info(), warn() and error() all route here.
     */
    public function line($string, $style = null, $verbosity = null)
    {
        if ($string !== '') {
            $string = Carbon::now()->format('M d, Y @ h:i A').' | '.$string;
        }

        parent::line($string, $style, $verbosity);
    }
}
