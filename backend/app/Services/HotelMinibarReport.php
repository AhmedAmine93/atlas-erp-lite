<?php

namespace App\Services;

use App\Models\HotelProperty;
use Illuminate\Support\Facades\DB;

final class HotelMinibarReport
{
    public function lines(HotelProperty $hotel,string $start,string $end)
    {
        return DB::table('minibar_consumptions as c')
            ->join('hotel_rooms as r','r.id','=','c.room_id')
            ->join('minibar_products as p','p.id','=','c.product_id')
            ->where('c.company_id',$hotel->company_id)->where('c.hotel_id',$hotel->id)
            ->whereBetween('c.business_date',[$start,$end])
            ->groupBy('c.business_date','r.room_number','p.sku','p.name','c.unit_cents')
            ->select('c.business_date','r.room_number','p.sku','p.name','c.unit_cents')
            ->selectRaw('SUM(c.quantity)::bigint AS quantity, SUM(c.total_cents)::bigint AS total_cents')
            ->orderBy('c.business_date')->orderBy('r.room_number')->orderBy('p.sku')->orderBy('c.unit_cents')->get();
    }

    public function csv(string $filename,array $header,iterable $rows)
    {
        // Materialize the tenant-filtered result before the PostgreSQL RLS transaction closes.
        $stream = fopen('php://temp','w+b');
        fputcsv($stream,$header,';','"','');
        foreach ($rows as $fields) {
            $safe = array_map(static function ($field) {
                if (is_string($field) && preg_match('/^(?:[\x00-\x20]*[=+@-]|[\t\r\n])/u',$field)) {
                    return "'".$field;
                }
                return $field;
            },$fields);
            fputcsv($stream,$safe,';','"','');
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);
        return response($bytes,200,['Content-Type'=>'text/csv; charset=UTF-8',
            'Content-Disposition'=>'attachment; filename="'.$filename.'"','Cache-Control'=>'private, no-store']);
    }
}
