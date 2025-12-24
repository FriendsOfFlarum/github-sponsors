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

1. **Fetching Sponsors**: Queries GitHub's GraphQL API to retrieve your current sponsors
2. **Matching Users**: Identifies Flarum users by matching:
   - Email addresses from GitHub sponsors with Flarum user emails
   - GitHub OAuth provider IDs for users who logged in via GitHub
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
   - `user` - Read user profile data
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

## How User Matching Works

The extension uses two methods to match GitHub sponsors to Flarum users:

1. **Email Matching**: Direct comparison of sponsor email from GitHub with Flarum user emails
2. **OAuth Matching**: For users who logged in via GitHub, matches their GitHub ID with sponsor IDs

This dual approach maximizes the chance of correctly identifying your sponsors.

## Important Notes

- The extension only removes the group from users it previously added. It won't affect users who were manually added to the group.
- Users must have either the same email address as their GitHub account OR have logged into Flarum via GitHub OAuth at least once.
- The GitHub API has rate limits. The extension checks once per hour to stay well within these limits.

## Troubleshooting

### No sponsors are being synced

- Verify your GitHub token has the correct scopes (`user` and `read:org`)
- Check that the account type and login match your GitHub Sponsors account
- Ensure the cron job is running (`schedule:run`)
- Check logs in `storage/logs/fof-github-sponsors.log`

### Some sponsors aren't being matched

- Ensure sponsors have public email addresses on their GitHub profiles
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
