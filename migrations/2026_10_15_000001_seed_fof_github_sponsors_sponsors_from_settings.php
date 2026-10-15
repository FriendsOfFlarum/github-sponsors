<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Illuminate\Database\Schema\Builder;

/*
 * Moves the managed-user list from settings into the sponsors table. Status is
 * left null: the first sync afterwards records it without firing events.
 */
return [
    'up' => function (Builder $schema) {
        $db = $schema->getConnection();
        $setting = fn (string $key) => $db->table('settings')->where('key', $key)->value('value');

        $managed = [];

        foreach (['fof-github-sponsors.users' => 'fof-github-sponsors.group_id'] as $listKey => $groupKey) {
            $groupId = (int) $setting($groupKey);

            if (!$groupId) {
                continue;
            }

            foreach ((array) json_decode($setting($listKey) ?? '[]') as $userId) {
                $managed[(int) $userId][] = $groupId;
            }
        }

        $existing = $db->table('users')->whereIn('id', array_keys($managed))->pluck('id')->all();

        foreach ($existing as $userId) {
            $db->table('fof_github_sponsors_sponsors')->insert([
                'user_id'           => $userId,
                'status'            => null,
                'managed_group_ids' => json_encode(array_values(array_unique($managed[$userId]))),
            ]);
        }

        $db->table('settings')->where('key', 'fof-github-sponsors.users')->delete();
    },

    'down' => function (Builder $schema) {
        $db = $schema->getConnection();
        $groupIds = [
            'fof-github-sponsors.users' => (int) $db->table('settings')->where('key', 'fof-github-sponsors.group_id')->value('value'),
        ];
        $rows = $db->table('fof_github_sponsors_sponsors')->get(['user_id', 'managed_group_ids']);

        foreach ($groupIds as $listKey => $groupId) {
            $userIds = $rows->filter(fn ($row) => in_array($groupId, json_decode($row->managed_group_ids ?? '[]', true), true))->pluck('user_id')->values();
            $db->table('settings')->updateOrInsert(['key' => $listKey], ['value' => $userIds->toJson()]);
        }
    },
];
