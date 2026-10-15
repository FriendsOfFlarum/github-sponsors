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

use RuntimeException;
use Throwable;

/**
 * A failed GitHub request, classified so callers can explain it.
 */
class GitHubSponsorsException extends RuntimeException
{
    /** The token was rejected outright (HTTP 401: missing, revoked or expired). */
    public const UNAUTHORIZED = 'unauthorized';

    /** The token is valid but lacks a scope or permission (GraphQL INSUFFICIENT_SCOPES / FORBIDDEN, or HTTP 403). */
    public const FORBIDDEN = 'forbidden';

    public const NOT_FOUND = 'not_found';

    public const RATE_LIMITED = 'rate_limited';

    public const NETWORK = 'network';

    /** The response could not be understood (not JSON, or missing expected data). */
    public const INVALID_RESPONSE = 'invalid_response';

    public const API_ERROR = 'api_error';

    public function __construct(public readonly string $kind, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
