# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

A procedural PHP "Travel Journal" web app served under XAMPP (Apache + MySQL). No framework, no
package manager, no build step, no tests. Each page is a standalone `.php` file that mixes a PHP
header (DB queries + `$_POST`/`$_GET` handling) with an inline HTML template based on the
"Multikart" Bootstrap 4 admin theme.

## Running

- Serve via XAMPP: place this directory under `c:\xampp\htdocs\` (it already is as
  `travel_journel`) and browse to `http://localhost/travel_journel/login.php`.
- Start Apache + MySQL from the XAMPP control panel; there is no CLI entry point.
- DB connection is hardcoded in [connection.php](connection.php) (and duplicated inside
  [mail.php](mail.php)): host `localhost`, user `root`, empty password, database `project`.
- There is **no schema/seed file in the repo.** The MySQL database `project` and its tables must
  already exist in the XAMPP MySQL instance. Tables referenced by the code:
  - `signup` — users: `fname, lname, eml, psw` (plaintext password).
  - `db` — journal entry core: `Title, Description, Country, City, cd, dv, dr, hn, address, ptv, tv, eml`.
  - `db1` — expenses (breakfast/lunch/…, travel costs, `subTotal`, `total`, title, `eml`).
  - `db2`, `db3` — later steps of the same entry (further trip details).
  An entry is keyed by its `Title` + owning `eml` across all four tables.

## Architecture

**Auth / session.** `$_SESSION['eml']` (the user's email) is the identity carried everywhere and
is the foreign key used in every per-user query. It is set on login ([login.php](login.php)) and
registration ([register.php](register.php)). [session.php](session.php) is a guard that redirects
to `login.php` when no email is in session — but note it `include()`s `login.php` to do so, so it
is heavyweight; most pages instead just read `$_SESSION['eml']` directly after their own
`session_start()`.

**The multi-step "new entry" wizard.** Creating one journal entry spans four pages, each inserting
into a different table and redirecting to the next:
`dashboard.php` (→ `db`) → `dashboard1.php` (→ `db1`) → `dashboard2.php` (→ `db2`) →
`dashboard3.php` (→ `db3`) → `view-list.php`. State is threaded between steps via
`$_SESSION['lastInsertedTitle']`, set from `$_POST['title']` on the first step. Each step's form
posts `name="next"` to trigger its own `INSERT`. `dashboard1.php` uses a positional
`INSERT INTO db1 VALUES(...)`, so column order in the table must exactly match the code.

**Other pages.**
- [pc.php](pc.php) — "View My Entries": lists rows from `db` filtered by session `eml`, with
  View/Edit/Delete links.
- [action.php](action.php) — fetches a single entry (across `db`/`db1`/`db2`/`signup`) by
  `$_GET['title']`; used as a data-loading include.
- [profile.php](profile.php), [change-pswd.php](change-pswd.php),
  [forgot-pswd.php](forgot-pswd.php) — account pages.
- [mail.php](mail.php) — password recovery, emails the stored plaintext password via PHPMailer
  (`require 'PHPMailer-master/PHPMailerAutoload.php'` — that vendor dir is not present in this
  checkout).

**Shared partials.** [header.php](header.php), [footer.php](footer.php), [logo.php](logo.php),
[db.php](db.php) exist but most pages inline their own full HTML `<head>`/nav/sidebar rather than
including these, so editing a partial usually won't change most pages.

**Assets.** `css/` and `js/` hold the Multikart theme (Bootstrap, jQuery 3.3.1, feather icons,
jsgrid, dropzone, chartist). Several templates reference `../assets/images/dashboard/...`, i.e.
one level *above* this folder — those image paths assume a parent `assets/` dir.

## Important characteristics to preserve or fix deliberately

- **SQL is built by string interpolation of `$_POST`/`$_GET` throughout** (e.g. `action.php`,
  `login.php`, `register.php`, `dashboard*.php`) — every query is SQL-injectable. Passwords are
  stored and emailed in plaintext. This is the existing pattern; if asked to add a feature, follow
  the surrounding style unless the task is explicitly to harden it.
- `error_reporting(0)` is set at the top of most pages, so PHP warnings are silently suppressed —
  a broken query fails invisibly. Temporarily remove it when debugging.
- Several linked pages are **referenced but do not exist** in this checkout: `view-list.php`,
  `display.php`, `update.php`, `delete.php`, `das.php`. Links to them will 404. Create them if a
  task depends on them.
- The DB dropdown/nav in each page is largely hardcoded duplicated markup; there is no single
  source of truth for navigation.
