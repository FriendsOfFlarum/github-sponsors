<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Data;

use Flarum\Gdpr\Data\Type;
use Flarum\Group\Group;
use FoF\GitHubSponsors\Sponsor;

/**
 * A member's sponsorship as this extension records it, for flarum/gdpr.
 *
 * Erasing the record matters beyond the data itself: left behind on an anonymized
 * account, the next sync would see the sponsor "disappear" and fire SponsorshipEnded,
 * with an audit entry, for someone who asked to be erased.
 */
class GitHubSponsor extends Type
{
    /** Named explicitly, so renaming the class never changes GDPR's translation keys. */
    public static function dataType(): string
    {
        return 'GitHubSponsor';
    }

    public function export(): ?array
    {
        $sponsor = Sponsor::find($this->user->id);

        if (!$sponsor) {
            return null;
        }

        return [
            'github-sponsors/sponsor.json' => $this->encodeForExport([
                'status'            => $sponsor->status?->value,
                'status_changed_at' => $sponsor->status_changed_at?->toIso8601String(),
                'managed_groups'    => Group::query()->whereIn('id', $sponsor->managedGroupIds())->pluck('name_singular')->all(),
            ]),
        ];
    }

    public function anonymize(): void
    {
        // Nothing in the record is worth keeping once the account is anonymous;
        // GDPR's own User type removes the group memberships afterwards.
        $this->delete();
    }

    public function delete(): void
    {
        Sponsor::query()->where('user_id', $this->user->id)->delete();
    }
}
