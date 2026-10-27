// rpt_software_inventory: one row per device and installed software item (current).
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_software_inventory"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"device_id", Int64.Type}, {"client_id", Int64.Type}, {"software_name", type text}, {"source", type text}, {"version", type text}, {"publisher", type text}, {"installed_on", type date}, {"first_seen_at", type datetime}, {"last_seen_at", type datetime}}, "en-US")
in
    Typed
