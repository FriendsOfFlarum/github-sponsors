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

use Exception;
use FoF\GitHubSponsors\Api\GitHubSponsorsClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class GitHubSponsorsClientTest extends TestCase
{
    public function testFetchSponsorsSuccess()
    {
        // Mock response data
        $mockResponse = json_encode([
            'data' => [
                'user' => [
                    'name'                     => 'Test User',
                    'sponsorshipsAsMaintainer' => [
                        'nodes' => [
                            [
                                'sponsor' => [
                                    'databaseId' => 12345,
                                    'email'      => 'sponsor@example.com',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        // Mock Guzzle client
        $mockClient = $this->createMock(Client::class);
        $mockClient->expects($this->once())
            ->method('post')
            ->with(
                'https://api.github.com/graphql',
                $this->callback(function ($options) {
                    return isset($options['json']['query']) &&
                           isset($options['json']['variables']) &&
                           isset($options['headers']['Authorization']);
                })
            )
            ->willReturn(new Response(200, [], $mockResponse));

        // Test
        $client = new GitHubSponsorsClient($mockClient);
        $result = $client->fetchSponsors('test-token', 'user', 'testuser');

        $this->assertEquals('Test User', $result['maintainer']);
        $this->assertCount(1, $result['sponsors']);
        $this->assertEquals(12345, $result['sponsors'][0]->sponsor->databaseId);
        $this->assertEquals('sponsor@example.com', $result['sponsors'][0]->sponsor->email);
    }

    public function testFetchSponsorsThrowsExceptionOnGraphQLError()
    {
        $mockResponse = json_encode([
            'errors' => [
                ['message' => 'Invalid token'],
            ],
        ]);

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('post')
            ->willReturn(new Response(200, [], $mockResponse));

        $client = new GitHubSponsorsClient($mockClient);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid token');

        $client->fetchSponsors('invalid-token', 'user', 'testuser');
    }

    public function testFetchSponsorsThrowsExceptionOnInvalidLogin()
    {
        $mockResponse = json_encode([
            'data' => [],
        ]);

        $mockClient = $this->createMock(Client::class);
        $mockClient->method('post')
            ->willReturn(new Response(200, [], $mockResponse));

        $client = new GitHubSponsorsClient($mockClient);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Login 'nonexistent' not found");

        $client->fetchSponsors('test-token', 'user', 'nonexistent');
    }
}
