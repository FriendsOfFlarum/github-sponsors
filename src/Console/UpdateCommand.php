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
use FoF\GitHubSponsors\Services\SponsorChange;
use FoF\GitHubSponsors\Services\SponsorMatcher;
use FoF\GitHubSponsors\Services\SponsorSynchronizer;
use FoF\GitHubSponsors\Services\SupporterCounts;
use FoF\GitHubSponsors\Services\SyncStatus;
use FoF\GitHubSponsors\Sponsor;
use FoF\GitHubSponsors\SponsorStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
        protected SponsorSynchronizer $synchronizer,
        protected SyncStatus $status,
        protected SupporterCounts $counts,
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
            $recurringGroupId = $this->settings->get('fof-github-sponsors.group_id');
            $onetimeGroupId = $this->settings->get('fof-github-sponsors.onetime_group_id');

            $this->validateSettings($apiToken, $accountType, $login, $recurringGroupId);

            $recurringGroup = Group::findOrFail((int) $recurringGroupId);
            $onetimeGroup = $onetimeGroupId ? Group::find((int) $onetimeGroupId) : null;

            if ($onetimeGroupId && !$onetimeGroup) {
                throw new UnexpectedValueException("Invalid one-time group ID: '$onetimeGroupId'");
            }

            if ($verbose) {
                $this->info('Configuration:');
                $this->info("|> Account type: $accountType");
                $this->info("|> Login: $login");
                $this->info("|> Monthly sponsors group: {$recurringGroup->name_singular} (ID: {$recurringGroup->id})");
                $this->info('|> One-time and past sponsors group: '.($onetimeGroup ? "{$onetimeGroup->name_singular} (ID: {$onetimeGroup->id})" : 'Not configured'));
                $this->line('');
            }

            $this->info('Retrieving GitHub sponsors...');

            $result = $this->client->fetchSponsors($apiToken, $accountType, $login);
            $sponsors = $result['sponsors'];

            $this->info('|> '.count($sponsors)." sponsors of {$result['maintainer']} ($accountType)");

            // An empty result ends every known sponsorship in one run. That is right
            // for an account that truly lost all its sponsors, but far more often it
            // means the API answered oddly, so it needs an explicit --force.
            if (count($sponsors) === 0 && !$this->option('force') && Sponsor::query()->exists()) {
                $message = 'GitHub returned no sponsors, which would end every known sponsorship. No changes made; re-run with --force if this is correct.';
                $this->error($message);
                $this->logger->error('[fof/github-sponsors] '.$message);
                $dryRun || $this->status->recordFailure(SyncStatus::NO_SPONSORS, $message);

                return 1;
            }

            $categorized = $this->matcher->categorizeSponsors($sponsors);
            $this->info('|> '.count($categorized['recurring']).' active monthly sponsors');
            $this->info('|> '.count($categorized['onetime']).' one-time or past sponsors');

            if ($verbose && count($sponsors) > 0) {
                $this->line('');
                $this->info('Sponsors on GitHub:');

                foreach ($sponsors as $sponsor) {
                    $account = $sponsor->sponsorEntity;
                    $email = ($account->email ?? '') ?: 'no public email';
                    $type = GitHubSponsorsClient::isActiveRecurring($sponsor) ? 'monthly' : 'one-time or past';
                    $this->info("|> {$account->login} ({$account->__typename}, ID {$account->databaseId}) | $email [$type]");
                }

                $this->line('');
            }

            if ($this->synchronizer->isBaseline()) {
                $this->info('First sync: recording current sponsor statuses without firing sponsor events.');
            }

            $this->info($dryRun ? 'Calculating changes...' : 'Applying changes...');

            $changes = $this->synchronizer->synchronize($sponsors, $recurringGroup, $onetimeGroup, $dryRun);
            $groupNames = Group::query()
                ->whereIn('id', $changes->flatMap(fn (SponsorChange $change) => [...$change->addedGroupIds, ...$change->removedGroupIds, ...$change->adoptedGroupIds])->unique()->values())
                ->pluck('name_singular', 'id');

            foreach ($changes as $change) {
                $this->info($this->describe($change, $groupNames));
            }

            $this->info($changes->isEmpty() ? 'No changes needed.' : $changes->count().' users '.($dryRun ? 'would change' : 'changed').'.');
            $this->recordCounts($apiToken, $accountType, $login, $sponsors, $dryRun);

            $this->info('Done.');
            $dryRun || $this->status->recordSuccess(count($sponsors), $changes->count());
        } catch (GitHubSponsorsException $e) {
            $this->handleApiError($e);
            $dryRun || $this->status->recordFailure($e->kind, $e->getMessage());

            return 1;
        } catch (UnexpectedValueException $e) {
            $this->error($e->getMessage());
            $this->logger->error('[fof/github-sponsors] Configuration error: '.$e->getMessage());
            $dryRun || $this->status->recordFailure(SyncStatus::CONFIGURATION, $e->getMessage());

            return 1;
        } catch (Throwable $e) {
            $this->error('An unexpected error occurred: '.$e->getMessage());
            $this->logger->error('[fof/github-sponsors] Unexpected error: '.$e->getMessage(), [
                'exception' => $e,
            ]);
            $dryRun || $this->status->recordFailure(SyncStatus::UNEXPECTED, $e->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * Count the sponsors a supporters page cannot list by name. A failed private
     * count keeps the previous one rather than failing the sync.
     *
     * @param array<object> $sponsors
     */
    protected function recordCounts(string $apiToken, string $accountType, string $login, array $sponsors, bool $dryRun): void
    {
        $unmatched = $this->matcher->countUnmatched($sponsors);

        try {
            $private = $this->client->countPrivateSponsors($apiToken, $accountType, $login, $sponsors);
        } catch (GitHubSponsorsException $e) {
            $private = null;
            $this->warn('Could not count private sponsors: '.$e->getMessage());
            $this->logger->warning('[fof/github-sponsors] Could not count private sponsors: '.$e->getMessage());
        }

        $this->info('|> private sponsors: '.($private ? "{$private['recurring']} monthly, {$private['onetime']} one-time or past" : 'unknown'));
        $this->info("|> without a forum account: {$unmatched['recurring']} monthly, {$unmatched['onetime']} one-time or past");

        $dryRun || $this->counts->record($private, $unmatched);
    }

    /**
     * @param Collection<int, string> $groupNames Group names by ID
     */
    protected function describe(SponsorChange $change, Collection $groupNames): string
    {
        $parts = [];

        if ($change->statusChanged()) {
            $parts[] = $this->statusLabel($change->previous).' → '.$this->statusLabel($change->current);
        }

        foreach ($change->addedGroupIds as $id) {
            $parts[] = '+'.($groupNames[$id] ?? "group #$id");
        }

        foreach ($change->removedGroupIds as $id) {
            $parts[] = '-'.($groupNames[$id] ?? "group #$id");
        }

        foreach ($change->adoptedGroupIds as $id) {
            $parts[] = 'took over '.($groupNames[$id] ?? "group #$id");
        }

        return "|> #{$change->user->id} {$change->user->username}: ".implode(', ', $parts);
    }

    protected function statusLabel(?SponsorStatus $status): string
    {
        return match ($status) {
            SponsorStatus::Recurring => 'monthly',
            SponsorStatus::OneTime   => 'one-time or past',
            null                     => 'none',
        };
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
     * Prefix every non-empty line with the current time, so a captured run shows
     * when it happened. info(), warn() and error() all route here.
     */
    public function line($string, $style = null, $verbosity = null)
    {
        if ($string !== '') {
            $string = Carbon::now()->format('M d, Y @ h:i A').' | '.$string;
        }

        parent::line($string, $style, $verbosity);
    }
}
