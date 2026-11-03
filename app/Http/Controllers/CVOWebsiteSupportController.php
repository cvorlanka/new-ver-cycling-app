<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CVOWebsiteSupportController extends Controller
{
    /**
     * Country codes the website accepts; anything else is sent as null.
     */
    protected const COUNTRY_CODES = [
        'AE', 'AG', 'AI', 'AN', 'AR', 'AS', 'AU', 'AW', 'BB', 'BE', 'BH', 'BM', 'BO', 'BQ', 'BR', 'BS', 'BZ',
        'CA', 'CH', 'CI', 'CL', 'CN', 'CO', 'CR', 'CU', 'CW', 'CY', 'CZ', 'DE', 'DM', 'DO', 'EC', 'ES', 'FI',
        'FR', 'GB', 'GD', 'GR', 'GT', 'GU', 'GY', 'HK', 'HN', 'HT', 'HU', 'ID', 'IE', 'IL', 'IN', 'IT', 'JM',
        'JO', 'JP', 'KN', 'KW', 'KY', 'LB', 'LC', 'MA', 'MP', 'MS', 'MX', 'MY', 'NA', 'NG', 'NI', 'NL', 'NZ',
        'PA', 'PE', 'PH', 'PL', 'PR', 'PT', 'PY', 'QA', 'RO', 'RU', 'SE', 'SG', 'SK', 'SR', 'SV', 'SX', 'TH',
        'TR', 'TC', 'TT', 'TW', 'UA', 'US', 'UY', 'VC', 'VE', 'VI', 'VN', 'ZA',
    ];

    protected const ALLOWED_BRANDS = ['AR', 'IL', 'DE', 'UC', 'ADV-T'];

    protected const DEFAULT_SHIP_VIAS = ['UPSGR', 'UPS2D', 'USPS'];

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

    /**
     * GET /magento/validate/{acc}/{inv}
     *
     * Confirms the invoice (or credit memo) belongs to the account, then returns the account's
     * status and sales rep. Responds with the plain string "Invalid" otherwise.
     */
    public function validateAccount(string $acc, string $inv)
    {
        $acc = $this->padAccount($acc);

        // Short numbers are tried as an invoice first, then as a credit memo.
        $useInvoice = (strlen($inv) < 10 && (! preg_match('/INV/i', $inv) || ! preg_match('/CRM/i', $inv))) ? 'INV'.$inv : $inv;

        if (! $this->invoiceBelongsTo($acc, $useInvoice) && ! $this->invoiceBelongsTo($acc, 'CRM'.$inv)) {
            return 'Invalid';
        }

        $terrData = DB::select("SELECT sc.territory_code as SALES_REP_TERRITORY,
                CASE WHEN a.status_type = 1 THEN 'ACTIVE' ELSE 'INACTIVE' END as customer_status,
                a.contact_name,
                'No' as isRXE,
                a.contact_email as customer_email,
                sc.salesperson_name as SALES_REP_NAME,
                sc.phone as SALES_REP_PHONE,
                sc.slp_email as SALES_REP_EMAIL
            FROM armaster a
            JOIN cvo_sc_addr_vw sc WITH (NOLOCK) ON (a.territory_code = sc.territory_code)
            WHERE a.customer_code = ?", [$acc]);

        // Encodes as {"0": {...}, "allow_shipping_methods": "..."}, which the website expects.
        $terrData['allow_shipping_methods'] = 'USPS, UPSGR';

        return response()->json($terrData);
    }

    /**
     * GET /magento/account/{account}
     *
     * Returns the account's addresses keyed by ship-to code ("default" for the bill-to), followed by
     * its labs, third-party ship-tos and multi-location accounts.
     */
    public function accountData(string $account)
    {
        $account = $this->padAccount($account);

        $brandMap = $this->brandList();
        $tempAllowBrands = DB::table('cvo_temp_allow_brands')->where('account_no', $account)->value('allow_brands');
        $excludeDropShips = $this->excludeDropShipList($account);

        $data = [];
        $validShipOptions = [];

        foreach (DB::select('exec cvo_web_customer_info_sp ?', [$account]) as $i => $row) {
            $arr = (array) $row;

            $this->addShipVia($validShipOptions, $arr['ship_via_code']);
            $arr['country_code'] = $this->countryCode($arr['country_code']);

            // Split the contact (or company) name at the first space into first / last name.
            $name = (string) (($arr['contact_name'] ?? '') != '' ? $arr['contact_name'] : $arr['address_name']);
            $space = (int) strpos($name, ' ');
            $firstPart = substr($name, 0, $space);
            $secondPart = substr($name, $space);
            if ($secondPart != '' && $firstPart == '') {
                $firstPart = $secondPart;
            }

            if (($arr['Allow_brand'] ?? '') != '') {
                $brands = array_intersect(explode(';', $arr['Allow_brand']), self::ALLOWED_BRANDS);
                $arr['Allow_brand'] = implode(',', array_map(fn ($b) => $brandMap[$b] ?? '', $brands));
            }
            if ($arr['Allow_brand'] == '' && $tempAllowBrands !== null) {
                $arr['Allow_brand'] = $tempAllowBrands;
            }

            $arr['first_name'] = trim($firstPart) != '' ? trim($firstPart) : 'Contact';
            $arr['last_name'] = trim($secondPart) != '' ? trim($secondPart) : 'Name';
            $arr['is_lab'] = 'no';
            $arr['company'] = $arr['address_name'];
            $arr['isRXE'] = $arr['rxe'] == 'true' ? 'Yes' : 'No';
            $arr = $this->finishAddress($arr, $validShipOptions);

            $shipKey = $arr['ship_to_code'] != '' ? $arr['ship_to_code'] : 'default';
            if (! in_array($shipKey, $excludeDropShips)) {
                $data[$shipKey] = $arr;
            }

            // Labs, third-party ship-tos and locations follow the first address, as on the current website.
            if ($i === 0) {
                $this->addLabs($account, $data, $validShipOptions);
                $this->addThirdPartyShipTos($account, $data, $validShipOptions);
                $this->addLocations($account, $data, $validShipOptions);
            }
        }

        foreach (self::DEFAULT_SHIP_VIAS as $shipVia) {
            $this->addShipVia($validShipOptions, $shipVia);
        }
        $data['default']['allow_shipping_methods'] = implode(',', $validShipOptions);

        return response()->json($data);
    }

    /**
     * Drop ships and labs linked to the account.
     */
    protected function addLabs(string $account, array &$data, array &$validShipOptions): void
    {
        $records = DB::select("SELECT DISTINCT a.customer_code, a.ship_to_code, a.address_name, a.contact_name, a.addr2, a.addr3, a.addr4, a.addr5, a.addr6,
                a.city, a.state, a.postal_code, a.country_code, a.contact_phone, a.contact_email, a.ship_via_code,
                a.territory_code, '' as salesperson_name, a.addr_sort1,
                '' as salesperson_contact, '' as salesperson_email, '' as Parent_code, '' as Allow_brand, a.modified_by_date
            FROM armaster a
            WHERE a.status_type = '1'
                AND a.customer_code IN (SELECT DISTINCT lab_code FROM cvo_web_accounts_locations WHERE account_no = ? AND lab_code IS NOT NULL)", [$account]);

        foreach ($records as $row) {
            $labs = (array) $row;

            $this->addShipVia($validShipOptions, $labs['ship_via_code']);
            $labs['country_code'] = $this->countryCode($labs['country_code']);
            $labs['first_name'] = $labs['last_name'] = $labs['contact_name'] != '' ? $labs['contact_name'] : $labs['address_name'];

            // Customer codes starting with a digit are regular accounts; anything else is a lab.
            $isLab = ! preg_match('/^[0-9]/', $labs['customer_code']);
            $labs['is_lab'] = $isLab ? 'yes' : 'no';
            $labs['valid_shipto_flag'] = $isLab ? '0' : '1';

            $labs['company'] = $labs['address_name'];
            if ($labs['addr_sort1'] == 'Global Lab') {
                $labs['ship_to_code'] = $labs['customer_code'];
            }
            $labs['isRXE'] = 'No';

            $data[$labs['customer_code']] ??= $this->finishAddress($labs, $validShipOptions);
        }
    }

    /**
     * Default third-party ship-to labs for the account.
     */
    protected function addThirdPartyShipTos(string $account, array &$data, array &$validShipOptions): void
    {
        $records = DB::select("SELECT DISTINCT cust_code as customer_code,
                third_party_code as ship_to_code,
                third_party_code as address_name,
                '' as contact_name,
                tp_address_1 as addr2,
                tp_address_2 as addr3,
                tp_address_3 as addr4,
                tp_address_4 as addr5,
                tp_address_5 as addr6,
                tp_city as city,
                tp_state as state,
                tp_zip as postal_code,
                tp_country as country_code,
                tp_phone as contact_phone,
                '' as contact_email,
                tp_carrier as ship_via_code,
                '' as territory_code,
                '' as salesperson_name,
                '' as addr_sort1,
                '' as salesperson_contact,
                '' as salesperson_email,
                '' as Parent_code,
                '' as Allow_brand,
                '2020-01-01 00:00:00' as modified_by_date
            FROM cvo_third_party_ship_to
            WHERE tp_default = '1' AND cust_code = ?", [$account]);

        foreach ($records as $row) {
            $labs = (array) $row;

            $this->addShipVia($validShipOptions, $labs['ship_via_code']);
            $labs['country_code'] = $this->countryCode(($labs['country_code'] ?? '') != '' ? $labs['country_code'] : 'US');
            $labs['first_name'] = $labs['last_name'] = $labs['contact_name'] != '' ? $labs['contact_name'] : $labs['address_name'];
            $labs['company'] = $labs['address_name'];
            $labs['isRXE'] = 'No';
            $labs['is_lab'] = 'yes';
            $labs['valid_shipto_flag'] = '0';

            $data[$labs['ship_to_code']] ??= $this->finishAddress($labs, $validShipOptions);
        }
    }

    /**
     * Other accounts linked to this one as locations.
     */
    protected function addLocations(string $account, array &$data, array &$validShipOptions): void
    {
        $records = DB::select("SELECT DISTINCT a.customer_code, a.ship_to_code, a.address_name, a.contact_name, a.addr2, a.addr3, a.addr4, a.addr5, a.addr6,
                a.city, a.state, a.postal_code, a.country_code, a.contact_phone, a.contact_email, a.ship_via_code,
                a.territory_code, sc.salesperson_name as salesperson_name,
                sc.phone as salesperson_contact, sc.slp_email as salesperson_email, '' as Parent_code, '' as Allow_brand, '1' as valid_shipto_flag, a.modified_by_date
            FROM armaster a
            JOIN cvo_sc_addr_vw sc WITH (NOLOCK) ON (a.territory_code = sc.territory_code)
            WHERE a.status_type = '1'
                AND a.customer_code IN (SELECT DISTINCT ship_to_code FROM cvo_web_accounts_locations WHERE account_no = ? AND ship_to_code IS NOT NULL AND LEN(ship_to_code) > 5)", [$account]);

        foreach ($records as $row) {
            $locs = (array) $row;

            $this->addShipVia($validShipOptions, $locs['ship_via_code']);
            $locs['country_code'] = $this->countryCode($locs['country_code']);
            $locs['ship_to_code'] = $locs['customer_code'];
            $locs['first_name'] = $locs['last_name'] = $locs['contact_name'] != '' ? $locs['contact_name'] : $locs['address_name'];
            $locs['is_lab'] = 'no';
            $locs['company'] = $locs['address_name'];
            $locs['isRXE'] = 'No';

            $data[$locs['customer_code']] ??= $this->finishAddress($locs, $validShipOptions);
        }
    }

    /**
     * Shared clean-up for every address row: strip special characters, fill required
     * website fields and stamp the current ship methods and time.
     */
    protected function finishAddress(array $row, array $validShipOptions): array
    {
        foreach (['first_name', 'last_name', 'company', 'address_name'] as $field) {
            $row[$field] = $this->excludeSpecialChars($row[$field]);
        }

        if (($row['contact_phone'] ?? '') == '') {
            $row['contact_phone'] = '000-000-0000';
        }
        if (trim($row['postal_code'] ?? '') == '') {
            $row['postal_code'] = 'xxxxx';
        }

        $row['allow_shipping_methods'] = implode(',', $validShipOptions);
        $row['updated_at'] = now()->format('Y-m-d H:i:s');

        return $row;
    }

    /**
     * Account numbers are six digits; the website may drop the leading zero.
     */
    protected function padAccount(string $account): string
    {
        return (strlen($account) == 5 && ! preg_match('/^0/', $account)) ? '0'.$account : $account;
    }

    protected function invoiceBelongsTo(string $account, string $docNo): bool
    {
        $row = DB::selectOne('exec cvo_web_cust_inv_chk_sp ?, ?', [$account, $docNo]);

        return $row !== null && (array_values((array) $row)[0] ?? null) == 'Yes';
    }

    protected function addShipVia(array &$validShipOptions, $shipVia): void
    {
        if (! in_array($shipVia, $validShipOptions)) {
            $validShipOptions[] = $shipVia;
        }
    }

    protected function countryCode($code): ?string
    {
        return in_array($code, self::COUNTRY_CODES, true) ? $code : null;
    }

    /**
     * Collection code => name for the brands an account can be restricted to.
     */
    protected function brandList(): array
    {
        $brands = DB::table('cvo_inv_master_r2_vw')
            ->whereIn('Collection', ['IL', 'AR', 'DE', 'UC'])
            ->distinct()
            ->pluck('CollectionName', 'Collection')
            ->all();
        $brands['ADV-T'] = 'ADVANTAGE TITANIUM';

        return $brands;
    }

    protected function excludeDropShipList(string $account): array
    {
        return DB::table('cvo_customer_exclude_dropships')
            ->where('account_no', $account)
            ->distinct()
            ->pluck('ship_to')
            ->all();
    }

    protected function excludeSpecialChars($input): string
    {
        $str = preg_replace('/[^\-\(\)%@!_:\+=;<>\$~`&]#\'/', ' ', (string) $input);

        return str_replace(['/', '(', ')', "'", '-', '&', '*', ']', '[', '#', '^', '@', '{', '}'], ' ', $str);
    }
}
