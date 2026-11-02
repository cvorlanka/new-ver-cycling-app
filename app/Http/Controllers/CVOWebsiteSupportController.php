<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CVOWebsiteSupportController extends Controller
{
    /**
     * GET /magento/lab/{lab}
     */
    public function validateLab(string $lab)
    {
        $results = DB::select("SELECT customer_code, address_name, addr2, addr3, addr4, city, state, postal_code, country_code, contact_name
            FROM armaster
            WHERE customer_code = ? AND addr_sort1 LIKE '%Lab%'", [$lab]);

        return response()->json($results);
    }

    /**
     * POST /magento/add_lab
     */
    public function addCustomerLab(Request $request)
    {
        $data = $request->validate([
            'account_no' => 'required',
            'lab_code' => 'required',
        ]);

        return DB::table('cvo_web_account_labs')->insertGetId([
            'account_no' => $data['account_no'],
            'lab_code' => $data['lab_code'],
        ]);
    }
}
