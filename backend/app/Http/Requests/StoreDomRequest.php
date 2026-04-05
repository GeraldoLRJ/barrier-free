<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDomRequest extends FormRequest
{
    //Verifica se o usuário está autorizado a fazer a requisição
    public function authorize(): bool
    {
        return true; // Autenticação será implementada posteriormente, liberado por padrão no MVP
    }

    public function rules(): array
    {
        $teste = 0;
        return [
            'html_content' => ['required', 'string'],
            'url'          => ['nullable', 'url', 'string', new \App\Rules\SafeUrl()],
        ];
    }
}
