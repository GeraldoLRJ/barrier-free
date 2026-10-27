<?php

namespace App\Services;

/**
 * Monta, de forma determinística, a fala do comando "buscar" para usuários com deficiência visual.
 *
 * A lista de opções NÃO é escrita pelo Gemini: assim a estrutura é sempre a mesma e só cita
 * sites que realmente podem ser abertos com "abrir opção N".
 */
class SearchSpeechFormatter
{
    /** Nomes falados para domínios cuja leitura direta soaria estranha. */
    protected const SITE_NAMES = [
        'uol'         => 'UOL',
        'g1'          => 'G1',
        'globo'       => 'Globo',
        'bcb'         => 'Banco Central',
        'pucpr'       => 'PUC Paraná',
        'infomoney'   => 'InfoMoney',
        'youtube'     => 'YouTube',
        'linkedin'    => 'LinkedIn',
        'github'      => 'GitHub',
        'wikipedia'   => 'Wikipédia',
        'mercadolivre' => 'Mercado Livre',
    ];

    protected const IGNORED_LABELS = [
        'www', 'm', 'amp', 'com', 'br', 'org', 'net', 'gov', 'edu', 'io', 'app', 'co', 'pt', 'en', 'web',
    ];

    protected const MAX_TITLE_LENGTH = 70;

    /**
     * Enriquece os resultados brutos da busca com número, nome falado do site e título curto.
     *
     * @param array<int, array{title: string, url: string}> $results
     * @return array<int, array{number: int, title: string, url: string, site_name: string, spoken_title: string}>
     */
    public function buildSources(array $results): array
    {
        $sources = [];
        foreach (array_values($results) as $index => $result) {
            $title = (string) ($result['title'] ?? '');
            $url   = (string) ($result['url'] ?? '');

            $sources[] = [
                'number'       => $index + 1,
                'title'        => $title,
                'url'          => $url,
                'site_name'    => $this->siteName($url),
                'spoken_title' => $this->shortTitle($title),
            ];
        }

        return $sources;
    }

    /**
     * Resposta curta + lista numerada de opções + instrução de como abrir.
     *
     * @param array<int, array{number: int, site_name: string, spoken_title: string}> $sources
     */
    public function buildSpeech(string $answer, array $sources): string
    {
        $total = count($sources);
        if ($total === 0) {
            return trim($answer);
        }

        $lines = [$total === 1 ? 'Encontrei 1 opção.' : "Encontrei {$total} opções."];
        foreach ($sources as $source) {
            $lines[] = "Opção {$source['number']}: " . $this->describe($source) . '.';
        }

        $lines[] = $total === 1
            ? 'Para abrir, diga abrir opção 1.'
            : "Para abrir, diga abrir opção seguido do número, de 1 a {$total}. Para ouvir de novo, diga repetir opções.";

        $answer = trim($answer);

        return ($answer !== '' ? $answer . "\n\n" : '') . implode("\n", $lines);
    }

    /**
     * Texto só com a lista (usado por "repetir opções" e por "abrir" sozinho).
     *
     * @param array<int, array{number: int, site_name: string, spoken_title: string}> $sources
     */
    public function buildListOnly(array $sources): string
    {
        return $this->buildSpeech('', $sources);
    }

    /**
     * "Site, título" — ou apenas o título quando ele já contém o nome do site.
     *
     * @param array{site_name: string, spoken_title: string} $source
     */
    public function describe(array $source): string
    {
        $site  = trim($source['site_name'] ?? '');
        $title = trim($source['spoken_title'] ?? '');

        if ($title === '') {
            return $site !== '' ? $site : 'sem título';
        }
        if ($site === '' || mb_stripos($title, $site) !== false) {
            return $title;
        }

        return "{$site}, {$title}";
    }

    /**
     * Nome falado do site a partir da URL (ex.: economia.uol.com.br -> UOL).
     */
    public function siteName(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $labels = array_values(array_filter(
            explode('.', mb_strtolower($host)),
            fn($label) => $label !== '' && !in_array($label, self::IGNORED_LABELS, true)
        ));

        if (empty($labels)) {
            return '';
        }

        // O último rótulo significativo é o domínio registrável (economia.uol.com.br -> uol)
        $label = end($labels);

        if (isset(self::SITE_NAMES[$label])) {
            return self::SITE_NAMES[$label];
        }

        $label = str_replace('-', ' ', $label);

        return mb_strlen($label) <= 3 ? mb_strtoupper($label) : mb_convert_case($label, MB_CASE_TITLE);
    }

    /**
     * Título curto e limpo para leitura em voz alta.
     */
    public function shortTitle(string $title): string
    {
        // Remove emojis e símbolos decorativos
        $title = preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $title) ?? $title;
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        // Separadores comuns entre título e nome do site: " | ", " - ", " – ", " — ", " » "
        $segments = preg_split('/\s+[|\-–—»·]\s+/u', $title) ?: [$title];
        $segments = array_values(array_filter(array_map('trim', $segments), fn($s) => $s !== ''));

        $chosen = $segments[0] ?? '';
        foreach ($segments as $segment) {
            if (mb_strlen($segment) >= 8) {
                $chosen = $segment;
                break;
            }
        }

        // "UOL Economia: encontre orientações..." -> "UOL Economia"
        if (str_contains($chosen, ':')) {
            $before = trim(explode(':', $chosen, 2)[0]);
            if (mb_strlen($before) >= 8) {
                $chosen = $before;
            }
        }

        $chosen = trim($chosen, " \t\n\r\0\x0B.…-–—|");
        $chosen = rtrim(preg_replace('/\.{3,}$/u', '', $chosen) ?? $chosen);

        if (mb_strlen($chosen) > self::MAX_TITLE_LENGTH) {
            $cut = mb_substr($chosen, 0, self::MAX_TITLE_LENGTH);
            $lastSpace = mb_strrpos($cut, ' ');
            $chosen = $lastSpace !== false && $lastSpace > 30 ? mb_substr($cut, 0, $lastSpace) : $cut;
        }

        return trim($chosen);
    }
}
