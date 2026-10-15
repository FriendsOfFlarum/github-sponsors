<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Services;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\LoginProvider;
use Flarum\User\User;
use FoF\GitHubSponsors\Event\OneTimeSponsorshipStarted;
use FoF\GitHubSponsors\Event\RecurringSponsorshipStarted;
use FoF\GitHubSponsors\Event\RecurringSponsorshipStopped;
use FoF\GitHubSponsors\Event\SponsorGroupsChanged;
use FoF\GitHubSponsors\Event\SponsorMembershipAdopted;
use FoF\GitHubSponsors\Event\SponsorshipEnded;
use FoF\GitHubSponsors\Sponsor;
use FoF\GitHubSponsors\SponsorStatus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

/**
 * Brings each matched user's sponsor status and group memberships in line with
 * GitHub Sponsors.
 *
 * Ownership: the sync owns a membership it added, or one it adopted because the
 * user's sponsorship justifies it (for example one added by hand). It only removes
 * memberships it owns, and only when no other supporter source (see
 * {@see SponsorGroupClaims}) still wants them. A supporter is never in both the
 * monthly and the one-time group: the monthly group wins.
 */
class SponsorSynchronizer
{
    /**
     * Set after the first applied sync. Until then statuses are recorded without
     * firing status events, so installing or upgrading does not announce every
     * existing sponsor as new.
     */
    public const BASELINE_SETTING = 'fof-github-sponsors.baseline_recorded_at';

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected Dispatcher $events,
        protected SponsorMatcher $matcher,
        protected Container $container
    ) {
    }

    public function isBaseline(): bool
    {
        return !$this->settings->get(self::BASELINE_SETTING);
    }

    /**
     * @param array<object> $sponsors One sponsorship per account, as returned by {@see \FoF\GitHubSponsors\Api\GitHubSponsorsClient::fetchSponsors()}
     *
     * @return Collection<int, SponsorChange> One entry per user whose status or groups changed, or whose membership was adopted
     */
    public function synchronize(array $sponsors, Group $recurringGroup, ?Group $onetimeGroup, bool $dryRun = false): Collection
    {
        $baseline = $this->isBaseline();
        $desired = $this->desiredStatuses($sponsors);
        $known = Sponsor::query()->get()->keyBy('user_id');
        $userIds = array_values(array_unique(array_merge(array_keys($desired), $known->keys()->map(fn ($id) => (int) $id)->all())));

        $users = User::query()->whereIn('id', $userIds)->with('groups')->get();

        $recurringId = (int) $recurringGroup->id;
        $onetimeId = $onetimeGroup ? (int) $onetimeGroup->id : null;
        $othersWant = [$recurringId => $this->othersWanting($userIds, $recurringId)];

        if ($onetimeId && $onetimeId !== $recurringId) {
            $othersWant[$onetimeId] = $this->othersWanting($userIds, $onetimeId);
        }

        $changes = new Collection();

        foreach ($users as $user) {
            /** @var Sponsor $sponsor */
            $sponsor = $known->get($user->id) ?? new Sponsor(['user_id' => $user->id]);
            [$current, $account] = $desired[$user->id] ?? [null, null];
            $change = $this->reconcile($user, $sponsor, $current, $account, $recurringId, $onetimeId, $othersWant, $dryRun, $baseline);

            if ($change) {
                $changes->push($change);
            }
        }

        if ($baseline && !$dryRun) {
            $this->settings->set(self::BASELINE_SETTING, Carbon::now()->toIso8601String());
        }

        return $changes;
    }

    /**
     * @param array<int, array<int>> $othersWant User IDs other sources want in each group, by group ID
     */
    protected function reconcile(
        User $user,
        Sponsor $sponsor,
        ?SponsorStatus $current,
        ?object $account,
        int $recurringId,
        ?int $onetimeId,
        array $othersWant,
        bool $dryRun,
        bool $baseline
    ): ?SponsorChange {
        $previous = $sponsor->status;
        $oldGroupIds = $user->groups->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $groups = array_fill_keys($oldGroupIds, true);
        $owned = array_fill_keys($sponsor->managedGroupIds(), true);
        $add = $remove = $adopt = [];
        $uid = (int) $user->id;

        $othersWantRecurring = in_array($uid, $othersWant[$recurringId] ?? [], true);
        $singleGroup = !$onetimeId || $onetimeId === $recurringId;
        $wantRecurring = $current === SponsorStatus::Recurring || ($onetimeId === $recurringId && $current !== null);

        // The monthly group.
        if ($wantRecurring) {
            if (!isset($groups[$recurringId])) {
                $add[] = $recurringId;
            } elseif (!isset($owned[$recurringId])) {
                $adopt[] = $recurringId;
            }
        } elseif (isset($groups[$recurringId], $owned[$recurringId]) && !$othersWantRecurring) {
            $remove[] = $recurringId;
        }

        // The one-time group, which a monthly supporter is never also in.
        if (!$singleGroup) {
            $inRecurringAfter = (isset($groups[$recurringId]) || in_array($recurringId, $add, true)) && !in_array($recurringId, $remove, true);
            $wantOnetime = $current === SponsorStatus::OneTime;
            $othersWantOnetime = in_array($uid, $othersWant[$onetimeId] ?? [], true);

            if ($inRecurringAfter) {
                // Left alone while another source still claims it: that source sees the
                // monthly membership and drops it itself, and removing it here could
                // fight a source that maps these groups the other way round.
                if (isset($groups[$onetimeId]) && !$othersWantOnetime) {
                    $remove[] = $onetimeId;
                }
            } elseif ($othersWantRecurring) {
                // Another source is about to add the monthly group; it removes this one then.
            } elseif ($wantOnetime) {
                if (!isset($groups[$onetimeId])) {
                    $add[] = $onetimeId;
                } elseif (!isset($owned[$onetimeId])) {
                    $adopt[] = $onetimeId;
                }
            } elseif (isset($groups[$onetimeId], $owned[$onetimeId]) && !$othersWantOnetime) {
                $remove[] = $onetimeId;
            }
        }

        $newGroupIds = array_values(array_diff(array_unique(array_merge($oldGroupIds, $add)), $remove));
        sort($newGroupIds);

        // Ownership of a membership that no longer exists, or that this source no
        // longer wants (another source still does, and adopts it), is dropped.
        $wanted = array_filter([$wantRecurring ? $recurringId : null, (!$singleGroup && $current === SponsorStatus::OneTime) ? $onetimeId : null]);
        $newOwned = array_values(array_intersect(array_unique(array_merge(array_keys($owned), $add, $adopt)), $newGroupIds, $wanted));
        sort($newOwned);
        $oldOwned = array_keys($owned);
        sort($oldOwned);

        $statusChanged = $previous !== $current;

        if (!$statusChanged && !$add && !$remove && !$adopt && $newOwned === $oldOwned) {
            return null;
        }

        if (!$dryRun) {
            $add && $user->groups()->attach($add);
            $remove && $user->groups()->detach($remove);

            if ($current === null && !$newOwned) {
                $sponsor->exists && $sponsor->delete();
            } else {
                $sponsor->status = $current;
                $sponsor->managed_group_ids = $newOwned;

                if ($statusChanged) {
                    $sponsor->status_changed_at = Carbon::now();
                }

                $sponsor->save();
            }

            if ($statusChanged && !$baseline) {
                $event = match (true) {
                    $current === SponsorStatus::Recurring                                         => RecurringSponsorshipStarted::class,
                    $previous === SponsorStatus::Recurring && $current === SponsorStatus::OneTime => RecurringSponsorshipStopped::class,
                    $current === SponsorStatus::OneTime                                           => OneTimeSponsorshipStarted::class,
                    default                                                                       => SponsorshipEnded::class,
                };

                $this->events->dispatch(new $event($user, $previous, $current, $account, $oldGroupIds, $newGroupIds));
            } elseif ($add || $remove) {
                $this->events->dispatch(new SponsorGroupsChanged($user, $oldGroupIds, $newGroupIds));
            }

            if ($adopt) {
                $this->events->dispatch(new SponsorMembershipAdopted($user, $adopt));
            }
        }

        // Dropping ownership alone is bookkeeping, not a change to the user.
        return ($statusChanged || $add || $remove || $adopt) ? new SponsorChange($user, $previous, $current, $add, $remove, $adopt) : null;
    }

    /**
     * User IDs that other supporter sources want in the group.
     *
     * @param array<int> $userIds
     *
     * @return array<int>
     */
    protected function othersWanting(array $userIds, int $groupId): array
    {
        $wanted = [];

        foreach ($this->container->tagged(SponsorGroupClaims::TAG) as $claims) {
            if ($claims instanceof SponsorGroupClaims || !is_callable($claims)) {
                continue;
            }

            array_push($wanted, ...array_map('intval', (array) $claims($userIds, $groupId)));
        }

        return array_values(array_unique($wanted));
    }

    /**
     * Each matched user's status per GitHub, with their GitHub account. An active
     * monthly sponsorship wins over a one-time or past one.
     *
     * @param array<object> $sponsors
     *
     * @return array<int, array{0: SponsorStatus, 1: object|null}>
     */
    protected function desiredStatuses(array $sponsors): array
    {
        $byGitHubId = [];
        $byEmail = [];

        foreach ($sponsors as $sponsor) {
            $account = $sponsor->sponsorEntity;
            $byGitHubId[(string) ($account->databaseId ?? '')] ??= $account;

            if (!empty($account->email)) {
                $byEmail[strtolower($account->email)] ??= $account;
            }
        }

        $categorized = $this->matcher->categorizeSponsors($sponsors);
        $matched = [];

        foreach ([[SponsorStatus::OneTime, $categorized['onetime']], [SponsorStatus::Recurring, $categorized['recurring']]] as [$status, $group]) {
            foreach ($this->matcher->matchSponsorsToUsers($group) as $user) {
                $matched[$user->id] = [$status, $user];
            }
        }

        $githubIds = LoginProvider::query()
            ->where('provider', 'github')
            ->whereIn('user_id', array_keys($matched))
            ->pluck('identifier', 'user_id');

        $desired = [];

        foreach ($matched as $userId => [$status, $user]) {
            $account = $byGitHubId[(string) ($githubIds[$userId] ?? '')] ?? $byEmail[strtolower($user->email)] ?? null;
            $desired[$userId] = [$status, $account];
        }

        return $desired;
    }
}
