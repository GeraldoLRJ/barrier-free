<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SafeBrowsingService
{
    protected string $apiUrl = 'https://safebrowsing.googleapis.com/v4/threatMatches:find';

    /**
     * Verifica se uma URL fornecida é segura de acordo com a API Google Safe Browsing.
     *
     * @param string $url
     * @return bool True se a URL for segura, false se for encontrada em listas de ameaças.
     */
    public function isSafe(string $url): bool
    {
        $apiKey = config('services.google_safe_browsing.key');

        if (!$apiKey) {
            // Se a API key estiver ausente, deixar passar ou falhar dependendo da rigidez.
            // Logar um aviso e deixar passar pode ser útil em desenvolvimento.
            Log::warning('Google Safe Browsing API key está ausente. Pulando validação de URL para: ' . $url);
            return true;
        }

        $response = Http::post($this->apiUrl . '?key=' . $apiKey, [
            'client' => [
                'clientId'      => 'barrier-free',
                'clientVersion' => '1.0.0',
            ],
            'threatInfo' => [
                'threatTypes'      => [
                    'MALWARE',
                    'SOCIAL_ENGINEERING',
                    'UNWANTED_SOFTWARE',
                    'POTENTIALLY_HARMFUL_APPLICATION',
                ],
                'platformTypes'    => ['ANY_PLATFORM'],
                'threatEntryTypes' => ['URL'],
                'threatEntries'    => [
                    ['url' => $url],
                ],
            ],
        ]);

        if ($response->failed()) {
            Log::error('Google Safe Browsing API falhou', ['status' => $response->status(), 'response' => $response->body()]);
            // If the API call fails, we might want to default to true to not block legitimate traffic, or false to be strict.
            // Let's default to true for resilience, but in a highly secure environment this might be false.
            return true;
        }

        $data = $response->json();

        // Se o array 'matches' estiver presente, significa que a URL foi encontrada nas listas de ameaças
        if (isset($data['matches']) && count($data['matches']) > 0) {
            return false;
        }

        return true;
    }
}
