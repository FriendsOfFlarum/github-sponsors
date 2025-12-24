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
use Illuminate\Support\Collection;

class GroupSynchronizer
{
    private SettingsRepositoryInterface $settings;
    private const MANAGED_USERS_KEY = 'fof-github-sponsors.users';

    public function __construct(SettingsRepositoryInterface $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Synchronize group memberships for sponsors.
     *
     * @param Group            $group         The group to synchronize
     * @param Collection<User> $sponsorUsers  Users who are sponsors
     * @param array<string>    $sponsorEmails Sponsor email addresses
     * @param array<int>       $sponsorIds    Sponsor GitHub database IDs
     *
     * @return array{added: Collection<User>, removed: Collection<User>}
     */
    public function synchronize(
        Group $group,
        Collection $sponsorUsers,
        array $sponsorEmails,
        array $sponsorIds
    ): array {
        $usersManaging = $this->getManagedUsers();

        // Remove group from users who are no longer sponsors
        $usersToRemove = $this->getUsersToRemove($group, $usersManaging, $sponsorEmails, $sponsorIds);
        $this->removeUsersFromGroup($group, $usersToRemove);

        // Update managed users list after removals
        foreach ($usersToRemove as $user) {
            $usersManaging = $usersManaging->reject(fn ($id) => $id == $user->id);
        }

        // Add group to users who should have it
        $usersToAdd = $this->addUsersToGroup($group, $sponsorUsers, $usersManaging);

        // Update managed users list after additions
        $usersManaging = $usersManaging->merge($usersToAdd->pluck('id'));
        $this->updateManagedUsers($usersManaging);

        return [
            'added'   => $usersToAdd,
            'removed' => $usersToRemove,
        ];
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
        return $group->users()
            ->leftJoin('login_providers', 'login_providers.user_id', '=', 'users.id')
            ->whereIn('users.id', $usersManaging)
            ->whereNotIn('users.email', $sponsorEmails)
            ->whereNotIn('login_providers.identifier', $sponsorIds)
            ->get();
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
     *
     * @return Collection<User> Users that were added
     */
    private function addUsersToGroup(Group $group, Collection $sponsorUsers, Collection $usersManaging): Collection
    {
        $usersAdded = collect();

        $sponsorUsers->each(function ($user) use ($group, &$usersAdded) {
            if (!$user->groups()->find($group->id)) {
                $user->groups()->attach($group->id);
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
