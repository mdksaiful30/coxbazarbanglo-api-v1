<?php

namespace Database\Seeders;

use App\Models\GuestCategory;
use App\Models\Room;
use App\Models\Setting;
use App\Models\TariffRate;
use App\Models\TariffVersion;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('properties')->updateOrInsert(['id' => 1], ['name' => "BIWTA Cox's Bazar Inspection Bungalow", 'address' => "Cox's Bazar, Bangladesh", 'timezone' => 'Asia/Dhaka']);
        DB::table('buildings')->updateOrInsert(['id' => 1], ['property_id' => 1, 'name' => 'Main Building']);
        foreach ([1, 2, 3] as $floor) {
            DB::table('floors')->updateOrInsert(['id' => $floor], ['building_id' => 1, 'number' => $floor, 'name' => "Floor $floor"]);
        }
        $rooms = [
            ['R102', 'অপেক্ষমান কক্ষ', 1, 'non_ac', 'স্ট্যান্ডার্ড ডাবল'], ['R201', 'বাঁকখালী', 2, 'ac', 'ডাবল বেড'],
            ['R202', 'মাতামুহুরী', 2, 'non_ac', 'ডাবল বেড'], ['R203', 'রেজু', 2, 'non_ac', 'কাপল বেড'], ['R204', 'সাঙ্গু', 2, 'non_ac', 'কাপল বেড'],
            ['R301', 'কর্ণফুলি', 3, 'ac', 'কাপল বেড'], ['R302', 'ধরলা', 3, 'non_ac', 'ডাবল বেড'], ['R303', '৩ নং রুম', 3, 'non_ac', 'কাপল বেড'],
            ['R304', '৪ নং রুম', 3, 'non_ac', 'কাপল বেড'], ['R305', 'জলকদর', 3, 'ac', 'ডাবল বেড'], ['R306', 'উজানটিয়া', 3, 'ac', 'ডাবল বেড'],
            ['R307', 'কুহুলিকা', 3, 'non_ac', 'ডাবল বেড'], ['R308', 'নাফ', 3, 'ac', 'কাপল বেড'], ['R309', 'স্যুট', 3, 'ac', 'স্যুট'],
        ];
        foreach ($rooms as [$code,$name,$floor,$ac,$bed]) {
            Room::firstOrCreate(['code' => $code], ['name' => $name, 'floor_id' => $floor, 'ac_type' => $ac, 'bed_type' => $bed, 'room_type' => $code === 'R309' ? 'suite' : 'standard', 'bookable' => false, 'housekeeping' => 'unverified']);
        }
        $cats = [
            ['A', 'Ministry of Shipping / BIWTA officers, employees and retired officers', null],
            ['B', 'Government / semi-government / statutory / autonomous organization officers and retired officers', 10],
            ['C', 'Private individuals and officers', 10],
        ];
        foreach ($cats as [$code,$name,$max]) {
            GuestCategory::firstOrCreate(['code' => $code], ['name' => $name, 'max_nights' => $max]);
        }
        $tariff = TariffVersion::firstOrCreate(['reference' => '2026 Schedule 5'], ['name' => 'BIWTA Cox’s Bazar Tariff 2026', 'active' => false, 'vat_inclusive' => true]);
        $bands = ['A' => [[1, 3, 250, 200], [4, 10, 500, 300], [11, null, 700, 400]], 'B' => [[1, 3, 1000, 700], [4, 7, 1200, 900], [8, 10, 1500, 1000]], 'C' => [[1, 10, 2000, 1500]]];
        foreach ($bands as $code => $rows) {
            foreach ($rows as [$min,$max,$ac,$non]) {
                foreach (['ac' => $ac, 'non_ac' => $non] as $type => $rate) {
                    TariffRate::firstOrCreate(['tariff_version_id' => $tariff->id, 'guest_category_id' => GuestCategory::where('code', $code)->value('id'), 'ac_type' => $type, 'min_nights' => $min], ['max_nights' => $max, 'amount_minor' => $rate * 100]);
                }
            }
        }
        Setting::firstOrCreate(['key' => 'operations'], ['value' => ['check_in_time' => '14:00', 'check_out_time' => '12:00', 'turnaround_minutes' => 0, 'notification_channel' => 'email']]);
    }
}
