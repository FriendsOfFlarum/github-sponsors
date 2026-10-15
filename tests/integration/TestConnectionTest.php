<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Tests\integration;

use Flarum\Extend;
use Flarum\Testing\integration\RetrievesAuthorizedUsers;
use Flarum\Testing\integration\TestCase;
use Flarum\User\User;
use FoF\GitHubSponsors\Tests\fixtures\FakeGitHubProvider;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;

class TestConnectionTest extends TestCase
{
    use RetrievesAuthorizedUsers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('fof-github-sponsors');
        $this->extend((new Extend\ServiceProvider())->register(FakeGitHubProvider::class));
        FakeGitHubProvider::reset();

        $this->setting('fof-github-sponsors.api_token', 'saved-token');
        $this->setting('fof-github-sponsors.account_type', 'organization');
        $this->setting('fof-github-sponsors.login', 'saved-login');

        $this->prepareDatabase([User::class => [$this->normalUser()]]);
    }

    /**
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    protected function test(int $actorId, array $body = []): array
    {
        $response = $this->send($this->request('POST', '/api/fof/github-sponsors/test-connection', [
            'authenticatedAs' => $actorId,
            'json'            => $body,
        ]));

        return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
    }

    #[Test]
    public function an_admin_sees_who_the_token_belongs_to_and_what_it_can_read(): void
    {
        FakeGitHubProvider::connection(52);

        [$status, $body] = $this->test(1);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => true, 'account' => 'admin-account', 'maintainer' => 'Flarum', 'sponsorships' => 52, 'withPublicEmail' => 1], $body);
    }

    #[Test]
    public function values_from_the_form_win_over_saved_settings(): void
    {
        FakeGitHubProvider::connection();

        $this->test(1, ['apiToken' => 'unsaved-token', 'accountType' => 'organization', 'login' => 'Unsaved-Login']);

        $request = FakeGitHubProvider::$history[0]['request'];
        $this->assertSame('bearer unsaved-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('unsaved-login', json_decode((string) $request->getBody(), true)['variables']['login']);
    }

    #[Test]
    public function saved_settings_are_used_when_the_form_sends_nothing(): void
    {
        FakeGitHubProvider::connection();

        $this->test(1);

        $request = FakeGitHubProvider::$history[0]['request'];
        $this->assertSame('bearer saved-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('saved-login', json_decode((string) $request->getBody(), true)['variables']['login']);
    }

    #[Test]
    public function a_failing_check_explains_why(): void
    {
        FakeGitHubProvider::reset([new Response(401, [], json_encode(['message' => 'Bad credentials']))]);

        [$status, $body] = $this->test(1);

        $this->assertSame(200, $status);
        $this->assertSame(['ok' => false, 'kind' => 'unauthorized', 'message' => 'Bad credentials'], $body);
    }

    #[Test]
    public function missing_settings_are_reported_without_calling_open_collective(): void
    {
        $this->setting('fof-github-sponsors.api_token', '');

        [, $body] = $this->test(1);

        $this->assertSame('configuration', $body['kind']);
        $this->assertSame([], FakeGitHubProvider::$history);
    }

    #[Test]
    public function only_admins_can_test(): void
    {
        FakeGitHubProvider::connection();

        [$status] = $this->test(2);

        $this->assertSame(403, $status);
        $this->assertSame([], FakeGitHubProvider::$history);
    }
}
