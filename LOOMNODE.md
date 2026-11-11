# LoomNode's Pelican Panel

This is a modified version of [Pelican Panel](https://github.com/pelican/panel), run by
[LoomNode](https://loomnode.cloud) as the game panel at panel.loomnode.cloud. It is licensed,
like Pelican, under the GNU Affero General Public License v3.0 only (see LICENSE).

- **Branch `loomnode`**: Pelican plus LoomNode's changes. It currently builds on Pelican v1.0.0-rc.2.
- **Tags `loomnode-<pelican version>.<n>`**: the exact code of each build that ran on panel.loomnode.cloud.
  The Panel's footer links to the tag that is running.
- `main` is upstream Pelican, untouched.

## What LoomNode changed

First made 2026-09-28. Each change is its own commit on `loomnode`; `git log v1.0.0-rc.2..loomnode` shows them all.

- 2026-09-28: Fix backup/database creation on PostgreSQL
- 2026-09-28: Evaluate schedule cron expressions in America/Chicago
- 2026-09-28: Enforce node resource limits on every server create and resize
- 2026-09-28: Add a power endpoint to the Application API
- 2026-09-28: Fix node updates that set a value back to its default
- 2026-09-20: Fix daemon deauthorize and cancel transfer requests to match Wings API (#2576)
- 2026-10-10: Show the server start command to admins only
- 2026-10-11: Import schedules from a URL: admins only
- 2026-10-11: Backup downloads: admins only
- 2026-10-14: Sign-in links and shared passkeys for an external customer site
- 2026-10-16: Server panel: "Startup" reads "Game Settings" for customers
- 2026-10-16: Game Settings: hide the start command row from customers
- 2026-10-20: Game Settings: show variable env names and rules to admins only
- 2026-10-21: Game Settings: only the game's settings, for admins too
- 2026-10-22: Game Settings: a dropdown saves the moment a choice is picked
- 2026-10-22: Activity log: a password, token or key setting is kept only as set or empty
- 2026-10-23: Game Settings: a typed value saves when you click away, even to a page link
- 2026-10-24: Node capacity check: a setting to switch it off, off in the test suite, and tests
- 2026-10-24: Schedules: every schedule runs in one panel timezone (America/Chicago)
- 2026-10-24: Power endpoint: reinstall() gets its own docs back; imports sorted
- 2026-11-01: Game Settings: one fold-out section per setting group
- 2026-11-01: Console: a server's owner sees its install output
- 2026-11-08: Footer: link to this Panel's source code (AGPL-3.0 section 13)
- 2026-11-08: this file
- 2026-11-10: Game Settings: a Mods button (a Modrinth browser for Minecraft server mods) and an Update modpack button

Two of the early changes were reverted before Pelican v1.0.0-rc.2 was merged, because upstream had
replaced them: the PostgreSQL fix by Pelican's own (#2592), and the America/Chicago schedule change by
Pelican's schedule timezone code (#2626), with LoomNode's America/Chicago default added back on
2026-10-24 (above). The 2026-09-20 Wings fix was picked early from upstream (#2576) and is identical to it.

Questions: support@loomnode.cloud
