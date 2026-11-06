<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DCUtilsController extends Controller
{
    /**
     * GET /case_label
     */

    /*
    public function printLabel()
    {
        $results = DB::select('SELECT o.order_no, o.scan_user, 1 as num_labels
            FROM cvo_cart_scan_orders o
            JOIN cvo_case_barcode_log l WITH (NOLOCK) ON (o.order_no = l.order_no)
            WHERE l.is_label_printed = 0');

        return response()->json($results);
    }

    /**
     * POST /confirm_label
     */
    /*
    public function confirmLabel(Request $request)
    {
        DB::table('cvo_case_barcode_log')
            ->where('order_no', $request->input('order_no'))
            ->update([
                'is_label_printed' => $request->input('is_printed'),
                'num_labels_printed' => $request->input('num_labels'),
                'label_date' => @date("Y-m-d H:i:s")
            ]);
    }
    */

    public function printLabel()
    {
        //$zipcode = $request->input('zip');
        //$results = DB::select("SELECT TOP 1 order_no, scan_user, 1 as num_labels FROM cvo_cart_scan_orders");
        $results = DB::select("SELECT o.order_no, o.scan_user, 1 as num_labels 
        FROM cvo_cart_scan_orders o
        JOIN cvo_case_barcode_log l WITH (NOLOCK) 
        ON (o.order_no = l.order_no) 
        WHERE l.is_label_printed = 0");
        $results = json_encode((array) $results);
        return $results;
    }

    public function confirmLabel(Request $request)
    {
        $orderNo   = $request->input('order_no');
        $printFlag  = $request->input('is_printed');
        $numLabels = $request->input('num_labels');
        DB::table('cvo_case_barcode_log')
            ->where('order_no', $orderNo)
            ->update(['is_label_printed' => $printFlag, 'is_label_printed' => $numLabels, 'label_date' => @date("Y-m-d H:i:s")]);
        //DB::insert("INSERT INTO cvo_case_barcode_log (order_no, is_label_printed, num_labels_printed) VALUES (?, ?, ?)", [$orderNo, $printFlag, $numLabels]);
    }
}
