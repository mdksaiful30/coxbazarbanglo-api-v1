<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FinanceService
{
    public function summary(Booking $b): array
    {
        $charges = (int) $b->invoices()->where('status', 'issued')->sum('total_minor');
        $credits = (int) CreditNote::where('booking_id', $b->id)->sum('amount_minor');
        $charges -= $credits;
        $paid = (int) $b->payments()->where('status', 'posted')->sum('amount_minor');
        $refund = (int) Refund::whereIn('payment_id', $b->payments()->where('status', 'posted')->select('id'))->sum('amount_minor');
        $net = $paid - $refund;
        $balance = $charges - $net;

        return ['currency' => 'BDT', 'credits_minor' => $credits, 'charges_minor' => $charges, 'paid_minor' => $paid, 'refunded_minor' => $refund, 'balance_minor' => $balance, 'payment_status' => $balance < 0 ? 'overpaid' : ($balance === 0 && $charges > 0 ? 'paid' : ($net > 0 ? 'partially_paid' : 'unpaid'))];
    }

    public function record(int $id, array $data): Payment
    {
        return DB::transaction(function () use ($id, $data) {
            $b = Booking::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($b->status, ['confirmed', 'checked_in', 'checked_out']), 409, 'Payments require an approved booking.');
            $p = Payment::create($data + ['booking_id' => $b->id, 'receipt_number' => 'RCT-'.Str::upper(Str::random(10)), 'created_by' => auth()->id()]);
            Audit::booking($b, 'payment_recorded', null, ['payment_id' => $p->id, 'amount_minor' => $p->amount_minor]);

            return $p;
        });
    }

    public function reverse(int $id, string $reason): Payment
    {
        return DB::transaction(function () use ($id, $reason) {
            $p = Payment::findOrFail($id);
            Booking::whereKey($p->booking_id)->lockForUpdate()->firstOrFail();
            $p = Payment::lockForUpdate()->findOrFail($id);
            abort_unless($p->status === 'posted' && ! $p->refunds()->exists(), 409, 'Payment already reversed or refunded.');
            $p->update(['status' => 'reversed', 'reversal_reason' => $reason]);
            Audit::record('payment_reversed', 'payment', $p->id, ['reason' => $reason]);

            return $p;
        });
    }

    public function refund(int $id, array $data): Refund
    {
        return DB::transaction(function () use ($id, $data) {
            $p = Payment::findOrFail($id);
            Booking::whereKey($p->booking_id)->lockForUpdate()->firstOrFail();
            $p = Payment::lockForUpdate()->findOrFail($id);
            abort_unless($p->status === 'posted', 409, 'Only a posted payment can be refunded.');
            abort_if($data['amount_minor'] > $p->amount_minor - $p->refunds()->sum('amount_minor'), 422, 'Refund exceeds remaining payment.');
            $r = Refund::create($data + ['payment_id' => $p->id, 'created_by' => auth()->id()]);
            Audit::record('refund_recorded', 'refund', $r->id, $data);

            return $r;
        });
    }

    public function credit(int $id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $b = Booking::lockForUpdate()->findOrFail($id);
            $remaining = $this->summary($b)['charges_minor'];
            abort_if($remaining <= 0 || $data['amount_minor'] > $remaining, 422, 'Credit exceeds remaining issued charges.');
            $note = CreditNote::create($data + ['booking_id' => $id, 'number' => 'CRN-'.Str::upper(Str::random(10)), 'created_by' => auth()->id()]);
            Audit::booking($b, 'credit_issued', $data['reason'], ['credit_note_id' => $note->id, 'amount_minor' => $note->amount_minor]);

            return $note;
        });
    }
}
