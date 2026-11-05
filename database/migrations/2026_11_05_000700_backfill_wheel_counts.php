<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Строки, отсканированные до появления поштучного счёта: счётчик колёс = погруженному/принятому количеству. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('transfer_lines')->where('scanned_out', true)->where('wheels_out', 0)->update(['wheels_out' => DB::raw('COALESCE(qty_out, 0)')]);
        DB::table('transfer_lines')->where('scanned_in', true)->where('wheels_in', 0)->update(['wheels_in' => DB::raw('COALESCE(qty_in, 0)')]);
    }

    public function down(): void {}
};
