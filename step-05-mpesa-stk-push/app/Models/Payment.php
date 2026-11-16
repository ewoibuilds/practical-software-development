<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = ['phone', 'amount', 'status', 'merchant_request_id', 'checkout_request_id', 'reported_receipt', 'mpesa_receipt', 'result_code', 'result_desc'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
