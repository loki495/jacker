<?php

declare(strict_types=1);

use App\Support\UsdPay;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Converts pay already staged in another currency, as new listings now are; accepted listings are left alone. */
    public function up(): void
    {
        DB::table('found_jobs')->whereNotNull('currency')->where('currency', '!=', 'USD')->where('status', '!=', 'accepted')->orderBy('id')->each(function (object $row): void {
            $converted = UsdPay::apply((array) $row);
            DB::table('found_jobs')->where('id', $row->id)->update(array_intersect_key($converted, array_flip(['salary_min', 'salary_max', 'currency', 'salary_notes'])));
        });
    }

    public function down(): void
    {
        // The original figures stay in salary_notes; the conversion is not undone.
    }
};
