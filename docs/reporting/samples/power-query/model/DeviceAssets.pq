// DeviceAssets: one row per asset with its newest device and that device's client.
// Needed only when the health metrics come from the PostgreSQL store: rpt_device_health_hourly / _daily there have asset_id and no device_id or client_id.
// Relate DeviceAssets[asset_id] (one) to rpt_device_health_hourly[asset_id] and rpt_device_health_daily[asset_id] (many), and DeviceAssets[device_id] to rpt_devices[device_id].
// NOT VERIFIED in Power BI Desktop (structure-checked only). "rpt_devices" is the query you loaded from the MariaDB / MySQL connection (see ../mysql-mariadb/rpt_devices.pq).
let
    Linked = Table.SelectRows(rpt_devices, each [asset_id] <> null),
    Sorted = Table.Buffer(Table.Sort(Linked, {{"device_id", Order.Descending}})),
    Newest = Table.Distinct(Sorted, {"asset_id"}),
    Slim = Table.SelectColumns(Newest, {"asset_id", "device_id", "client_id"})
in
    Slim
