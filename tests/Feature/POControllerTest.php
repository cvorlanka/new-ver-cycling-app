<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class POControllerTest extends TestCase
{
    protected const UPSERT_SP = 'exec cvo_poreq_upd_sp ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';

    protected function row(array $overrides = []): array
    {
        return array_merge([
            'row_id_po' => 101,
            'GROUP_NO' => '  G1  ',
            'part_no' => 'P-1',
            'po_qty' => 5,
            'buy_flag' => 'Y',
            'demand_date' => '2026-11-01',
            'location' => '001',
            'vendor_no' => 'V100',
            'unit_cost' => 2.5,
        ], $overrides);
    }

    public function test_get_data_returns_view_rows(): void
    {
        DB::shouldReceive('select')->once()
            ->with('SELECT * FROM cvo_poreq_vw')
            ->andReturn([(object) ['row_id_po' => 1, 'part_no' => 'P-1']]);

        $this->getJson('/api/getData')
            ->assertOk()
            ->assertExactJson([['row_id_po' => 1, 'part_no' => 'P-1']]);
    }

    public function test_create_sends_ins_and_returns_true(): void
    {
        DB::shouldReceive('select')->once()
            ->with(self::UPSERT_SP, ['ins', 101, 'G1', 'P-1', 5, 'Y', '2026-11-01', '001', 'V100', 2.5])
            ->andReturn([]);

        $response = $this->postJson('/api/createPOReq', $this->row());

        $response->assertOk();
        $this->assertSame('true', $response->getContent());
    }

    public function test_empty_fields_are_sent_as_null_text(): void
    {
        DB::shouldReceive('select')->once()
            ->with(self::UPSERT_SP, ['ins', 'NULL', 'NULL', 'P-1', 'NULL', 'NULL', 'NULL', '001', 'V100', 'NULL'])
            ->andReturn([]);

        $this->postJson('/api/createPOReq', [
            'row_id_po' => '',
            'part_no' => 'P-1',
            'location' => '001',
            'vendor_no' => 'V100',
        ])->assertOk();
    }

    public function test_update_sends_upd_and_returns_sp_rows(): void
    {
        DB::shouldReceive('select')->once()
            ->with(self::UPSERT_SP, ['upd', 101, 'G1', 'P-1', 5, 'Y', '2026-11-01', '001', 'V100', 2.5])
            ->andReturn([(object) ['result' => 'ok']]);

        $this->postJson('/api/updatePOReq', $this->row())
            ->assertOk()
            ->assertExactJson([['result' => 'ok']]);
    }

    public function test_clone_sends_ins_and_returns_sp_rows(): void
    {
        DB::shouldReceive('select')->once()
            ->with(self::UPSERT_SP, ['ins', 101, 'G1', 'P-1', 5, 'Y', '2026-11-01', '001', 'V100', 2.5])
            ->andReturn([(object) ['row_id_po' => 202]]);

        $this->postJson('/api/clonePOReq', $this->row())
            ->assertOk()
            ->assertExactJson([['row_id_po' => 202]]);
    }

    public function test_delete_zeroes_each_row_with_real_nulls(): void
    {
        DB::shouldReceive('select')->once()
            ->with(self::UPSERT_SP, ['upd', 1, 'G1', 'P-1', 0, 'N', '2026-11-01', '001', 'V100', 2.5])
            ->andReturn([]);
        DB::shouldReceive('select')->once()
            ->with(self::UPSERT_SP, ['upd', 2, null, null, 0, 'N', null, null, null, null])
            ->andReturn([]);

        $response = $this->postJson('/api/deletePOReq', [
            $this->row(['row_id_po' => 1]),
            ['row_id_po' => 2],
        ]);

        $response->assertOk();
        $this->assertSame('Delete List: 1 - 2', $response->getContent());
    }

    public function test_submit_binds_id_list_and_user(): void
    {
        DB::shouldReceive('select')->once()
            ->with("exec cvo_ifp_poreq_po_sp ?, 'POREQ', 1, ?, 0", ['1,2', 'cvoptical\bmurphy'])
            ->andReturn([]);

        $response = $this->postJson('/api/submitPOReq', [['row_id_po' => 1], ['row_id_po' => 2]]);

        $response->assertOk();
        $this->assertSame('SUBMIT: 1,2', $response->getContent());
    }

    public function test_submit_with_no_rows_returns_none(): void
    {
        DB::shouldReceive('select')->never();

        $response = $this->postJson('/api/submitPOReq', []);

        $this->assertSame('NONE', $response->getContent());
    }
}
