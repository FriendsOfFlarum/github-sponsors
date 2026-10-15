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

use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\LoginProvider;
use Flarum\User\User;
use FoF\GitHubSponsors\Event\SponsorAdded;
use FoF\GitHubSponsors\Event\SponsorRemoved;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

class GroupSynchronizer
{
    private const MANAGED_USERS_KEY = 'fof-github-sponsors.users';

    public function __construct(private SettingsRepositoryInterface $settings, private Dispatcher $events)
    {
    }

    /**
     * Synchronize group memberships for sponsors.
     *
     * @param Group            $group         The group to synchronize
     * @param Collection<User> $sponsorUsers  Users who are sponsors
     * @param array<string>    $sponsorEmails Sponsor email addresses
     * @param array<string>    $sponsorIds    Sponsor GitHub account IDs, as strings (see SponsorMatcher::getSponsorIds())
     * @param bool             $dryRun        If true, no changes will be made
     * @param array            $sponsors      Raw sponsor data from GitHub API
     *
     * @return array{added: Collection<User>, removed: Collection<User>}
     */
    public function synchronize(
        Group $group,
        Collection $sponsorUsers,
        array $sponsorEmails,
        array $sponsorIds,
        bool $dryRun = false,
        array $sponsors = []
    ): array {
        $usersManaging = $this->getManagedUsers();

        // Remove group from users who are no longer sponsors
        $usersToRemove = $this->getUsersToRemove($group, $usersManaging, $sponsorEmails, $sponsorIds);

        if (!$dryRun) {
            $this->removeUsersFromGroup($group, $usersToRemove);

            // Dispatch SponsorRemoved events
            foreach ($usersToRemove as $user) {
                $this->events->dispatch(new SponsorRemoved($user));
            }
        }

        // Update managed users list after removals
        foreach ($usersToRemove as $user) {
            $usersManaging = $usersManaging->reject(function ($id) use ($user) {
                return $id == $user->id;
            });
        }

        // Add group to users who should have it
        $usersToAdd = $this->addUsersToGroup($group, $sponsorUsers, $usersManaging, $dryRun);

        if (!$dryRun) {
            // Dispatch SponsorAdded events with GitHub data
            foreach ($usersToAdd as $user) {
                $sponsorData = $this->findSponsorDataForUser($user, $sponsors);
                $this->events->dispatch(new SponsorAdded($user, $sponsorData));
            }

            // Update managed users list after additions
            $usersManaging = $usersManaging->merge($usersToAdd->pluck('id'));
            $this->updateManagedUsers($usersManaging);
        }

        return [
            'added'   => $usersToAdd,
            'removed' => $usersToRemove,
        ];
    }

    /**
     * The sponsor's GitHub account (databaseId, login, email) for a matched user.
     *
     * @param array<object> $sponsors
     */
    protected function findSponsorDataForUser(User $user, array $sponsors): ?object
    {
        $githubIds = $user->loginProviders()->where('provider', 'github')->pluck('identifier')->map(fn ($id) => (string) $id)->all();

        foreach ($sponsors as $sponsor) {
            $account = $sponsor->sponsorEntity ?? null;

            if (!$account) {
                continue;
            }

            if (in_array((string) ($account->databaseId ?? ''), $githubIds, true)
                || (!empty($account->email) && strtolower($account->email) === strtolower($user->email))) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Managed members of the group who no longer match any sponsor.
     *
     * @param array<string> $sponsorEmails
     * @param array<string> $sponsorIds    GitHub account IDs
     *
     * @return Collection<int, User>
     */
    protected function getUsersToRemove(Group $group, Collection $usersManaging, array $sponsorEmails, array $sponsorIds): Collection
    {
        $sponsorEmails = array_map('strtolower', $sponsorEmails);
        $linkedToSponsor = $sponsorIds
            ? LoginProvider::query()->where('provider', 'github')->whereIn('identifier', array_map('strval', $sponsorIds))->pluck('user_id')->map(fn ($id) => (int) $id)->all()
            : [];

        return $group->users()
            ->whereIn('users.id', $usersManaging)
            ->get()
            ->reject(fn (User $user) => in_array((int) $user->id, $linkedToSponsor, true) || in_array(strtolower($user->email), $sponsorEmails, true));
    }

    /**
     * Remove users from the group.
     *
     * @param Group            $group
     * @param Collection<User> $users
     */
    private function removeUsersFromGroup(Group $group, Collection $users): void
    {
        $group->users()->detach($users->pluck('id'));
    }

    /**
     * Add users to the group if they don't already have it.
     *
     * @param Group            $group
     * @param Collection<User> $sponsorUsers
     * @param Collection       $usersManaging
     * @param bool             $dryRun        If true, no changes will be made
     *
     * @return Collection<User> Users that were added
     */
    private function addUsersToGroup(Group $group, Collection $sponsorUsers, Collection $usersManaging, bool $dryRun = false): Collection
    {
        $usersAdded = collect();

        $sponsorUsers->each(function ($user) use ($group, &$usersAdded, $dryRun) {
            if (!$user->groups()->find($group->id)) {
                if (!$dryRun) {
                    $user->groups()->attach($group->id);
                }
                $usersAdded->push($user);
            }
        });

        return $usersAdded;
    }

    /**
     * Get the list of users currently managed by this extension.
     *
     * @return Collection<int>
     */
    public function getManagedUsers(): Collection
    {
        return collect((array) json_decode($this->settings->get(self::MANAGED_USERS_KEY, '[]')));
    }

    /**
     * Update the list of users managed by this extension.
     *
     * @param Collection<int> $users
     */
    private function updateManagedUsers(Collection $users): void
    {
        $this->settings->set(self::MANAGED_USERS_KEY, $users->values()->unique()->toJson());
    }
}
