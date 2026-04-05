<?php

namespace App\Rules;

use App\Services\SafeBrowsingService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafeUrl implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $safeBrowsingService = app(SafeBrowsingService::class);

        if (!$safeBrowsingService->isSafe($value)) {
            $fail('A URL fornecida contém conteúdo potencialmente inseguro ou malicioso.');
        }
    }
}
