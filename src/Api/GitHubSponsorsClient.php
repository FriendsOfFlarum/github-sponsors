<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Api;

use Exception;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;

class GitHubSponsorsClient
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * Fetch sponsors from GitHub GraphQL API.
     *
     * @param string $apiToken    GitHub personal access token
     * @param string $accountType Either 'user' or 'organization'
     * @param string $login       GitHub username or organization name
     *
     * @throws Exception
     *
     * @return array{maintainer: string, sponsors: array}
     */
    public function fetchSponsors(string $apiToken, string $accountType, string $login): array
    {
        $response = $this->client->post('https://api.github.com/graphql', [
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

        return [
            'maintainer' => $maintainer->name,
            'sponsors'   => collect($maintainer->sponsorshipsAsMaintainer->nodes)->all(),
        ];
    }
}
