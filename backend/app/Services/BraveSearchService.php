<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;

class BraveSearchService
{
    protected string $baseUrl = 'https://api.search.brave.com/res/v1/web/search';

    /**
     * Realiza uma busca na Brave Search API e retorna os resultados formatados.
     *
     * @param string $query  Termo de busca
     * @param int    $count  Número máximo de resultados (padrão: configurado em services.php)
     * @return array{title: string, url: string, description: string}[]
     */
    public function search(string $query, ?int $count = null): array
    {
        $apiKey = config('services.brave_search.key');
        $count  = $count ?? (int) config('services.brave_search.results_count', 5);

        if (!$apiKey) {
            Log::warning('Brave Search API key ausente. Não é possível realizar a busca.');
            return [];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Accept'               => 'application/json',
                    'Accept-Encoding'      => 'gzip',
                    'X-Subscription-Token' => $apiKey,
                ])
                ->get($this->baseUrl, [
                    'q'      => $query,
                    'count'  => $count,
                    'lang'   => 'pt',
                    'market' => 'pt-BR',
                ]);
        } catch (ConnectionException $e) {
            Log::error('Brave Search: timeout de conexão', ['error' => $e->getMessage()]);
            return [];
        }

        if ($response->failed()) {
            Log::error('Brave Search API falhou', [
                'status'   => $response->status(),
                'response' => $response->body(),
            ]);
            return [];
        }

        $data    = $response->json();
        $results = $data['web']['results'] ?? [];

        $formatted = [];
        foreach ($results as $result) {
            $formatted[] = [
                'title'       => $result['title']       ?? 'Sem título',
                'url'         => $result['url']         ?? '',
                'description' => $result['description'] ?? '',
            ];
        }

        Log::info('Brave Search: resultados obtidos', [
            'query'   => $query,
            'count'   => count($formatted),
        ]);

        return $formatted;
    }
}
