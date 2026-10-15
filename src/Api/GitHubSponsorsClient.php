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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;

class GitHubSponsorsClient
{
    protected const ENDPOINT = 'https://api.github.com/graphql';

    /** GitHub's maximum page size for connections. */
    protected const PAGE_SIZE = 100;

    public const ACCOUNT_TYPES = ['user', 'organization'];

    protected Client $client;

    public function __construct(?Client $client = null)
    {
        // Without a timeout a hung request blocks forever, and the scheduler's
        // withoutOverlapping() lock then stops every later run for 24 hours.
        $this->client = $client ?? new Client(['timeout' => 30, 'connect_timeout' => 10]);
    }

    /**
     * Fetch every public sponsor of a user or organization, current or past, one
     * sponsorship per GitHub account.
     *
     * Private sponsorships are left out on purpose: the sponsor chose not to be
     * shown, and a public forum group would reveal them.
     *
     * @param string $accountType 'user' or 'organization'
     *
     * @throws GitHubSponsorsException
     *
     * @return array{maintainer: string, sponsors: array<object>} Each sponsor is a Sponsorship node with a `sponsorEntity`
     */
    public function fetchSponsors(string $apiToken, string $accountType, string $login): array
    {
        if (!in_array($accountType, self::ACCOUNT_TYPES, true)) {
            // It is interpolated into the query as a field name, so it must be one of the two.
            throw new InvalidArgumentException("Unknown account type '$accountType'");
        }

        $sponsors = [];
        $cursor = null;
        $maintainer = null;

        do {
            $data = $this->query($apiToken, "
                query sponsors(\$login: String!, \$first: Int!, \$after: String) {
                  $accountType(login: \$login) {
                    name
                    login
                    sponsorshipsAsMaintainer(first: \$first, after: \$after, activeOnly: false, includePrivate: false) {
                      pageInfo {
                        hasNextPage
                        endCursor
                      }
                      nodes {
                        isActive
                        isOneTimePayment
                        sponsorEntity {
                          __typename
                          ... on User {
                            databaseId
                            login
                            email
                          }
                          ... on Organization {
                            databaseId
                            login
                            email
                          }
                        }
                      }
                    }
                  }
                }
            ", ['login' => $login, 'first' => self::PAGE_SIZE, 'after' => $cursor]);

            $maintainer = $data->$accountType ?? null;

            if (!$maintainer) {
                throw new GitHubSponsorsException(GitHubSponsorsException::NOT_FOUND, "GitHub $accountType '$login' not found");
            }

            $connection = $maintainer->sponsorshipsAsMaintainer ?? null;

            if (!isset($connection->nodes, $connection->pageInfo)) {
                throw new GitHubSponsorsException(GitHubSponsorsException::INVALID_RESPONSE, 'GitHub returned no sponsorships data');
            }

            array_push($sponsors, ...array_filter((array) $connection->nodes, fn ($node) => isset($node->sponsorEntity)));
            $cursor = $connection->pageInfo->endCursor ?? null;
        } while (($connection->pageInfo->hasNextPage ?? false) && $cursor);

        return [
            'maintainer' => $maintainer->name ?: $maintainer->login,
            'sponsors'   => $this->uniqueSponsors($sponsors),
        ];
    }

    /**
     * Check a token can do everything the sync needs, cheaply: authenticate, find
     * the account, and read its sponsorships (scope errors surface on that query).
     *
     * @param string $accountType 'user' or 'organization'
     *
     * @throws GitHubSponsorsException
     *
     * @return array{account: string|null, maintainer: string, sponsorships: int, withPublicEmail: int} withPublicEmail counts a sample of up to 25 sponsors
     */
    public function testConnection(string $apiToken, string $accountType, string $login): array
    {
        if (!in_array($accountType, self::ACCOUNT_TYPES, true)) {
            throw new InvalidArgumentException("Unknown account type '$accountType'");
        }

        $data = $this->query($apiToken, "
            query test(\$login: String!) {
              viewer {
                login
              }
              $accountType(login: \$login) {
                name
                login
                sponsorshipsAsMaintainer(first: 25, activeOnly: false, includePrivate: false) {
                  totalCount
                  nodes {
                    sponsorEntity {
                      ... on User {
                        email
                      }
                      ... on Organization {
                        email
                      }
                    }
                  }
                }
              }
            }
        ", ['login' => $login]);

        $maintainer = $data->$accountType ?? null;

        if (!$maintainer) {
            throw new GitHubSponsorsException(GitHubSponsorsException::NOT_FOUND, "GitHub $accountType '$login' not found");
        }

        if (!isset($maintainer->sponsorshipsAsMaintainer->totalCount)) {
            throw new GitHubSponsorsException(GitHubSponsorsException::INVALID_RESPONSE, 'GitHub returned no sponsorships data');
        }

        $nodes = (array) ($maintainer->sponsorshipsAsMaintainer->nodes ?? []);

        return [
            'account'         => $data->viewer->login ?? null,
            'maintainer'      => $maintainer->name ?: $maintainer->login,
            'sponsorships'    => (int) $maintainer->sponsorshipsAsMaintainer->totalCount,
            'withPublicEmail' => count(array_filter($nodes, fn ($node) => !empty($node->sponsorEntity->email))),
        ];
    }

    /**
     * An active monthly sponsorship, as opposed to a one-time payment or a past
     * monthly sponsorship.
     */
    public static function isActiveRecurring(object $sponsorship): bool
    {
        return ($sponsorship->isActive ?? false) && !($sponsorship->isOneTimePayment ?? false);
    }

    /**
     * Reduce sponsorships to one per GitHub account. An active monthly one wins, so
     * an old one-time payment never hides a live sponsorship.
     *
     * @param array<object> $sponsorships
     *
     * @return array<object>
     */
    protected function uniqueSponsors(array $sponsorships): array
    {
        $unique = [];

        foreach ($sponsorships as $sponsorship) {
            $account = $sponsorship->sponsorEntity;
            $key = ($account->__typename ?? 'User').':'.($account->databaseId ?? $account->login ?? spl_object_id($sponsorship));

            if (!isset($unique[$key]) || (!self::isActiveRecurring($unique[$key]) && self::isActiveRecurring($sponsorship))) {
                $unique[$key] = $sponsorship;
            }
        }

        return array_values($unique);
    }

    /**
     * Run a GraphQL query and return its `data`, turning every failure mode into
     * a {@see GitHubSponsorsException}.
     *
     * @param array<string, mixed> $variables
     *
     * @throws GitHubSponsorsException
     */
    protected function query(string $apiToken, string $query, array $variables): object
    {
        try {
            $response = $this->client->post(self::ENDPOINT, [
                'json'    => ['query' => $query, 'variables' => $variables],
                'headers' => ['Authorization' => "bearer $apiToken"],
            ]);
        } catch (ConnectException $e) {
            throw new GitHubSponsorsException(GitHubSponsorsException::NETWORK, 'Could not reach GitHub: '.$e->getMessage(), $e);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $status = $response?->getStatusCode();
            $kind = match (true) {
                $status === 401                                                    => GitHubSponsorsException::UNAUTHORIZED,
                $status === 429, $response?->getHeaderLine('X-RateLimit-Remaining') === '0' => GitHubSponsorsException::RATE_LIMITED,
                $status === 403                                                    => GitHubSponsorsException::FORBIDDEN,
                default                                                            => GitHubSponsorsException::API_ERROR,
            };
            $body = json_decode((string) $response?->getBody());

            throw new GitHubSponsorsException($kind, $body->message ?? "GitHub request failed (HTTP $status)", $e);
        } catch (GuzzleException $e) {
            throw new GitHubSponsorsException(GitHubSponsorsException::NETWORK, 'GitHub request failed: '.$e->getMessage(), $e);
        }

        $json = json_decode((string) $response->getBody());

        if (!is_object($json)) {
            throw new GitHubSponsorsException(GitHubSponsorsException::INVALID_RESPONSE, 'GitHub returned a response that is not JSON');
        }

        if (!empty($json->errors)) {
            $types = array_map(fn ($error) => $error->type ?? null, $json->errors);
            $kind = match (true) {
                in_array('INSUFFICIENT_SCOPES', $types, true), in_array('FORBIDDEN', $types, true) => GitHubSponsorsException::FORBIDDEN,
                in_array('NOT_FOUND', $types, true)                                                => GitHubSponsorsException::NOT_FOUND,
                in_array('RATE_LIMITED', $types, true)                                             => GitHubSponsorsException::RATE_LIMITED,
                default                                                                            => GitHubSponsorsException::API_ERROR,
            };

            throw new GitHubSponsorsException($kind, implode("\n", array_map(fn ($error) => $error->message ?? 'Unknown error', $json->errors)));
        }

        if (!isset($json->data) || !is_object($json->data)) {
            throw new GitHubSponsorsException(GitHubSponsorsException::INVALID_RESPONSE, 'GitHub returned no data');
        }

        return $json->data;
    }
}
