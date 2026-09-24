<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditCard extends Model
{
    protected $fillable = ['customer_id', 'card_holder_name', 'card_last_four', 'card_brand', 'card_token'];
    protected $hidden = ['card_token'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
