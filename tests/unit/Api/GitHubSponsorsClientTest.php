<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Tests\Unit\Api;

use FoF\GitHubSponsors\Api\GitHubSponsorsClient;
use FoF\GitHubSponsors\Api\GitHubSponsorsException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Runs the client against a real Guzzle client with queued responses, so HTTP
 * error handling behaves as in production.
 */
class GitHubSponsorsClientTest extends TestCase
{
    /** @var array<int, array{request: Request}> */
    protected array $history = [];

    protected function client(array $responses): GitHubSponsorsClient
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GitHubSponsorsClient(new Client(['handler' => $stack]));
    }

    protected function json(array $body, int $status = 200, array $headers = []): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
    }

    protected function sponsor(int $id, string $login, ?string $email = null, string $type = 'User', bool $active = true, bool $oneTime = false): array
    {
        return ['isActive' => $active, 'isOneTimePayment' => $oneTime, 'sponsorEntity' => ['__typename' => $type, 'databaseId' => $id, 'login' => $login, 'email' => $email]];
    }

    protected function page(array $nodes, ?string $endCursor = null, bool $hasNextPage = false, string $accountType = 'organization'): Response
    {
        return $this->json(['data' => [$accountType => [
            'name'                     => 'Flarum',
            'login'                    => 'flarum',
            'sponsorshipsAsMaintainer' => ['pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor], 'nodes' => $nodes],
        ]]]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function sentBodies(): array
    {
        return array_map(fn ($entry) => json_decode((string) $entry['request']->getBody(), true), $this->history);
    }

    #[Test]
    public function it_returns_users_and_organizations_with_the_maintainer_name(): void
    {
        $result = $this->client([$this->page([$this->sponsor(1, 'alice', 'alice@example.com'), $this->sponsor(2, 'acme', null, 'Organization')])])
            ->fetchSponsors('token', 'organization', 'flarum');

        $this->assertSame('Flarum', $result['maintainer']);
        $this->assertSame(['alice', 'acme'], array_map(fn ($s) => $s->sponsorEntity->login, $result['sponsors']));
        $this->assertSame('bearer token', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    #[Test]
    public function it_follows_every_page(): void
    {
        $result = $this->client([
            $this->page([$this->sponsor(1, 'alice')], 'cursor-1', true),
            $this->page([$this->sponsor(2, 'bob')]),
        ])->fetchSponsors('token', 'organization', 'flarum');

        $this->assertCount(2, $result['sponsors']);
        $this->assertNull($this->sentBodies()[0]['variables']['after']);
        $this->assertSame('cursor-1', $this->sentBodies()[1]['variables']['after']);
    }

    #[Test]
    public function past_sponsorships_are_requested_too(): void
    {
        $this->client([$this->page([])])->fetchSponsors('token', 'organization', 'flarum');

        $this->assertStringContainsString('activeOnly: false', $this->sentBodies()[0]['query']);
    }

    #[Test]
    public function an_active_monthly_sponsorship_wins_over_older_ones_from_the_same_account(): void
    {
        $result = $this->client([$this->page([
            $this->sponsor(1, 'alice', null, 'User', false, true),
            $this->sponsor(1, 'alice', null, 'User', true, false),
            $this->sponsor(1, 'alice', null, 'User', false, false),
            $this->sponsor(1, 'acme', null, 'Organization', true, false),
        ])])->fetchSponsors('token', 'organization', 'flarum');

        // A user and an organization may share a databaseId space, so they stay apart.
        $this->assertCount(2, $result['sponsors']);
        $this->assertTrue(GitHubSponsorsClient::isActiveRecurring($result['sponsors'][0]));
    }

    #[Test]
    public function only_an_active_monthly_sponsorship_is_recurring(): void
    {
        $this->assertTrue(GitHubSponsorsClient::isActiveRecurring((object) ['isActive' => true, 'isOneTimePayment' => false]));
        $this->assertFalse(GitHubSponsorsClient::isActiveRecurring((object) ['isActive' => false, 'isOneTimePayment' => false]));
        $this->assertFalse(GitHubSponsorsClient::isActiveRecurring((object) ['isActive' => true, 'isOneTimePayment' => true]));
    }

    #[Test]
    public function private_sponsorships_are_not_requested(): void
    {
        $this->client([$this->page([], null, false, 'user')])->fetchSponsors('token', 'user', 'someone');

        $this->assertStringContainsString('includePrivate: false', $this->sentBodies()[0]['query']);
        $this->assertStringContainsString('user(login:', $this->sentBodies()[0]['query']);
    }

    #[Test]
    public function an_unknown_account_type_never_reaches_the_query(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client([])->fetchSponsors('token', 'repository { id } organization', 'flarum');
    }

    protected function assertFailsWith(string $kind, array $responses, ?string $message = null): void
    {
        try {
            $this->client($responses)->fetchSponsors('token', 'organization', 'flarum');
            $this->fail("Expected a GitHubSponsorsException of kind $kind");
        } catch (GitHubSponsorsException $e) {
            $this->assertSame($kind, $e->kind);

            if ($message !== null) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    #[Test]
    public function a_rejected_token_is_unauthorized(): void
    {
        $this->assertFailsWith(GitHubSponsorsException::UNAUTHORIZED, [$this->json(['message' => 'Bad credentials'], 401)], 'Bad credentials');
    }

    #[Test]
    public function an_unknown_login_is_not_found(): void
    {
        // The shape GitHub returns: HTTP 200, a NOT_FOUND error, null data for the account.
        $this->assertFailsWith(GitHubSponsorsException::NOT_FOUND, [$this->json([
            'data'   => ['organization' => null],
            'errors' => [['type' => 'NOT_FOUND', 'path' => ['organization'], 'message' => "Could not resolve to an Organization with the login of 'flarum'."]],
        ])]);
    }

    #[Test]
    public function missing_scopes_are_forbidden(): void
    {
        $this->assertFailsWith(GitHubSponsorsException::FORBIDDEN, [$this->json([
            'errors' => [['type' => 'INSUFFICIENT_SCOPES', 'message' => "Your token has not been granted the required scopes to execute this query. The 'email' field requires one of the following scopes: ['user:email', 'read:user']"]],
        ])], 'required scopes');
    }

    #[Test]
    public function an_exhausted_rate_limit_is_reported_as_such(): void
    {
        $this->assertFailsWith(GitHubSponsorsException::RATE_LIMITED, [$this->json(['message' => 'API rate limit exceeded'], 403, ['X-RateLimit-Remaining' => '0'])]);
    }

    #[Test]
    public function an_unreachable_api_is_a_network_failure(): void
    {
        $this->assertFailsWith(GitHubSponsorsException::NETWORK, [new ConnectException('Connection timed out', new Request('POST', 'https://api.github.com/graphql'))]);
    }

    #[Test]
    public function a_body_that_is_not_json_is_an_invalid_response(): void
    {
        $this->assertFailsWith(GitHubSponsorsException::INVALID_RESPONSE, [new Response(200, [], '<html>Unicorn!</html>')]);
    }

    #[Test]
    public function missing_sponsorship_data_is_an_invalid_response_rather_than_no_sponsors(): void
    {
        $this->assertFailsWith(GitHubSponsorsException::INVALID_RESPONSE, [$this->json(['data' => ['organization' => ['name' => 'Flarum', 'login' => 'flarum', 'sponsorshipsAsMaintainer' => null]]])]);
    }

    protected function connectionResponse(?array $organization, int $total = 52): Response
    {
        return $this->json(['data' => [
            'viewer'       => ['login' => 'token-owner'],
            'organization' => $organization === null ? null : $organization + ['sponsorshipsAsMaintainer' => ['totalCount' => $total, 'nodes' => [['sponsorEntity' => ['email' => 'a@example.com']], ['sponsorEntity' => ['email' => '']]]]],
        ]]);
    }

    #[Test]
    public function a_working_token_reports_its_owner_account_and_sponsorships(): void
    {
        $result = $this->client([$this->connectionResponse(['name' => 'Flarum', 'login' => 'flarum'])])->testConnection('token', 'organization', 'flarum');

        $this->assertSame(['account' => 'token-owner', 'maintainer' => 'Flarum', 'sponsorships' => 52, 'withPublicEmail' => 1], $result);
        $this->assertSame('bearer token', $this->history[0]['request']->getHeaderLine('Authorization'));
    }

    protected function assertConnectionFailsWith(string $kind, array $responses): void
    {
        try {
            $this->client($responses)->testConnection('token', 'organization', 'flarum');
            $this->fail("Expected a GitHubSponsorsException of kind $kind");
        } catch (GitHubSponsorsException $e) {
            $this->assertSame($kind, $e->kind);
        }
    }

    #[Test]
    public function the_connection_test_spots_a_rejected_token(): void
    {
        $this->assertConnectionFailsWith(GitHubSponsorsException::UNAUTHORIZED, [$this->json(['message' => 'Bad credentials'], 401)]);
    }

    #[Test]
    public function the_connection_test_spots_an_unknown_account(): void
    {
        $this->assertConnectionFailsWith(GitHubSponsorsException::NOT_FOUND, [$this->connectionResponse(null)]);
    }

    #[Test]
    public function the_connection_test_spots_a_missing_scope(): void
    {
        $this->assertConnectionFailsWith(GitHubSponsorsException::FORBIDDEN, [$this->json([
            'errors' => [['type' => 'INSUFFICIENT_SCOPES', 'message' => 'Your token has not been granted the required scopes to execute this query.']],
        ])]);
    }

    protected function privatePage(array $nodes, ?string $endCursor = null, bool $hasNextPage = false): Response
    {
        return $this->json(['data' => ['organization' => ['sponsorshipsAsMaintainer' => ['pageInfo' => ['hasNextPage' => $hasNextPage, 'endCursor' => $endCursor], 'nodes' => $nodes]]]]);
    }

    protected function privateNode(int $id, string $privacy, bool $active, bool $oneTime = false, string $type = 'User'): array
    {
        return ['privacyLevel' => $privacy, 'isActive' => $active, 'isOneTimePayment' => $oneTime, 'sponsorEntity' => ['__typename' => $type, 'databaseId' => $id]];
    }

    #[Test]
    public function private_sponsors_are_counted_once_each_and_split_by_status(): void
    {
        $counts = $this->client([
            $this->privatePage([
                $this->privateNode(1, 'PRIVATE', true),
                $this->privateNode(1, 'PRIVATE', false),
                $this->privateNode(2, 'PRIVATE', false),
                $this->privateNode(3, 'PUBLIC', true),
            ], 'next', true),
            $this->privatePage([$this->privateNode(4, 'PRIVATE', true, true)]),
        ])->countPrivateSponsors('token', 'organization', 'flarum');

        // 1: monthly (its past sponsorship does not count again); 2: past; 3: public, not counted; 4: one-time payment.
        $this->assertSame(['recurring' => 1, 'onetime' => 2], $counts);
        $this->assertStringContainsString('includePrivate: true', $this->sentBodies()[0]['query']);
        $this->assertSame('next', $this->sentBodies()[1]['variables']['after']);
    }

    #[Test]
    public function a_private_sponsor_who_also_sponsors_publicly_is_left_to_the_public_list(): void
    {
        $public = [(object) ['isActive' => true, 'isOneTimePayment' => false, 'sponsorEntity' => (object) ['__typename' => 'User', 'databaseId' => 1, 'login' => 'alice']]];

        $counts = $this->client([$this->privatePage([$this->privateNode(1, 'PRIVATE', false), $this->privateNode(2, 'PRIVATE', true)])])
            ->countPrivateSponsors('token', 'organization', 'flarum', $public);

        $this->assertSame(['recurring' => 1, 'onetime' => 0], $counts);
    }
}
