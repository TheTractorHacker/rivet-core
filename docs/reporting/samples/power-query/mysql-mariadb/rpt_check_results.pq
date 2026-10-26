// rpt_check_results: one row per recorded check result.
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_check_results"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"result_id", Int64.Type}, {"device_id", Int64.Type}, {"client_id", Int64.Type}, {"check_name", type text}, {"status", type text}, {"reported_at", type datetime}}, "en-US")
    // INCREMENTAL REFRESH (optional, append-only history): create two Date/Time parameters named RangeStart and RangeEnd, add a comma after the Typed step,
    // uncomment the next line and change the line after "in" to Filtered:
    // Filtered = Table.SelectRows(Typed, each [reported_at] >= RangeStart and [reported_at] < RangeEnd)
in
    Typed
