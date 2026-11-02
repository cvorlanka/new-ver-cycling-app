# poapis

The PO requisition API, built with Laravel 12 on PHP 8.3. It calls the view and stored procedures in Microsoft SQL Server 2008. There is no authentication.

For server setup (Ubuntu, Apache 2, SQL Server drivers), see [DEPLOYMENT.md](DEPLOYMENT.md).

## Endpoints

All of them are in `App\Http\Controllers\POController`.

| Method | Path | Calls | Response |
|---|---|---|---|
| GET | `/api/getData` | `SELECT * FROM cvo_poreq_vw` | JSON array of rows |
| POST | `/api/createPOReq` | `cvo_poreq_upd_sp 'ins', …` | text `true` |
| POST | `/api/updatePOReq` | `cvo_poreq_upd_sp 'upd', …` | JSON rows returned by the SP |
| POST | `/api/clonePOReq` | `cvo_poreq_upd_sp 'ins', …` | JSON rows returned by the SP |
| POST | `/api/deletePOReq` | `cvo_poreq_upd_sp 'upd', …` per row, with `po_qty=0`, `buy_flag='N'` | text `Delete List: 1 - 2` |
| POST | `/api/submitPOReq` | `cvo_ifp_poreq_po_sp '<ids>', 'POREQ', 1, '<user>', 0` | text `SUBMIT: 1,2` or `NONE` |
| GET | `/api/health/db` | `SELECT @@VERSION` | used to check the connection |

Each client IP is limited to 60 requests per minute.

### Request bodies

`createPOReq`, `updatePOReq` and `clonePOReq` take one row:

```json
{
  "row_id_po": 101,
  "GROUP_NO": "G1",
  "part_no": "P-1",
  "po_qty": 5,
  "buy_flag": "Y",
  "demand_date": "2026-11-01",
  "location": "001",
  "vendor_no": "V100",
  "unit_cost": 2.5
}
```

Empty or missing optional fields reach the SP as the text `'NULL'`, as in the original controller.

`deletePOReq` and `submitPOReq` take an array of rows. Submit only reads `row_id_po` from each row:

```json
[ { "row_id_po": 101 }, { "row_id_po": 102 } ]
```

The login that submit passes to `cvo_ifp_poreq_po_sp` defaults to `cvoptical\bmurphy`. To change it, set `POREQ_SUBMIT_USER` in `.env` (in single quotes).

## SQL Server 2008 notes

- Laravel's `paginate()` / `skip()` emit `OFFSET … FETCH`, which SQL Server 2008 doesn't support. Don't use them. Use `ROW_NUMBER()` or `take()` (which becomes `TOP n`).
- The app doesn't run migrations against SQL Server. Cache uses files, sessions use an in-memory array, and queues run synchronously (`sync`).

## Local development (Laragon)

```powershell
$php = "C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe"
& $php artisan test          # tests mock the database; no SQL Server needed
& $php artisan serve
```

To reach the real SQL Server from Windows, install the Microsoft ODBC Driver 17 and the `php_pdo_sqlsrv_83_ts_x64.dll` / `php_sqlsrv_83_ts_x64.dll` extensions in Laragon's PHP.
