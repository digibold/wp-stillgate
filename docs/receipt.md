# The freeze receipt

A freeze is only useful if you can tell afterwards that it held. The receipt
answers one question for every freeze: **did anything change in the content
while the freeze was on?**

It is a detector. It only reads, never writes, and never moves data between
environments. It is the safe half of the problem that makes replacing a
database risky: you find out whether the copy you took at the start is still
the complete truth, before you overwrite anything.

## What it records

A receipt opens when a freeze begins and closes when it ends. It stays until
the next freeze replaces it. It reports:

| Field | Meaning |
| --- | --- |
| `state` | `none` (no freeze has run), `open` (a freeze is running) or `closed` |
| `opened_at`, `closed_at` | When the freeze began and ended (UTC, ISO 8601) |
| `source` | What began it: `ui`, `cli`, `rest`, or `detected` (a scheduled window or `DEPLOYMENT_GUARD_FORCE` noticed later) |
| `clean` | `true` when no post changed, nothing was deleted, no term changed and no comment was added; `false` when something did; `null` when nothing can be promised (no freeze has run, or the window was never observed) |
| `unverified` | `true` only for a scheduled window that began and ended without any request that could record it; see below |
| `content.changed_posts` | Posts of the protected types (posts, pages, media, custom post types) saved, created or trashed since the freeze began |
| `content.changed_ids` | Their IDs, at most 200. `ids_truncated` says when there were more |
| `content.deleted_estimate` | Estimated number of posts permanently deleted |
| `content.terms_changed` | Whether categories, tags or other terms changed |
| `content.comments_added` | Comments added since the freeze began |
| `paused.comments`, `paused.forms` | Visitor comments and form submissions refused because the freeze covered them |

A receipt opened by a scheduled window takes its start from the schedule, not
from when Stillgate noticed it. A freeze that began on a schedule is noticed
on the next admin, API or WP-CLI request; nothing here runs on an ordinary
visitor page view. The content is compared from the moment it was noticed, so
a post deleted between the window's start and that moment is not seen.

A scheduled window that began and ended without any such request in between
(a 02:00 window on a quiet site, with nobody in wp-admin) leaves no way to say
what changed. It is recorded afterwards as **unverified**: a closed receipt
with `unverified: true` and `clean: null`, so an older clean receipt is never
mistaken for it and `receipt --check` fails.

A receipt covers only the time between `opened_at` and `closed_at`. What
happens after the freeze has ended is not in it, even when it says clean.

## Reading it

In wp-admin, Tools > Stillgate shows the receipt card, with links to the
changed posts. From a script:

```bash
wp stillgate receipt                  # table
wp stillgate receipt --format=json
wp stillgate receipt --check          # exits 1 unless the receipt is clean
wp stillgate drift                    # changed post IDs, comma separated
```

```bash
curl -fsS -u "$AUTH" https://example.com/wp-json/stillgate/v1/receipt
```

## Using it in a deployment

The common risky case is replacing a database: you copy production to
staging, work on staging, then push the staging database back. Anything saved
on production in between is overwritten.

```bash
wp stillgate enable --for=4h             # lock editing; extend it if the work runs long
wp db export before.sql                  # the copy you take to staging

# ... work on staging ...
wp stillgate extend 1h                   # whenever the work is taking longer than planned

# Still frozen? Check now, before the freeze ends and before the database
# is replaced:
if ! wp stillgate receipt --check; then
    wp stillgate drift                   # which posts
    wp export --post__in="$(wp stillgate drift)" --dir=/backups
    # Stop here and decide. Do not overwrite production yet.
fi
```

`--check` passes only for a clean receipt. It fails when something changed,
when the window was never observed, and when no freeze has run.

**The freeze has to be on, or have been on for all of the work.** The receipt
covers the time between its start and its end. If the timer runs out while you
are still working on staging, editors are free again, and anything they save
after that is not in the receipt: it can say clean and still be wrong. Make
the freeze last as long as the work (`wp stillgate extend`, or a manual
`wp stillgate enable` that you end yourself), and run the check before you end
it.

Read the receipt **before** the database is replaced. Stillgate keeps its
state in the database, so importing a database from another environment
replaces the receipt along with everything else. Take the dump after the
freeze begins, so the receipt covers exactly the time since the copy was made.

When the receipt is clean and the freeze was on the whole time, the copy you
took is still complete as far as the receipt can see (see below for what it
cannot) and replacing the database loses nothing. When it is not clean, you
have a precise list instead of a surprise.

## What it does not do

This is deliberately limited. Read it before relying on it.

- **It does not synchronize databases, and cannot be made to.** Merging two
  databases that both changed needs knowledge of every plugin's tables and of
  which side wins each conflict. A plugin that guessed would eventually
  overwrite the wrong thing. Stillgate stops at telling you what changed.
- **The export is a review aid, not an automatic merge.** `wp export` and
  `wp import` carry posts with their meta, terms and comments, but WordPress's
  importer decides whether a post already exists by comparing title, content
  and date, so an edited post usually arrives as a second copy instead of
  updating the first. New posts import cleanly; edits need a person to apply
  them.
- **Posts are compared by modification time, and by ID for new ones.** That
  is exact for anything saved through WordPress, including a brand-new draft,
  which WordPress stores with a zero modified time until it is saved again. A change made straight in the database that does
  not touch `post_modified_gmt` is invisible, and so is a change to post meta
  alone.
- **A receipt noticed late is closed as of when the freeze ended.** Posts
  modified or created after that are not blamed on it. Deletions and term
  changes cannot be dated, so they are as of when it was noticed.
- **WordPress can write on its own.** A block theme creates its fallback
  Navigation menu the first time it is shown, for example, and that is a
  change in protected content like any other. The receipt lists it, and you
  can tell from its title that nobody edited anything.
- **Deletions are estimated** from row counts, since a deleted row leaves
  nothing to list.
- **Terms are compared by a checksum.** The receipt tells you that terms
  changed, not which ones.
- **Only content is covered.** Options, users, orders kept in custom tables,
  and anything else a plugin stores itself are not inspected. Stock levels and
  similar counters on a shop legitimately change during a freeze and will
  show up as changes.
- **At most 200 IDs are listed.** The count is always exact.
- **A freeze that did not cover comments or forms** (see Coverage in the
  settings screen) does not stop them, and comments added are counted so you
  can see them.
- **A refused submission is counted per request.** A form plugin that sends
  two requests for one attempt counts twice.

## Storage

Three options per site, none autoloaded. `deployment_guard_receipt` holds
timestamps, counts and post IDs. `deployment_guard_paused_comments` and
`deployment_guard_paused_forms` are running totals of refused submissions: each
refusal is one atomic increment of one small row, so concurrent refusals are all
counted and a refusal never rewrites the receipt. A receipt reports the totals
now (or when it closed) less the totals when it opened. None of it holds
content, titles or personal data. All three are removed when the plugin is
uninstalled, and the receipt is replaced by the next freeze.
