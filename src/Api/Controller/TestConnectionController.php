<?php

/*
 * This file is part of fof/github-sponsors.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FoF\GitHubSponsors\Api\Controller;

use Flarum\Http\RequestUtil;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\GitHubSponsors\Api\GitHubSponsorsClient;
use FoF\GitHubSponsors\Api\GitHubSponsorsException;
use FoF\GitHubSponsors\Services\SyncStatus;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Checks a token the way the sync uses it. Values sent in the body (the admin
 * form, possibly unsaved) win over the saved settings.
 *
 * A failed check is a successful request: it answers 200 with `ok: false` and the
 * failure kind, so the admin page can explain it.
 */
class TestConnectionController implements RequestHandlerInterface
{
    public function __construct(
        protected GitHubSponsorsClient $client,
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        RequestUtil::getActor($request)->assertAdmin();

        $body = (array) $request->getParsedBody();
        $apiToken = trim((string) ($body['apiToken'] ?? '')) ?: (string) $this->settings->get('fof-github-sponsors.api_token');
        $accountType = trim((string) ($body['accountType'] ?? '')) ?: (string) $this->settings->get('fof-github-sponsors.account_type');
        $login = strtolower(trim((string) ($body['login'] ?? '')) ?: (string) $this->settings->get('fof-github-sponsors.login'));

        if (!$apiToken || !$login || !in_array($accountType, GitHubSponsorsClient::ACCOUNT_TYPES, true)) {
            return new JsonResponse(['ok' => false, 'kind' => SyncStatus::CONFIGURATION, 'message' => 'A token, an account type and a login are all required.']);
        }

        try {
            return new JsonResponse(['ok' => true] + $this->client->testConnection($apiToken, $accountType, $login));
        } catch (GitHubSponsorsException $e) {
            return new JsonResponse(['ok' => false, 'kind' => $e->kind, 'message' => $e->getMessage()]);
        }
    }
}
