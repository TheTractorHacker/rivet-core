// rpt_patch_history: one row per patch action recorded.
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_patch_history"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"patch_history_id", Int64.Type}, {"device_id", Int64.Type}, {"client_id", Int64.Type}, {"update_id", type text}, {"title", type text}, {"action", type text}, {"result", type text}, {"code", type text}, {"occurred_at", type datetime}}, "en-US")
    // INCREMENTAL REFRESH (optional, append-only history): create two Date/Time parameters named RangeStart and RangeEnd, add a comma after the Typed step,
    // uncomment the next line and change the line after "in" to Filtered:
    // Filtered = Table.SelectRows(Typed, each [occurred_at] >= RangeStart and [occurred_at] < RangeEnd)
in
    Typed
