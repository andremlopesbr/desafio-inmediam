<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['billing_id', 'credit_card_id', 'amount_paid', 'payment_method', 'status', 'paid_at'];

    public function billing()
    {
        return $this->belongsTo(Billing::class);
    }

    public function creditCard()
    {
        return $this->belongsTo(CreditCard::class);
    }
}
