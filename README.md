# GitHub Sponsors by FriendsOfFlarum

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/fof/github-sponsors.svg)](https://packagist.org/packages/fof/github-sponsors) [![OpenCollective](https://img.shields.io/badge/opencollective-fof-blue.svg)](https://opencollective.com/fof/donate) [![Donate](https://img.shields.io/badge/donate-datitisev-important.svg)](https://datitisev.me/donate)

A [Flarum](http://flarum.org) extension that automatically synchronizes your GitHub Sponsors with Flarum user groups.

## Features

- 🔄 **Automatic Synchronization**: Hourly checks for new and removed sponsors
- 👥 **Smart User Matching**: Matches sponsors via email and GitHub OAuth login
- 🎯 **Flexible Configuration**: Works with both user and organization sponsor accounts
- 🔒 **Safe Group Management**: Only manages users it adds, won't interfere with manually assigned groups
- 📝 **Detailed Logging**: Tracks all changes in dedicated log files

## How It Works

This extension connects your GitHub Sponsors program with your Flarum forum by:

1. **Fetching Sponsors**: Queries GitHub's GraphQL API to retrieve all of your current public sponsors, both users and organizations
2. **Matching Users**: Identifies Flarum users by matching:
   - GitHub OAuth provider IDs for users who logged in via GitHub
   - The sponsor's public GitHub email with a confirmed Flarum user email
3. **Managing Groups**: Automatically adds sponsors to a designated Flarum group and removes users who are no longer sponsors
4. **Tracking Changes**: Logs all additions and removals to help you monitor the process

## Installation

Install with composer:

```sh
composer require fof/github-sponsors:"*"
php flarum migrate
php flarum cache:clear
```

### Required: Flarum Scheduler

This extension requires [Flarum's scheduler](https://docs.flarum.org/scheduler/) to be set up and running. The extension will automatically check for sponsor updates every hour.

## Configuration

After installation, configure the extension in your Flarum admin panel:

### 1. Create a GitHub Personal Access Token

1. Go to [GitHub Settings > Tokens](https://github.com/settings/tokens)
2. Click "Generate new token (classic)"
3. Give it a descriptive name (e.g., "Flarum Sponsors Sync")
4. Select the following scopes:
   - `read:user` - Read user profile data
   - `user:email` - Read the user email address
   - `read:org` - Read organization membership (if syncing an organization)
5. Click "Generate token" and copy it

### 2. Configure the Extension

In your Flarum admin panel, navigate to the GitHub Sponsors extension settings:

- **API Token**: Paste your GitHub personal access token
- **Account Type**: Choose "user" or "organization" depending on your sponsor account type
- **Login**: Enter your GitHub username or organization name
- **Group**: Select which Flarum group to assign to sponsors

Save the settings, and the extension will start syncing on the next scheduled run.

## Usage

Once configured, the extension runs automatically every hour. You can also manually trigger an update:

```bash
php flarum fof:github-sponsors:update
```

### Command Options

#### Dry-Run Mode

Preview what changes would be made without actually applying them:

```bash
php flarum fof:github-sponsors:update --dry-run
```

This is useful for testing your configuration before letting the extension make real changes to your groups.

#### Force Mode

If GitHub reports no sponsors at all while the extension still manages group members, the sync stops without changing anything, because removing everyone is far more likely to be a bad API response than a real change. If you really have no sponsors left, confirm it with:

```bash
php flarum fof:github-sponsors:update --force
```

#### Verbose Output

Get detailed information about the synchronization process:

```bash
php flarum fof:github-sponsors:update -v
```

Verbose mode shows:
- Configuration details (account type, login, target group)
- All sponsors from GitHub with their IDs and emails
- Matched Flarum users with match method (email or GitHub OAuth)
- Unmatched sponsors who couldn't be linked to Flarum accounts
- Summary of users staying, being added, and being removed

Combine both options for detailed preview:

```bash
php flarum fof:github-sponsors:update --dry-run -v
```

### Viewing Logs

Check the synchronization logs:

```bash
tail -f storage/logs/fof-github-sponsors.log
```

Log output includes:
- Number of sponsors found on GitHub
- Number of sponsors matched to Flarum users
- Users added to the group (with `+ #userID username`)
- Users removed from the group (with `- #userID username`)
- Any API errors or configuration issues

## How User Matching Works

The extension uses two methods to match GitHub sponsors to Flarum users:

1. **OAuth Matching**: For users who logged in via GitHub (for example with [FoF OAuth](https://github.com/FriendsOfFlarum/oauth)), matches their GitHub account ID with the sponsor's
2. **Email Matching**: Compares the sponsor's public GitHub email with Flarum user emails, ignoring case. Only **confirmed** Flarum emails count: anyone could register with a sponsor's public email, so an unconfirmed one proves nothing

This dual approach maximizes the chance of correctly identifying your sponsors.

## Important Notes

- The extension only removes the group from users it previously added. It won't affect users who were manually added to the group.
- Users must have either confirmed the same email address as their public GitHub email OR have logged into Flarum via GitHub OAuth at least once.
- **Private sponsorships are not synced.** A private sponsor chose not to be shown publicly, and a forum group would reveal them.
- The GitHub API has rate limits. The extension checks once per hour to stay well within these limits.

## For Developers: Event System

This extension dispatches events when sponsors are added or removed, allowing other extensions to react to these changes.

### Available Events

#### `FoF\GitHubSponsors\Event\SponsorAdded`

Dispatched when a user is added to the sponsors group.

**Properties:**
- `$user` (Flarum\User\User) - The Flarum user who was added
- `$sponsorData` (object|null) - The sponsor's GitHub account:
  - `__typename` - `User` or `Organization`
  - `databaseId` - GitHub account ID
  - `login` - GitHub username
  - `email` - Public email address, if any

**Example listener:**

```php
use Flarum\Extend;
use FoF\GitHubSponsors\Event\SponsorAdded;

return [
    (new Extend\Event)
        ->listen(SponsorAdded::class, function (SponsorAdded $event) {
            $user = $event->user;
            $githubId = $event->sponsorData->databaseId ?? null;

            // Your custom logic here
            // e.g., send a welcome email, grant additional permissions, etc.
        }),
];
```

#### `FoF\GitHubSponsors\Event\SponsorRemoved`

Dispatched when a user is removed from the sponsors group (sponsorship ended).

**Properties:**
- `$user` (Flarum\User\User) - The Flarum user who was removed

**Example listener:**

```php
use Flarum\Extend;
use FoF\GitHubSponsors\Event\SponsorRemoved;

return [
    (new Extend\Event)
        ->listen(SponsorRemoved::class, function (SponsorRemoved $event) {
            $user = $event->user;

            // Your custom logic here
            // e.g., send a thank you message, revoke special access, etc.
        }),
];
```

### Notes on Events

- Events are **not dispatched** when running with the `--dry-run` flag
- Events fire after the database changes have been made
- The `SponsorAdded` event includes raw GitHub data for additional context
- These events only fire for automated changes, not manual group assignments

## Troubleshooting

### No sponsors are being synced

- Verify your GitHub token has the correct scopes (`read:user`, `user:email` and `read:org`)
- Run the command manually: errors now say whether GitHub rejected the token, the token lacks a scope, or the account was not found
- Check that the account type and login match your GitHub Sponsors account
- Ensure the cron job is running (`schedule:run`)
- Check logs in `storage/logs/fof-github-sponsors.log`

### Some sponsors aren't being matched

- Ensure sponsors have public email addresses on their GitHub profiles, and that the matching Flarum email is confirmed
- Private sponsorships are never synced
- Alternatively, have them log into your Flarum forum using GitHub OAuth at least once

## Updating

```sh
composer update fof/github-sponsors
php flarum migrate
php flarum cache:clear
```

[![OpenCollective](https://img.shields.io/badge/donate-friendsofflarum-44AEE5?style=for-the-badge&logo=open-collective)](https://opencollective.com/fof/donate) [![GitHub](https://img.shields.io/badge/donate-datitisev-ea4aaa?style=for-the-badge&logo=github)](https://datitisev.me/donate/github)

- [Packagist](https://packagist.org/packages/fof/github-sponsors)
- [GitHub](https://github.com/FriendsOfFlarum/github-sponsors)

An extension by [FriendsOfFlarum](https://github.com/FriendsOfFlarum).
