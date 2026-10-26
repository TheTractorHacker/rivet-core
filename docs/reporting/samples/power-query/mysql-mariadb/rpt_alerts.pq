// rpt_alerts: one row per alert episode (open and history).
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_alerts"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"alert_row_id", Int64.Type}, {"alert_id", Int64.Type}, {"device_id", Int64.Type}, {"client_id", Int64.Type}, {"check_name", type text}, {"episode", Int64.Type}, {"severity", type text}, {"state", type text}, {"is_open", Int64.Type}, {"opened_at", type datetime}, {"acked_at", type datetime}, {"resolved_at", type datetime}, {"resolve_reason", type text}, {"duration_s", Int64.Type}, {"time_to_ack_s", Int64.Type}}, "en-US")
in
    Typed
