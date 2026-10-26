// rpt_check_results: one row per recorded check result.
// Source: the PostgreSQL RMM data store (data_store = postgres). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:5432", DatabaseName = "your database".
let
    Source = PostgreSQL.Database(ServerName, DatabaseName, [CreateNavigationProperties = false]),
    Rows = Source{[Schema = "public", Item = "rpt_check_results"]}[Data],
    Utc = Table.TransformColumns(Rows, {{"reported_at", each if _ is datetimezone then DateTimeZone.RemoveZone(DateTimeZone.SwitchZone(_, 0)) else _, type nullable datetime}}),
    Typed = Table.TransformColumnTypes(Utc, {{"result_id", Int64.Type}, {"device_id", Int64.Type}, {"check_name", type text}, {"status", type text}}, "en-US")
    // INCREMENTAL REFRESH (optional, append-only history): create two Date/Time parameters named RangeStart and RangeEnd, add a comma after the Typed step,
    // uncomment the next line and change the line after "in" to Filtered:
    // Filtered = Table.SelectRows(Typed, each [reported_at] >= RangeStart and [reported_at] < RangeEnd)
in
    Typed
