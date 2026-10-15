# GitHub Sponsors by FriendsOfFlarum

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/fof/github-sponsors.svg)](https://packagist.org/packages/fof/github-sponsors) [![OpenCollective](https://img.shields.io/badge/opencollective-fof-blue.svg)](https://opencollective.com/fof/donate) [![Donate](https://img.shields.io/badge/donate-datitisev-important.svg)](https://datitisev.me/donate)

A [Flarum](http://flarum.org) extension that automatically synchronizes your GitHub Sponsors with Flarum user groups.

## Features

- 🔄 **Automatic Synchronization**: Hourly checks for new and removed sponsors
- 👥 **Smart User Matching**: Matches sponsors via email and GitHub OAuth login
- 🎯 **Flexible Configuration**: Works with both user and organization sponsor accounts
- 🔒 **Safe Group Management**: Only manages users it adds, won't interfere with manually assigned groups
- 🎯 **Two Groups**: Separate groups for active monthly sponsors and for one-time or past sponsors
- 🩺 **Sync Status and Connection Test**: See the last sync's result on the settings page and test a token before saving it
- 📝 **Audit Log**: With [Audit](https://docs.flarum.org/extensions/audit) enabled, every status and group change the sync makes is recorded

## How It Works

This extension connects your GitHub Sponsors program with your Flarum forum by:

1. **Fetching Sponsors**: Queries GitHub's GraphQL API to retrieve all of your public sponsors, current and past, both users and organizations
2. **Matching Users**: Identifies Flarum users by matching:
   - GitHub OAuth provider IDs for users who logged in via GitHub
   - The sponsor's public GitHub email with a confirmed Flarum user email
3. **Categorizing Sponsors**: Active monthly sponsors are monthly sponsors; one-time sponsors and past monthly sponsors are one-time sponsors
4. **Managing Groups**: Adds each sponsor to the group for their status, and moves them when it changes, for example from the monthly group to the one-time group when a monthly sponsorship ends
5. **Tracking Changes**: Remembers each sponsor's status, fires an event when it changes, and records every change in the audit log when [Audit](https://docs.flarum.org/extensions/audit) is enabled

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
- **Monthly Sponsors Group**: Select which Flarum group active monthly sponsors get
- **One-Time and Past Sponsors Group** (optional): Select which Flarum group one-time sponsors and past monthly sponsors get. If not set, they are not managed

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

### Monitoring the Sync

- **Status**: the top of the extension's settings page shows when the sync last ran, and why it failed if it did. It also warns when the last run was more than two hours ago, which usually means Flarum's scheduler is not running.
- **Test connection**: the button on the same page checks the token, account type and login currently in the form, saved or not: that GitHub accepts the token, that the account exists, and that its sponsorships can be read with the token's scopes. It also shows which GitHub account the token belongs to.
- **Changes**: with the [Audit](https://docs.flarum.org/extensions/audit) extension enabled, every change the sync makes is recorded in the audit log: a sponsor starting or stopping a monthly sponsorship, becoming a one-time sponsor, no longer being a sponsor, or being restored to a group they were missing from. Search the log with `action:fof_github_sponsors.*`, or `user:<username>` for one person.
- **Errors**: failed syncs (a rejected token, a missing scope, an unknown account) are written to Flarum's log in `storage/logs/`.
- **Manually**: run the command yourself, with `--dry-run -v` to see what it would change without changing anything.

The first sync after installing or upgrading records every sponsor's current status without announcing them as new sponsors. Only group memberships it restores on that run are recorded.

### Privacy (GDPR)

With the [GDPR](https://docs.flarum.org/extensions/gdpr) extension enabled, the extension's record of a member's sponsorship (their status, when it last changed, and which groups the sync manages for them) is included in their data export, and erased when their account is anonymized or deleted. Erasing it means the sync simply forgets the member: it does not report their sponsorship as ended, so no event fires and no audit entry is written for an erased account.

Sponsor emails and GitHub accounts are only read from GitHub while a sync runs; they are never stored. Private sponsors are only ever counted.

## How User Matching Works

The extension uses two methods to match GitHub sponsors to Flarum users:

1. **OAuth Matching**: For users who logged in via GitHub (for example with [FoF OAuth](https://github.com/FriendsOfFlarum/oauth)), matches their GitHub account ID with the sponsor's
2. **Email Matching**: Compares the sponsor's public GitHub email with Flarum user emails, ignoring case. Only **confirmed** Flarum emails count: anyone could register with a sponsor's public email, so an unconfirmed one proves nothing

This dual approach maximizes the chance of correctly identifying your sponsors.

## How Group Membership Works

- **The sync owns a membership** it added, or one it found already in place (for example added by hand) when the user's sponsorship justifies it. Taking over such a membership is reported in the command output and recorded in the audit log.
- **It only removes memberships it owns**, and only when no other supporter source still wants the user in that group. With [Open Collective](https://github.com/FriendsOfFlarum/open-collective) managing the same groups, someone who supports through both never loses a group because one of them ended.
- **A supporter is never in both groups.** The monthly group wins: a monthly sponsor is taken out of the one-time group, even if they were added to it by hand, and anyone already in the monthly group is not also put in the one-time group.
- **Members nobody wants are left alone.** Hand-added members who are not sponsors are never touched.
- **Sources never fight.** A membership is only added when some source wants it and only removed when none does, so the syncs settle on one result. Give every source the same monthly and one-time groups: if they are mapped the other way round, a supporter can end up in both groups, but nothing flips back and forth.

## Important Notes

- Users must have either confirmed the same email address as their public GitHub email OR have logged into Flarum via GitHub OAuth at least once.
- **Private sponsorships are not synced.** A private sponsor chose not to be shown publicly, and a forum group would reveal them.
- The GitHub API has rate limits. The extension checks once per hour to stay well within these limits.

## For Developers: Event System

The extension remembers each matched user's sponsor status (none, one-time or monthly) and fires an event whenever it changes. Events describe the sponsorship, not the groups: they fire for every matched user, including members an admin added to a group by hand, and whether or not a one-time group is configured.

### Status events

| Event | Fired when |
|---|---|
| `FoF\GitHubSponsors\Event\RecurringSponsorshipStarted` | A user becomes an active monthly sponsor, new or upgrading from a one-time or past sponsorship |
| `FoF\GitHubSponsors\Event\RecurringSponsorshipStopped` | A monthly sponsorship ends; the user remains a past sponsor |
| `FoF\GitHubSponsors\Event\OneTimeSponsorshipStarted` | A user who was not a sponsor makes a one-time sponsorship |
| `FoF\GitHubSponsors\Event\SponsorshipEnded` | A user is no longer a sponsor at all, for example once their GitHub account no longer matches |

All four extend `AbstractSponsorStatusChanged` and implement `SponsorStatusChanged`, so you can listen to the interface to react to every change. Each carries:

- `$user` (`Flarum\User\User`)
- `$previous`, `$current` (`FoF\GitHubSponsors\SponsorStatus|null`): the status before and after; `null` means not a sponsor
- `$account` (`object|null`): the sponsor's GitHub account (`__typename`, `databaseId`, `login`, `email`); `null` when the sponsorship ended
- `$oldGroupIds`, `$newGroupIds` (`int[]`): the user's groups before and after this sync

### Group repairs

`FoF\GitHubSponsors\Event\SponsorGroupsChanged` fires when the sync changes a user's groups without their status changing, such as re-adding a sponsor who was missing from their group. It carries `$user`, `$oldGroupIds` and `$newGroupIds`.

### Example listener

```php
use Flarum\Extend;
use FoF\GitHubSponsors\Event\RecurringSponsorshipStopped;

return [
    (new Extend\Event())
        ->listen(RecurringSponsorshipStopped::class, function (RecurringSponsorshipStopped $event) {
            // e.g. thank $event->user for their support so far
        }),
];
```

### Notes on Events

- Events fire after the database changes have been made, and never in `--dry-run` mode.
- The first sync after installing or upgrading records statuses without status events, so existing sponsors are not announced as new. Group repairs on that run still fire `SponsorGroupsChanged`.
- Group memberships are only removed where the extension owns them (it added or adopted them) and no other supporter source still wants them. A hand-added member is adopted once their sponsorship justifies the group.

### Sponsor counts on the API

A supporters page can only list sponsors who match a forum account. To show the rest, the forum API resource (`/api`, `app.forum.attribute(...)`) carries four public counts, refreshed by each sync:

| Attribute | Counts |
|---|---|
| `githubSponsorsPrivateRecurring` | Private sponsors with an active monthly sponsorship |
| `githubSponsorsPrivateOnetime` | Private sponsors who sponsored once or in the past |
| `githubSponsorsUnmatchedRecurring` | Public monthly sponsors with no matching forum account |
| `githubSponsorsUnmatchedOnetime` | Public one-time or past sponsors with no matching forum account |

Nothing about a private sponsor is stored: the sync reads their account IDs only to count each sponsor once.

### Sharing groups with other supporter sources

Supporter sources that manage the same groups ask each other before removing anyone. Each registers an invokable under the `fof.supporter-groups` container tag that takes user IDs and a group ID and returns the subset of those users it wants in that group:

```php
$container->tag([MySourceClaims::class], 'fof.supporter-groups');

class MySourceClaims
{
    /** @param array<int> $userIds @return array<int> */
    public function __invoke(array $userIds, int $groupId): array
    {
        // the users your source currently puts in $groupId
    }
}
```

The contract is a plain string tag, so sources need no shared package. Answer with what your source would actually do (for example, do not claim a one-time supporter who is already in the monthly group), and never call other sources from it. This extension's own answer comes from `FoF\GitHubSponsors\Services\SponsorGroupClaims`. Adopting a membership fires `FoF\GitHubSponsors\Event\SponsorMembershipAdopted` (`$user`, `$groupIds`).

### Upgrading from 1.x

`SponsorAdded` and `SponsorRemoved` were removed in 2.0. `SponsorAdded` maps to `RecurringSponsorshipStarted` or `OneTimeSponsorshipStarted`; `SponsorRemoved` maps to `RecurringSponsorshipStopped` or `SponsorshipEnded`.

## Troubleshooting

### No sponsors are being synced

- Verify your GitHub token has the correct scopes (`read:user`, `user:email` and `read:org`)
- Run the command manually: errors now say whether GitHub rejected the token, the token lacks a scope, or the account was not found
- Check that the account type and login match your GitHub Sponsors account
- Ensure the cron job is running (`schedule:run`)
- Check Flarum's log in `storage/logs/` for errors, and the audit log for changes

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
