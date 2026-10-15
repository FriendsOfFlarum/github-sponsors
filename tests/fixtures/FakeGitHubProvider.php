<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Tests\fixtures;

use Flarum\Foundation\AbstractServiceProvider;
use FoF\GitHubSponsors\Api\GitHubSponsorsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * Binds the GitHub client to queued Guzzle responses, so the real client code
 * (pagination, error classification) runs without the network.
 */
class FakeGitHubProvider extends AbstractServiceProvider
{
    /** @var array<Response|\Throwable> */
    public static array $responses = [];

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    public static array $history = [];

    public static function reset(array $responses = []): void
    {
        self::$responses = $responses;
        self::$history = [];
    }

    /**
     * Queue a successful connection test.
     */
    public static function connection(int $sponsorships = 52, array $emails = ['sponsor@example.com']): void
    {
        self::reset([new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => [
            'viewer'       => ['login' => 'admin-account'],
            'organization' => [
                'name'                     => 'Flarum',
                'login'                    => 'flarum',
                'sponsorshipsAsMaintainer' => ['totalCount' => $sponsorships, 'nodes' => array_map(fn ($email) => ['sponsorEntity' => ['email' => $email]], $emails)],
            ],
        ]]))]);
    }

    /**
     * Queue one page of sponsorships per argument.
     *
     * @param array<array<string, mixed>> ...$pages Sponsorship nodes
     */
    public static function sponsors(array ...$pages): void
    {
        $pages = $pages ?: [[]];
        $responses = [];

        foreach ($pages as $i => $nodes) {
            $more = $i < count($pages) - 1;
            $responses[] = new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => ['organization' => [
                'name'                     => 'Flarum',
                'login'                    => 'flarum',
                'sponsorshipsAsMaintainer' => ['pageInfo' => ['hasNextPage' => $more, 'endCursor' => $more ? "cursor-$i" : null], 'nodes' => $nodes],
            ]]]));
        }

        self::reset($responses);
    }

    public function register(): void
    {
        $this->container->singleton(GitHubSponsorsClient::class, function () {
            // Read the queue per request, so a test can queue responses for a second run
            // after the client singleton exists.
            $handler = fn ($request, array $options) => (new MockHandler([array_shift(self::$responses) ?? new \RuntimeException('No queued GitHub response')]))($request, $options);

            $stack = HandlerStack::create($handler);
            $stack->push(Middleware::history(self::$history));

            return new GitHubSponsorsClient(new Client(['handler' => $stack]));
        });
    }
}
