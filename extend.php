<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors;

use Flarum\Api\Resource\ForumResource;
use Flarum\Audit\Extend\Audit;
use Flarum\Extend;
use FoF\GitHubSponsors\Api\Controller\TestConnectionController;
use FoF\GitHubSponsors\Api\ForumAttributes;
use FoF\GitHubSponsors\Console\UpdateCommand;
use FoF\GitHubSponsors\Event\AbstractSponsorStatusChanged;
use FoF\GitHubSponsors\Event\OneTimeSponsorshipStarted;
use FoF\GitHubSponsors\Event\RecurringSponsorshipStarted;
use FoF\GitHubSponsors\Event\RecurringSponsorshipStopped;
use FoF\GitHubSponsors\Event\SponsorGroupsChanged;
use FoF\GitHubSponsors\Event\SponsorMembershipAdopted;
use FoF\GitHubSponsors\Event\SponsorshipEnded;
use FoF\GitHubSponsors\Providers\SupporterGroupsServiceProvider;
use Illuminate\Console\Scheduling\Event;

return [
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/resources/less/admin.less'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    (new Extend\ServiceProvider())
        ->register(SupporterGroupsServiceProvider::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(ForumAttributes::class),

    (new Extend\Routes('api'))
        ->post('/fof/github-sponsors/test-connection', 'fof-github-sponsors.test-connection', TestConnectionController::class),

    (new Extend\Console())
        ->command(UpdateCommand::class)
        ->schedule(UpdateCommand::class, function (Event $event) {
            $event->hourly()
                ->withoutOverlapping();
        }),

    (new Extend\Conditional())
        ->whenExtensionEnabled('flarum-audit', function () {
            // user_id and the group ID lists are the keys the Audit browser renders
            // as a username link and group badges, and that `user:` search matches.
            $status = fn (AbstractSponsorStatusChanged $event) => [
                'user_id'         => $event->user->id,
                'previous_status' => $event->previous?->value,
                'current_status'  => $event->current?->value,
                'old_group_ids'   => $event->oldGroupIds,
                'new_group_ids'   => $event->newGroupIds,
            ];

            return [
                (new Audit())
                    ->listen(RecurringSponsorshipStarted::class, 'fof_github_sponsors.recurring_started', $status)
                    ->listen(RecurringSponsorshipStopped::class, 'fof_github_sponsors.recurring_stopped', $status)
                    ->listen(OneTimeSponsorshipStarted::class, 'fof_github_sponsors.onetime_started', $status)
                    ->listen(SponsorshipEnded::class, 'fof_github_sponsors.sponsorship_ended', $status)
                    ->listen(SponsorGroupsChanged::class, 'fof_github_sponsors.groups_changed', fn (SponsorGroupsChanged $event) => [
                        'user_id'       => $event->user->id,
                        'old_group_ids' => $event->oldGroupIds,
                        'new_group_ids' => $event->newGroupIds,
                    ])
                    ->listen(SponsorMembershipAdopted::class, 'fof_github_sponsors.membership_adopted', fn (SponsorMembershipAdopted $event) => [
                        'user_id'       => $event->user->id,
                        'new_group_ids' => $event->groupIds,
                    ]),
            ];
        }),
];
