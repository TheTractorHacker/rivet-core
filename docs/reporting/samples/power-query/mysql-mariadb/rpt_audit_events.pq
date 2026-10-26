// rpt_audit_events: one row per audit event (summary only).
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_audit_events"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"audit_id", Int64.Type}, {"event_type", type text}, {"action", type text}, {"entity_type", type text}, {"actor_user_id", Int64.Type}, {"created_at", type datetime}}, "en-US")
    // INCREMENTAL REFRESH (optional, append-only history): create two Date/Time parameters named RangeStart and RangeEnd, add a comma after the Typed step,
    // uncomment the next line and change the line after "in" to Filtered:
    // Filtered = Table.SelectRows(Typed, each [created_at] >= RangeStart and [created_at] < RangeEnd)
in
    Typed
