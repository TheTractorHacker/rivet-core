// Clients: the dimension that row-level security filters (one row per client). Names are not in RivetCore: add them with a lookup you type in
// (Home > Enter data: client_id, client_name) and merge it here, or merge the edition's rpt_clients once the edition ships it.
// NOT VERIFIED in Power BI Desktop (structure-checked only). "rpt_devices" is the query you loaded from the MariaDB / MySQL connection.
let
    Ids = Table.SelectColumns(rpt_devices, {"client_id"}),
    Distinct = Table.Distinct(Ids),
    Typed = Table.TransformColumnTypes(Distinct, {{"client_id", Int64.Type}})
in
    Typed
