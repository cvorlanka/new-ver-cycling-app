<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class PackingListController extends Controller
{
    /**
     * GET /dc/order/{order_no}
     *
     * {order_no} is "<order_no>-<order_ext>", e.g. "1234567-0".
     */
    public function getOrder(string $order_no)
    {
        $results = DB::select("SELECT order_no, order_ext, carton_no, carton_type, carton_class, cust_code,
                CASE WHEN cust_po IS NULL THEN CONVERT(varchar(20), order_no) ELSE CONVERT(varchar(20), cust_po) END AS cust_po,
                carrier_code, shipper, ship_to_no, name, address1, address2, address3, city, state, zip, country,
                attention, date_shipped, weight, weight_uom, cs_tx_no, cs_tracking_no, cs_zone, cs_oversize,
                cs_call_tag_no, cs_airbill_no, cs_other, cs_pickup_no, cs_dim_weight, cs_published_freight,
                cs_disc_freight, cs_estimated_freight, cust_freight, freight_to, adjust_rate, template_code,
                operator, consolidated_pick_no, void, status, station_id, charge_code, bill_to_key,
                last_modified_date, modified_by, order_type, stlbin_no, stl_status, changed,
                carton_content_value, carton_tax_value, carton_seq, tag_no, lic_no, carton_cnt, SSCC,
                tag_type, epc_tag, SGTIN
            FROM TDC_CARTON_TX
            WHERE CONVERT(varchar(10), order_no) + '-' + CONVERT(varchar(4), order_ext) = ?", [$order_no]);

        return response()->json($results);
    }

    /**
     * GET /dc/order_lines/{order_no}
     *
     * {order_no} is "<order_no>" or "<order_no>-<order_ext>"; without an ext, lines for all exts are returned.
     */
    public function getOrderLines(string $order_no)
    {
        [$orderNumber, $orderExtension] = array_pad(explode('-', $order_no, 2), 2, '');

        $query = 'SELECT d.order_no, d.order_ext, d.line_no, d.part_no, cx.cust_part, inv.description, CONVERT(int, SUM(pack_qty)) as pack_qty
            FROM tdc_CARTON_DETAIL_TX d
            LEFT JOIN orders o WITH (NOLOCK) ON (d.order_no = o.order_no AND d.order_ext = o.ext)
            LEFT JOIN cust_xref cx WITH (NOLOCK) ON (d.part_no = cx.part_no AND o.cust_code = cx.customer_key)
            LEFT JOIN inv_master inv WITH (NOLOCK) ON (d.part_no = inv.part_no)
            WHERE d.order_no = ?';
        $bindings = [$orderNumber];

        if ($orderExtension !== '') {
            $query .= ' AND d.order_ext = ?';
            $bindings[] = $orderExtension;
        }

        $query .= ' GROUP BY d.order_no, d.order_ext, d.line_no, d.part_no, cx.cust_part, inv.description
            ORDER BY d.line_no';

        return response()->json(DB::select($query, $bindings));
    }
}
