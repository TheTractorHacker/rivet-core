// rpt_device_health_hourly: one row per asset, metric, instance and UTC hour.
// Source: the PostgreSQL RMM data store (data_store = postgres). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:5432", DatabaseName = "your database".
let
    Source = PostgreSQL.Database(ServerName, DatabaseName, [CreateNavigationProperties = false]),
    Rows = Source{[Schema = "public", Item = "rpt_device_health_hourly"]}[Data],
    Utc = Table.TransformColumns(Rows, {{"hour_start", each if _ is datetimezone then DateTimeZone.RemoveZone(DateTimeZone.SwitchZone(_, 0)) else _, type nullable datetime}}),
    Typed = Table.TransformColumnTypes(Utc, {{"asset_id", Int64.Type}, {"metric_name", type text}, {"instance", type text}, {"samples", Int64.Type}, {"sum_value", type number}, {"min_value", type number}, {"max_value", type number}, {"avg_value", type number}}, "en-US")
    // INCREMENTAL REFRESH (optional, append-only history): create two Date/Time parameters named RangeStart and RangeEnd, add a comma after the Typed step,
    // uncomment the next line and change the line after "in" to Filtered:
    // Filtered = Table.SelectRows(Typed, each [hour_start] >= RangeStart and [hour_start] < RangeEnd)
in
    Typed
