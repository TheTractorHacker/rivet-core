# Ui

Namespace `RivetCore\Ui`. Two pure helpers for the editions' list and report screens: `DateRange`, a timezone-aware date-filter resolver, and `IconCatalog`, a curated Font Awesome icon list with validation for a visual icon picker. Core owns the data and the validation; each edition renders its own picker and filter controls.

## Overview

- Owns no tables, has no migration, and does no I/O. Both classes are static or immutable and need nothing from the edition.
- `DateRange` arrived in 0.20.0, `IconCatalog` in 0.19.0.

## Contracts an edition must implement

No interface. The edition provides:

- For `DateRange`: the request values (`canned_date`, `dtf`, `dtt`), the application timezone as a `DateTimeZone`, and the week start (1 = Monday to 7 = Sunday). Pass the same timezone the database session uses, so "today" matches the rest of the app.
- For `IconCatalog`: the picker UI (render `toJson()`, post back a value) and the column that stores the result. Store the output of `normalize()`, never the raw input. `IconCatalog::MAX_LENGTH` (50) matches a `varchar(50)` column; an edition that stores the bare name (for example `fire`) strips the `fa-` prefix itself.
- Font Awesome itself (free solid, version 5.15 or 6.x) loaded on the page.

## Key classes

### DateRange

An inclusive pair of calendar dates (`Y-m-d`). Day arithmetic is done on calendar dates, so DST days are still one day.

`DateRange::resolve(string $preset, ?string $from = null, ?string $to = null, ?DateTimeImmutable $now = null, ?DateTimeZone $tz = null, int $weekStart = 1): DateRange`

Presets (`DateRange::presets()` returns `[id, label, group]` rows in display order; `isPreset($id)` tests membership, including `custom` and `alltime`):

| Group | Ids |
|---|---|
| Quick | `today`, `yesterday` |
| Days | `last7`, `last14`, `last30`, `last90` (the N days ending today, inclusive) |
| Weeks | `thisweek`, `lastweek` (start day set by `$weekStart`) |
| Months | `thismonth`, `lastmonth`, `last12months` (first day of the month 11 months ago to today) |
| Quarters/Years | `thisquarter`, `lastquarter`, `thisyear`, `lastyear` |
| Upcoming | `next7`, `next30` (today through today + N - 1, for due dates) |
| Other | `alltime`, `custom` |

Ten ids are the legacy `canned_date=` values already in URLs and saved views: `today`, `yesterday`, `thisweek`, `lastweek`, `thismonth`, `lastmonth`, `thisyear`, `lastyear`, `alltime`, `custom`.

Methods: `preset()`, `from()`, `to()`, `isAllTime()`, `label()` (for example "Last 30 days", or "Mar 1 - Mar 31, 2026" for a custom range), `days()` (inclusive length, 0 for all time), `previous()`, `sqlBounds()`, `toQuery()`.

```php
use RivetCore\Ui\DateRange;

$tz = new DateTimeZone('America/Chicago');
$range = DateRange::resolve($_GET['canned_date'] ?? 'last30', $_GET['dtf'] ?? null, $_GET['dtt'] ?? null, null, $tz, 1);

$range->from();                 // '2026-09-07' (for last30 on 2026-10-06)
[$start, $end] = $range->sqlBounds();
// '2026-09-07 00:00:00', '2026-10-07 00:00:00'  ->  WHERE created_at >= ? AND created_at < ?
$compare = $range->previous();  // the 30 days before, as a custom range (compare to previous period)
$range->toQuery();              // ['canned_date' => 'last30']: presets stay rolling in saved views
```

Behavior worth knowing:

- Unknown or empty preset, or an invalid custom range, resolves to `alltime` (`1970-01-01` to `2099-12-31`). Years are clamped to 1970..2099. A reversed custom range is swapped. A custom range with only `from` means "on or after", with only `to` means "up to".
- `sqlBounds()` is half-open (`>= from 00:00:00` and `< day after to`), so queries can use an index instead of `DATE(column) BETWEEN`. The strings are in the caller's database session timezone; no conversion is done.
- `toQuery()` returns only `canned_date` for presets, and `canned_date=custom` plus `dtf` and `dtt` for a custom range, so a saved view of "last 30 days" keeps rolling.
- `previous()` steps by calendar unit for `lastmonth`, `lastquarter` and `lastyear`; the to-date presets (`thismonth`, `thisquarter`, `thisyear`) compare the same number of days from the start of the previous period (clamped to its end); everything else is the N days directly before `from`. All time returns itself. The result is always a custom range, so it round-trips through `toQuery()`.
- Pass `$now` in tests for a fixed date. With no `$now`, "today" is the current date in `$tz` (UTC if `$tz` is null).

