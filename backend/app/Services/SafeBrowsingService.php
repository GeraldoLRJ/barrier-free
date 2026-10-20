<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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

        $cacheKey = 'safe_browsing:' . md5($url);

        try {
            $cached = Cache::store('file')->get($cacheKey);
            if ($cached !== null) {
                return (bool) $cached;
            }
        } catch (Throwable $e) {
            // Se o cache em arquivo falhar, segue com a verificação normal
        }

        try {
            $response = Http::connectTimeout(2)
                ->timeout(3)
                ->withOptions([
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    ],
                ])
                ->post($this->apiUrl . '?key=' . $apiKey, [
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
        } catch (Throwable $e) {
            Log::warning('Google Safe Browsing timeout ou erro de conexão, liberando URL por fallback', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return true;
        }

        if ($response->failed()) {
            Log::error('Google Safe Browsing API falhou', ['status' => $response->status(), 'response' => $response->body()]);
            //Criar falha posteriormente
            return true;
        }

        $data = $response->json();

        // Se o array 'matches' estiver presente, significa que a URL foi encontrada nas listas de ameaças
        $isSafe = !(isset($data['matches']) && count($data['matches']) > 0);

        try {
            Cache::store('file')->put($cacheKey, $isSafe, 3600);
        } catch (Throwable $e) {
            // Ignorar falha de gravação de cache
        }

        return $isSafe;
    }
}
