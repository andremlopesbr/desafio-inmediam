<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class PayBillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation()
    {
        if ($this->has('card_number')) {
            $this->merge([
                'card_number' => str_replace([' ', '-'], '', $this->card_number),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'card_holder_name' => ['required', 'string', 'max:255'],
            'card_number' => ['required', 'string', 'digits_between:13,19'],
            'expiry_date' => [
                'required',
                'string',
                'regex:/^(0[1-9]|1[0-2])\/\d{2}$/',
                function ($attribute, $value, $fail) {
                    if (preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $value)) {
                        $parts = explode('/', $value);
                        $month = (int)$parts[0];
                        $year = (int)('20' . $parts[1]);

                        $expiryDate = Carbon::createFromDate($year, $month, 1)->endOfMonth();
                        if ($expiryDate->isPast()) {
                            $fail('O cartão de crédito está expirado.');
                        }
                    }
                },
            ],
            'cvv' => ['required', 'string', 'digits_between:3,4'],
        ];
    }
}
