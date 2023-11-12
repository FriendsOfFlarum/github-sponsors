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
use Exception;
use Flarum\Group\Group;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\LoginProvider;
use Flarum\User\User;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use UnexpectedValueException;

class UpdateCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'fof:github-sponsors:update';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Update groups of GitHub sponsors.';

    protected $prefix;

    /**
     * @var SettingsRepositoryInterface
     */
    private $settings;

    public function __construct(SettingsRepositoryInterface $settings)
    {
        parent::__construct();

        $this->settings = $settings;
        $this->prefix = Carbon::now()->format('M d, Y @ h:m A');
    }

    public function handle()
    {
        $this->line('');

        $apiToken = $this->settings->get('fof-github-sponsors.api_token');
        $accountType = $this->settings->get('fof-github-sponsors.account_type');
        $login = strtolower($this->settings->get('fof-github-sponsors.login'));
        $groupId = $this->settings->get('fof-github-sponsors.group_id');

        /**
         * @var Group|null
         */
        $group = isset($groupId) ? Group::find((int) $groupId) : null;

        if (!isset($apiToken) || empty($apiToken)) {
            throw new UnexpectedValueException('GitHub API key must be provided');
        } elseif ($accountType != 'user' && $accountType != 'organization') {
            throw new UnexpectedValueException('Account type must be provided');
        } elseif (empty($login)) {
            throw new UnexpectedValueException('User or organization login must be provided');
        } elseif (!isset($group)) {
            throw new UnexpectedValueException("Invalid group ID: '$groupId'");
        }

        $this->info('Retrieving GitHub sponsors...');

        // Retrieve emails from Open Collective GraphQL API
        $client = new Client();
        $response = $client->post('https://api.github.com/graphql', [
            'json' => [
                'query' => "
                    query $accountType(\$login: String!) {
                      $accountType(login: \$login) {
                        name
                        sponsorshipsAsMaintainer(first: 100) {
                          nodes {
                            sponsor {
                              databaseId
                              email
                            }
                          }
                        }
                      }
                    }
                ",
                'variables' => ['login' => $login],
            ],
            'headers' => ['Authorization' => "bearer $apiToken"],
        ]);
        $json = json_decode($response->getBody()->getContents());

        if (isset($json->errors)) {
            throw new Exception(implode("\n", Arr::pluck($json->errors, 'message')));
        }

        if (!isset($json->data->$accountType)) {
            throw new Exception("Login '$login' not found");
        }

        $maintainer = $json->data->$accountType;
        $sponsors = collect($maintainer->sponsorshipsAsMaintainer->nodes);

        $this->info("|> {$sponsors->count()} sponsors of {$maintainer->name} ($accountType)");

        $sponsorUsersIds = $sponsors->pluck('sponsor.databaseId')->all();
        $sponsorUsersEmails = $sponsors
            ->pluck('sponsor.email')
            ->merge(
                LoginProvider::query()
                    ->where('provider', 'github')
                    ->whereIn('identifier', $sponsorUsersIds)
                    ->join('users', 'login_providers.user_id', '=', 'users.id')
                    ->pluck('users.email')
            )
            ->filter()
            ->unique();
        $sponsorUsers = User::query()->whereIn('email', $sponsorUsersEmails)->get();

        $this->info("|> -> {$sponsorUsers->count()} registered");

        // Remove group from users that have it but shouldn't
        $usersManaging = collect(json_decode($this->settings->get('fof-github-sponsors.users', '[]')));
        $usersToRemove = $group->users()
            ->leftJoin('login_providers', 'login_providers.user_id', '=', 'users.id')
            ->whereIn('users.id', $usersManaging)
            ->whereNotIn('users.email', $sponsorUsersEmails)
            ->whereNotIn('login_providers.identifier', $sponsorUsersIds)
            ->get();

        $this->info('Applying group changes...');

        $group->users()->detach($usersToRemove->map->id);

        foreach ($usersToRemove as $user) {
            $usersManaging = $usersManaging->reject($user->id);
        }

        $this->updateUsersManaging($usersManaging);
        $this->outputUsers($usersToRemove, '-');

        if ($sponsorUsers->isEmpty()) {
            $this->info('Done.');

            return;
        }

        // Add group to users that should have it
        if ($sponsorUsers->isNotEmpty()) {
            $sponsorUsers->each(function ($user) use ($usersManaging, $group) {
                if (!$user->groups()->find($group->id)) {
                    $this->outputUser($user, '+');
                    $user->groups()->attach($group->id);
                    $usersManaging->push($user->id);
                }
            });
        }

        $this->updateUsersManaging($usersManaging);

        $this->info('Done.');
    }

    protected function outputUsers($users, $prefix)
    {
        foreach ($users as $user) {
            $this->outputUser($user, $prefix);
        }
    }

    protected function outputUser($user, $prefix)
    {
        $this->info("|> $prefix #{$user->id} {$user->username}");
    }

    public function info($string, $verbosity = null)
    {
        parent::info($this->prefix.' | '.$string, $verbosity);
    }

    protected function updateUsersManaging(Collection $users)
    {
        $this->settings->set('fof-github-sponsors.users', $users->values()->unique()->toJson());
    }
}
