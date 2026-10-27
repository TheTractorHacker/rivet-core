// rpt_patch_history: one row per patch action recorded.
// Source: the PostgreSQL RMM data store (data_store = postgres). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:5432", DatabaseName = "your database".
let
    Source = PostgreSQL.Database(ServerName, DatabaseName, [CreateNavigationProperties = false]),
    Rows = Source{[Schema = "public", Item = "rpt_patch_history"]}[Data],
    Utc = Table.TransformColumns(Rows, {{"occurred_at", each if _ is datetimezone then DateTimeZone.RemoveZone(DateTimeZone.SwitchZone(_, 0)) else _, type nullable datetime}}),
    Typed = Table.TransformColumnTypes(Utc, {{"patch_history_id", Int64.Type}, {"device_id", Int64.Type}, {"client_id", Int64.Type}, {"update_id", type text}, {"title", type text}, {"action", type text}, {"result", type text}, {"code", type text}}, "en-US")
    // INCREMENTAL REFRESH (optional, append-only history): create two Date/Time parameters named RangeStart and RangeEnd, add a comma after the Typed step,
    // uncomment the next line and change the line after "in" to Filtered:
    // Filtered = Table.SelectRows(Typed, each [occurred_at] >= RangeStart and [occurred_at] < RangeEnd)
in
    Typed
