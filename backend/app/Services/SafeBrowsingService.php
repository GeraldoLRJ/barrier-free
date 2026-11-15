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

    /**
     * Verifica várias URLs de uma vez (1 requisição por até 500 URLs).
     * Em falha de API/rede libera as URLs (mesmo fallback de isSafe()).
     *
     * @param string[] $urls
     * @return array<string, bool> mapa url => é segura
     */
    public function checkMany(array $urls): array
    {
        $urls   = array_values(array_unique(array_filter($urls, 'is_string')));
        $result = [];
        $apiKey = config('services.google_safe_browsing.key');

        if (!$apiKey) {
            Log::warning('Google Safe Browsing API key está ausente. Pulando validação em lote.');
            return array_fill_keys($urls, true);
        }

        $pending = [];
        foreach ($urls as $url) {
            try {
                $cached = Cache::store('file')->get('safe_browsing:' . md5($url));
            } catch (Throwable $e) {
                $cached = null;
            }
            if ($cached !== null) {
                $result[$url] = (bool) $cached;
            } else {
                $pending[] = $url;
            }
        }

        foreach (array_chunk($pending, 500) as $chunk) {
            try {
                $response = Http::connectTimeout(2)
                    ->timeout(5)
                    ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                    ->post($this->apiUrl . '?key=' . $apiKey, [
                        'client' => ['clientId' => 'barrier-free', 'clientVersion' => '1.0.0'],
                        'threatInfo' => [
                            'threatTypes'      => ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE', 'POTENTIALLY_HARMFUL_APPLICATION'],
                            'platformTypes'    => ['ANY_PLATFORM'],
                            'threatEntryTypes' => ['URL'],
                            'threatEntries'    => array_map(fn ($u) => ['url' => $u], $chunk),
                        ],
                    ]);
            } catch (Throwable $e) {
                Log::warning('Safe Browsing (lote) erro de conexão, liberando URLs por fallback', ['error' => $e->getMessage()]);
                foreach ($chunk as $u) {
                    $result[$u] = true;
                }
                continue;
            }

            if ($response->failed()) {
                Log::error('Google Safe Browsing API (lote) falhou', ['status' => $response->status()]);
                foreach ($chunk as $u) {
                    $result[$u] = true;
                }
                continue;
            }

            $unsafe = [];
            foreach ($response->json('matches') ?? [] as $match) {
                if (isset($match['threat']['url'])) {
                    $unsafe[$match['threat']['url']] = true;
                }
            }

            foreach ($chunk as $u) {
                $safe = !isset($unsafe[$u]);
                $result[$u] = $safe;
                try {
                    Cache::store('file')->put('safe_browsing:' . md5($u), $safe, 3600);
                } catch (Throwable $e) {
                    // Ignorar falha de cache
                }
            }
        }

        return $result;
    }

    /**
     * Remove da lista os itens cuja URL é maliciosa.
     *
     * @param array<int, array> $items
     * @return array<int, array>
     */
    public function filterSafe(array $items, string $urlKey = 'url'): array
    {
        if (empty($items)) {
            return $items;
        }

        $map = $this->checkMany(array_column($items, $urlKey));

        $safe = array_values(array_filter(
            $items,
            fn ($item) => ($map[$item[$urlKey] ?? ''] ?? true) === true
        ));

        $removed = count($items) - count($safe);
        if ($removed > 0) {
            Log::warning("Safe Browsing: {$removed} link(s) inseguro(s) removido(s)");
        }

        return $safe;
    }
}
