// rpt_device_groups: one row per device per group it belongs to.
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_device_groups"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"device_id", Int64.Type}, {"client_id", Int64.Type}, {"group_id", Int64.Type}, {"group_name", type text}}, "en-US")
in
    Typed
