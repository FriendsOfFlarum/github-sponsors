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

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the extension knows about one user's sponsorship, and which of their group
 * memberships it owns.
 *
 * @property int               $user_id
 * @property SponsorStatus|null $status
 * @property array<int>|null   $managed_group_ids
 * @property Carbon|null       $status_changed_at
 * @property User              $user
 */
class Sponsor extends AbstractModel
{
    /** @use HasFactory<SponsorFactory> */
    use HasFactory;

    protected $table = 'fof_github_sponsors_sponsors';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    /** Lets test fixtures upsert by user rather than by every column (PostgreSQL cannot compare the JSON column). */
    public ?array $uniqueKeys = ['user_id'];

    protected $fillable = ['user_id'];

    protected $casts = [
        'status'            => SponsorStatus::class,
        'managed_group_ids' => 'array',
        'status_changed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<int>
     */
    public function managedGroupIds(): array
    {
        return array_map('intval', $this->managed_group_ids ?? []);
    }
}
