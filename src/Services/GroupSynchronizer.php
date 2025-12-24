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
use Flarum\User\User;
use FoF\GitHubSponsors\Event\SponsorAdded;
use FoF\GitHubSponsors\Event\SponsorRemoved;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;

class GroupSynchronizer
{
    /**
     * @var SettingsRepositoryInterface
     */
    private $settings;

    /**
     * @var Dispatcher
     */
    private $events;

    private const MANAGED_USERS_KEY = 'fof-github-sponsors.users';

    public function __construct(SettingsRepositoryInterface $settings, Dispatcher $events)
    {
        $this->settings = $settings;
        $this->events = $events;
    }

    /**
     * Synchronize group memberships for sponsors.
     *
     * @param Group            $group         The group to synchronize
     * @param Collection<User> $sponsorUsers  Users who are sponsors
     * @param array<string>    $sponsorEmails Sponsor email addresses
     * @param array<int>       $sponsorIds    Sponsor GitHub database IDs
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
     * Find the GitHub sponsor data for a given Flarum user.
     *
     * @param User  $user
     * @param array $sponsors
     *
     * @return object|null
     */
    private function findSponsorDataForUser(User $user, array $sponsors): ?object
    {
        foreach ($sponsors as $sponsor) {
            $sponsorData = $sponsor->sponsor ?? $sponsor;
            $email = $sponsorData->email ?? null;
            $id = $sponsorData->databaseId ?? null;

            // Match by email
            if ($email && $user->email === $email) {
                return $sponsorData;
            }

            // Match by GitHub OAuth
            if ($id) {
                $hasMatchingProvider = $user->loginProviders()
                    ->where('provider', 'github')
                    ->where('identifier', $id)
                    ->exists();

                if ($hasMatchingProvider) {
                    return $sponsorData;
                }
            }
        }

        return null;
    }

    /**
     * Get users who should be removed from the group.
     *
     * @param Group         $group
     * @param Collection    $usersManaging
     * @param array<string> $sponsorEmails
     * @param array<int>    $sponsorIds
     *
     * @return Collection<User>
     */
    private function getUsersToRemove(
        Group $group,
        Collection $usersManaging,
        array $sponsorEmails,
        array $sponsorIds
    ): Collection {
        // Get all users in the group that are managed by this extension
        $managedGroupUsers = $group->users()
            ->whereIn('users.id', $usersManaging)
            ->get();

        // Filter out users who are still sponsors (matched by email OR GitHub OAuth)
        return $managedGroupUsers->filter(function ($user) use ($sponsorEmails, $sponsorIds) {
            // Check if user is matched by email
            if (in_array($user->email, $sponsorEmails)) {
                return false; // Keep this user (don't remove)
            }

            // Check if user is matched by GitHub OAuth
            $hasMatchingGithubProvider = $user->loginProviders()
                ->where('provider', 'github')
                ->whereIn('identifier', $sponsorIds)
                ->exists();

            if ($hasMatchingGithubProvider) {
                return false; // Keep this user (don't remove)
            }

            return true; // Remove this user
        });
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
        return collect(json_decode($this->settings->get(self::MANAGED_USERS_KEY, '[]')));
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
