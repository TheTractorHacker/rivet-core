# Samples for Power BI

| File | What |
|---|---|
| [power-query/mysql-mariadb/*.pq](power-query/mysql-mariadb/) | One Power Query (M) per view for a MySQL / MariaDB source (17 views). **Generated** from the view catalog. |
| [power-query/postgresql/*.pq](power-query/postgresql/) | The same for the PostgreSQL RMM store (the 6 hot views, when `data_store = postgres`). **Generated.** |
| [power-query/model/DeviceAssets.pq](power-query/model/DeviceAssets.pq), [Clients.pq](power-query/model/Clients.pq) | Helper queries for the model: one device per asset (PostgreSQL health tables), the client dimension for row-level security. |
| [measures.dax](measures.dax) | DAX measures over the Core views: device availability %, patch compliance %, mean time to resolve / acknowledge, alerts per 100 devices, top noisy checks, storage growth per day, active devices in the last 7 days, and more. |
| [measures-edition.dax](measures-edition.dax) | Measures that need the edition's tickets and SLA views: tickets per 100 devices, SLA breach rate, ticket resolution time. |
| [STARTER_REPORT.md](STARTER_REPORT.md) | A step-by-step first report: pages, visuals, slicers. |

How to use a `.pq` file: create two text parameters `ServerName` (`host:3306` / `host:5432`) and `DatabaseName` (*Home > Transform data > Manage parameters*), then *New source > Blank query > Advanced editor*, paste the file, and name the query after the view (`rpt_devices`). The full walk-through is in [../POWER_BI.md](../POWER_BI.md).

The `.pq` files are written by `php scripts/reporting-docs.php` from `src/Reporting/ReportingCatalog.php`; edit the catalog, not the files. **None of these files could be run in Power BI Desktop when they were written** (no Power BI available): a test checks the structure (balanced brackets, the navigation target, every typed column exists in the view) and the views' SQL runs against real MariaDB, MySQL and PostgreSQL. If Power BI rejects a file, report the error text.
