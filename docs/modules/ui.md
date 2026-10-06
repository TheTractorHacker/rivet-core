# UI helpers

`RivetCore\Ui\IconCatalog` and `RivetCore\Ui\DateRange`: framework-free helpers for list and report screens.

## What they own

No tables. `IconCatalog` embeds 621 curated Font Awesome free-solid icons in 14 categories. `DateRange` holds the preset ids.

## You supply

The picker or filter UI. Render `IconCatalog::toJson()` for a client-side picker and store `normalize()` output. Feed `DateRange::resolve()`
the request's `canned_date`, `dtf`, `dtt`, your time zone and week start.

## Flags

None.

## Use it

<!-- run -->
```php
use RivetCore\Ui\{DateRange, IconCatalog};

$range = DateRange::resolve('last30', null, null, new DateTimeImmutable('2026-10-05 12:00:00', new DateTimeZone('America/Chicago')), new DateTimeZone('America/Chicago'));
[$from, $to] = $range->sqlBounds();           // half-open: created_at >= $from AND created_at < $to (index friendly)
echo $range->label(), ": $from .. $to; icon=", IconCatalog::normalize('fas fa-fire'), "\n";
```

## How they fail

Neither throws on bad input. `IconCatalog::normalize()` returns the default for empty or invalid input and keeps any syntactically
valid `fa-*` class (so icons an admin saved before still work); `has()` is catalog membership only. `DateRange` resolves an unknown preset
or invalid custom dates to all time, swaps reversed dates and clamps years to 1970..2099; DST-safe calendar-date math.
