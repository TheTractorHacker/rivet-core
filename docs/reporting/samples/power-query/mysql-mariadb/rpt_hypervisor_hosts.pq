// rpt_hypervisor_hosts: one row per hypervisor host device.
// Source: the control database (MySQL or MariaDB). NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.
// Parameters (Home > Manage parameters, type Text): ServerName = "host:3306", DatabaseName = "your database".
let
    Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false]),
    Rows = Source{[Schema = DatabaseName, Item = "rpt_hypervisor_hosts"]}[Data],
    Typed = Table.TransformColumnTypes(Rows, {{"device_id", Int64.Type}, {"client_id", Int64.Type}, {"platform", type text}, {"version", type text}, {"cluster_name", type text}, {"quorate", Int64.Type}, {"failed_groups", Int64.Type}, {"vm_count", Int64.Type}, {"is_truncated", Int64.Type}, {"last_collected_at", type datetime}, {"last_report_at", type datetime}, {"ignored", Int64.Type}}, "en-US")
in
    Typed