### IconCatalog

621 curated Font Awesome free-solid icons in 14 categories (`general`, `tickets`, `status`, `people`, `devices`, `security`, `files`, `communication`, `money`, `time`, `places`, `tools`, `arrows`, `nature`), each with a label and search keywords. `IconCatalog::VERSION` (currently 1) is bumped whenever entries change, so editions can cache-bust the embedded JSON.

| Method | Returns |
|---|---|
| `categories()` | category key to label, in display order |
| `all()` | every icon as `['class', 'label', 'category', 'keywords']`; an icon in several categories appears once, in the first |
| `byCategory()` | the same entries grouped by category key (every category present, possibly empty) |
| `search($query, $category = null, $limit = 60)` | ranked matches: exact name or label, prefix, word prefix in the label, keyword, substring, keyword substring; ties keep catalog order; empty query returns the catalog; `$limit < 1` returns `[]` |
| `has($class)` | catalog membership only; expects a canonical `fa-xxx` class |
| `normalize($input, $default = 'fa-filter')` | one safe canonical `fa-xxx` class |
| `toJson()` | compact ASCII JSON for a client-side picker |

`normalize()` accepts `fa-fire`, `fas fa-fire`, `fa fa-fire`, `fa-solid fa-fire` and bare `fire` (trimmed, case-insensitive). Any syntactically valid class is accepted even when it is not curated, because administrators saved free-text classes before the picker existed. Empty, over-long (more than 50 characters) or invalid input (extra words, quotes, angle brackets, non-ASCII) returns `$default`. `has()` therefore answers "is it in the catalog" (for example to mark a stored icon as "custom" in the picker), while `normalize()` answers "is it safe to store and render".

```php
use RivetCore\Ui\IconCatalog;

$icon = IconCatalog::normalize($_POST['icon'] ?? '', 'fa-tag');     // 'fa-fire' from 'fas fa-fire' or 'fire'
IconCatalog::normalize('<b>x</b>', 'fa-tag');                       // 'fa-tag'
IconCatalog::has('fa-fire');                                        // true
IconCatalog::search('printer', null, 3);                            // [['class' => 'fa-print', 'label' => 'Printer', ...]]

// Embed for the picker script. toJson() is {"version":N,"categories":{...},"icons":[{c,l,g,k},...]}
// (c = class, l = label, g = category, k = keywords). Escape for a <script> block:
$json = str_replace(['<', '>', '&'], ['<', '>', '&'], IconCatalog::toJson());
```

## Configuration

None. Week start and timezone are arguments; the icon list is fixed per Core version.

## How it fails

Neither class throws on user input and neither logs.

- `DateRange`: bad input degrades to all time (see above), a bad `$weekStart` falls back to Monday (0 means Sunday).
- `IconCatalog`: bad input returns the default you supplied. `toJson()` uses `JSON_THROW_ON_ERROR`, but the catalog is static ASCII, so it does not fail in practice.

## Security notes

- `normalize()` is the only thing that should decide what is stored in an icon column. Its output matches `^fa-[a-z0-9]+(-[a-z0-9]+)*$` at most 50 characters, so it is safe to place in a `class` attribute; still escape on output as usual.
- Category labels contain `&` (for example "Tickets & support"). Escape `<`, `>` and `&` when embedding `toJson()` in a script tag, as in the example above.
- `DateRange` values from `from()` and `to()` are validated `Y-m-d` strings, and `sqlBounds()` output is meant for bound parameters, not string concatenation.

## Used by

- RivetMSP (`/home/sysadmin/rivetmsp-beta`, requires `^0.21`):
  - `DateRange`: `includes/date_range.php` (resolution in the app timezone, Monday week start), `includes/date_range_picker.php` (the preset popover), used by `includes/filter_header.php` for list and report filters.
  - `IconCatalog`: `includes/icon_picker.php` (picker field, embeds `toJson()` with `IconCatalog::VERSION`), `admin/post/custom_link.php`, `admin/post/tag_model.php`, `agent/post/tag_model.php` and `agent/post/ticket_saved_view.php` (store `normalize()` output), with the pickers in the add and edit modals for tags, custom links and saved ticket views.
- RivetIT (`/var/www/mw-itflow.foleyit.com`): not used. It requires Core `^0.18`, which predates this module, and no source file references `RivetCore\Ui`.

## Links

- [CHANGELOG](../../CHANGELOG.md): 0.19.0 (`IconCatalog`), 0.20.0 (`DateRange`).
- [modules overview](README.md).
- Tests: `tests/Unit/DateRangeTest.php`, `tests/Unit/IconCatalogTest.php`.
