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
use GuzzleHttp\Exception\RequestException;
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
    protected $signature = 'fof:github-sponsors:update {--dry-run : Show what changes would be made without applying them}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Update groups of GitHub sponsors.';

    protected $prefix;

    public function __construct(
        private SettingsRepositoryInterface $settings,
        private GitHubSponsorsClient $client,
        private SponsorMatcher $matcher,
        private GroupSynchronizer $synchronizer,
        private LoggerInterface $logger
    ) {
        parent::__construct();
        $this->prefix = Carbon::now()->format('M d, Y @ h:m A');
    }

    public function handle()
    {
        $this->line('');

        $dryRun = $this->option('dry-run');
        $verbose = $this->getOutput()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        try {
            // Load and validate settings
            $apiToken = $this->settings->get('fof-github-sponsors.api_token');
            $accountType = $this->settings->get('fof-github-sponsors.account_type');
            $login = strtolower($this->settings->get('fof-github-sponsors.login'));
            $groupId = $this->settings->get('fof-github-sponsors.group_id');

            $this->validateSettings($apiToken, $accountType, $login, $groupId);

            $group = Group::find((int) $groupId);

            if ($verbose) {
                $this->info('Configuration:');
                $this->info("|> Account type: $accountType");
                $this->info("|> Login: $login");
                $this->info("|> Target group: {$group->name_singular} (ID: {$group->id})");
                $this->line('');
            }

            $this->info('Retrieving GitHub sponsors...');

            // Fetch sponsors from GitHub
            $result = $this->client->fetchSponsors($apiToken, $accountType, $login);
            $sponsors = $result['sponsors'];
            $maintainerName = $result['maintainer'];

            $this->info('|> '.count($sponsors)." sponsors of {$maintainerName} ($accountType)");

            if ($verbose && count($sponsors) > 0) {
                $this->line('');
                $this->info('Sponsor details from GitHub:');
                foreach ($sponsors as $sponsor) {
                    $sponsorData = $sponsor->sponsor ?? $sponsor;
                    $email = $sponsorData->email ?? 'no email';
                    $id = $sponsorData->databaseId ?? 'no ID';
                    $this->info("|> GitHub ID: $id | Email: $email");
                }
                $this->line('');
            }

            // Match sponsors to Flarum users
            $sponsorUsers = $this->matcher->matchSponsorsToUsers($sponsors);
            $registeredCount = $sponsorUsers->count();
            $unregisteredCount = count($sponsors) - $registeredCount;

            $this->info("|> -> {$registeredCount} registered, {$unregisteredCount} not registered");

            if ($verbose) {
                if ($registeredCount > 0) {
                    $this->line('');
                    $this->info('Matched Flarum users:');

                    // Get sponsor data for matching
                    $sponsorEmails = $this->matcher->getSponsorEmails($sponsors)->all();
                    $sponsorIds = $this->matcher->getSponsorIds($sponsors);

                    foreach ($sponsorUsers as $user) {
                        $matchMethods = [];

                        // Check if matched by email
                        if (in_array($user->email, $sponsorEmails)) {
                            $matchMethods[] = 'email';
                        }

                        // Check if matched by GitHub OAuth provider
                        $githubProvider = $user->loginProviders()
                            ->where('provider', 'github')
                            ->whereIn('identifier', $sponsorIds)
                            ->first();

                        if ($githubProvider) {
                            $matchMethods[] = "GitHub OAuth (ID: {$githubProvider->identifier})";
                        }

                        $matchInfo = !empty($matchMethods) ? ' [matched by: '.implode(', ', $matchMethods).']' : '';
                        $this->info("|> #{$user->id} {$user->username} ({$user->email}){$matchInfo}");
                    }
                    $this->line('');
                }

                if ($unregisteredCount > 0) {
                    $this->info('Unmatched sponsors (not registered on Flarum):');
                    $matchedEmails = $sponsorUsers->pluck('email')->all();
                    $matchedGithubIds = $sponsorUsers->flatMap(function ($user) {
                        return $user->loginProviders()
                            ->where('provider', 'github')
                            ->pluck('identifier');
                    })->all();

                    foreach ($sponsors as $sponsor) {
                        $sponsorData = $sponsor->sponsor ?? $sponsor;
                        $email = $sponsorData->email ?? null;
                        $id = $sponsorData->databaseId ?? null;

                        // Check if this sponsor was matched
                        $wasMatched = false;
                        if ($email && in_array($email, $matchedEmails)) {
                            $wasMatched = true;
                        }
                        if ($id && in_array($id, $matchedGithubIds)) {
                            $wasMatched = true;
                        }

                        if (!$wasMatched) {
                            $emailDisplay = $email ?: 'no email provided';
                            $idDisplay = $id ?: 'no ID';
                            $reason = !$email ? ' (no email to match)' : ' (no matching Flarum user found)';
                            $this->info("|> GitHub ID: $idDisplay | Email: $emailDisplay{$reason}");
                        }
                    }
                    $this->line('');
                }
            }

            // Synchronize group memberships
            $this->info($dryRun ? 'Calculating group changes...' : 'Applying group changes...');

            $changes = $this->synchronizer->synchronize(
                $group,
                $sponsorUsers,
                $this->matcher->getSponsorEmails($sponsors)->all(),
                $this->matcher->getSponsorIds($sponsors),
                $dryRun,
                $sponsors
            );

            // Calculate users staying in the group (active sponsors already in group)
            $usersStaying = $sponsorUsers->filter(function ($user) use ($group) {
                return $user->groups()->find($group->id) !== null;
            });

            if ($verbose) {
                $this->line('');
                $this->info('Summary:');
                $this->info("|> Users staying in group: {$usersStaying->count()}");
                $this->info("|> Users to remove: {$changes['removed']->count()}");
                $this->info("|> Users to add: {$changes['added']->count()}");
                $this->line('');
            }

            if ($verbose && $usersStaying->count() > 0) {
                $this->info('Users staying in group (active sponsors):');
                $this->outputUsers($usersStaying, '=');
            }

            if ($changes['removed']->count() > 0) {
                if ($verbose) {
                    $this->info('Removing users from group:');
                }
                $this->outputUsers($changes['removed'], '-');
            }

            if ($changes['added']->count() > 0) {
                if ($verbose) {
                    $this->info('Adding users to group:');
                }
                $this->outputUsers($changes['added'], '+');
            }

            if ($changes['removed']->count() === 0 && $changes['added']->count() === 0) {
                $this->info('No changes needed.');
            }

            $this->info('Done.');
        } catch (RequestException $e) {
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
     * Handle GitHub API errors gracefully.
     */
    private function handleApiError(RequestException $e): void
    {
        $response = $e->getResponse();
        $statusCode = $response ? $response->getStatusCode() : null;

        $message = 'GitHub API error';

        if ($statusCode === 401) {
            $message = 'GitHub API authentication failed. Please check your API token.';
            $this->error($message);
            $this->line('');
            $this->line('To fix this:');
            $this->line('1. Generate a new Personal Access Token at: https://github.com/settings/tokens');
            $this->line('2. Ensure the token has the "read:user" and "read:org" scopes');
            $this->line('3. Update the token in your admin settings');
        } elseif ($statusCode === 403) {
            $message = 'GitHub API rate limit exceeded or insufficient permissions.';
            $this->error($message);
        } elseif ($statusCode === 404) {
            $message = 'GitHub user or organization not found.';
            $this->error($message);
        } else {
            $this->error('GitHub API request failed: '.$e->getMessage());
        }

        $this->logger->error('[fof/github-sponsors] '.$message, [
            'status_code' => $statusCode,
            'exception'   => $e->getMessage(),
        ]);
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

    public function error($string, $verbosity = null)
    {
        parent::error($this->prefix.' | '.$string, $verbosity);
    }

    public function line($string, $style = null, $verbosity = null)
    {
        if ($string !== '') {
            parent::line($this->prefix.' | '.$string, $style, $verbosity);
        } else {
            parent::line($string, $style, $verbosity);
        }
    }
}
