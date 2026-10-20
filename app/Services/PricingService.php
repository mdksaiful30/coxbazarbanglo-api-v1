<?php

namespace App\Services;

use App\Models\GuestCategory;
use App\Models\Room;
use App\Models\TariffRate;
use App\Models\TariffVersion;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class PricingService
{
    public function quote(array $data, bool $requireActive = false): array
    {
        $start = CarbonImmutable::parse($data['check_in_at'], 'Asia/Dhaka');
        $end = CarbonImmutable::parse($data['check_out_at'], 'Asia/Dhaka');
        $nights = (int) $start->startOfDay()->diffInDays($end->startOfDay(), false);
        if ($end <= $start || $nights < 1 || $nights > 365) {
            throw ValidationException::withMessages(['check_out_at' => 'Choose a stay of 1–365 nights. Same-day stays require a separate approved policy.']);
        }
        $category = GuestCategory::findOrFail($data['guest_category_id']);
        if ($category->max_nights && $nights > $category->max_nights) {
            throw ValidationException::withMessages(['check_out_at' => 'This guest category permits a maximum of '.$category->max_nights.' nights.']);
        }
        $tariff = isset($data['tariff_version_id']) ? TariffVersion::findOrFail($data['tariff_version_id']) : (TariffVersion::where('active', true)->first() ?? TariffVersion::firstOrFail());
        if ($requireActive && (! $tariff->active || ! $tariff->approved_at || ! $tariff->calculation_mode || ! $tariff->effective_from || $start->toDateString() < $tariff->effective_from)) {
            throw ValidationException::withMessages(['tariff' => 'An approved tariff effective on arrival is required.']);
        }
        $mode = $tariff->calculation_mode ?? 'progressive';
        $lines = [];
        $roomIds = array_values(array_unique($data['room_ids']));
        $rooms = Room::whereIn('id', $roomIds)->get();
        if ($rooms->count() !== count($roomIds)) {
            throw ValidationException::withMessages(['room_ids' => 'One or more rooms no longer exist.']);
        }
        foreach ($rooms as $room) {
            for ($i = 1; $i <= $nights; $i++) {
                $band = $mode === 'whole_stay' ? $nights : $i;
                $rate = TariffRate::where('tariff_version_id', $tariff->id)->where('guest_category_id', $category->id)->where('ac_type', $room->ac_type)->where('min_nights', '<=', $band)->where(fn ($q) => $q->whereNull('max_nights')->orWhere('max_nights', '>=', $band))->first();
                if (! $rate) {
                    throw ValidationException::withMessages(['tariff' => 'No matching rate band.']);
                }
                $lines[] = ['room_id' => $room->id, 'room_code' => $room->code, 'date' => $start->addDays($i - 1)->toDateString(), 'night' => $i, 'amount_minor' => $rate->amount_minor];
            }
        }

        return ['currency' => 'BDT', 'vat_inclusive' => true, 'nights' => $nights, 'tariff_version_id' => $tariff->id, 'tariff_reference' => $tariff->reference, 'calculation_mode' => $mode, 'provisional' => ! $tariff->active, 'policy_snapshot' => $tariff->policy, 'category_snapshot' => $category->toArray(), 'lines' => $lines, 'total_minor' => array_sum(array_column($lines, 'amount_minor'))];
    }
}
