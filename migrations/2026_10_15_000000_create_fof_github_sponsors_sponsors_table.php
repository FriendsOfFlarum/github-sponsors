<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable(
    'fof_github_sponsors_sponsors',
    function (Blueprint $table) {
        $table->unsignedInteger('user_id')->primary();
        $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        // Null once a known sponsor stops sponsoring; kept so ended sponsorships and owned memberships are not forgotten.
        $table->string('status', 16)->nullable();
        // Groups this extension added the user to, and may therefore remove them from.
        $table->json('managed_group_ids')->nullable();
        $table->dateTime('status_changed_at')->nullable();
    }
);
