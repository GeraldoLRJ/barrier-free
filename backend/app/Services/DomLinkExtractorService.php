<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;

class DomLinkExtractorService
{
    /**
     * Número máximo de links retornados para não sobrecarregar a extensão.
     */
    protected int $maxLinks = 30;

    /**
     * Extrai os links navegáveis de um HTML bruto.
     *
     * @param string $html     HTML bruto da página
     * @param string $baseUrl  URL base da página (para normalizar URLs relativas)
     * @return array<int, array{text: string, url: string}>
     */
    public function extract(string $html, string $baseUrl): array
    {
        if (empty(trim($html))) {
            return [];
        }

        // Suprimir erros de parsing de HTML malformado
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Buscar todas as tags <a> com href
        $nodes = $xpath->query('//a[@href]');

        $links   = [];
        $seen    = [];
        $baseUri = $this->parseBaseUri($baseUrl);

        foreach ($nodes as $node) {
            $href = trim($node->getAttribute('href'));
            $text = $this->sanitizeLinkText($node->textContent);

            // Filtrar hrefs inúteis e textos que ficaram vazios após sanitização
            if ($this->shouldSkip($href, $text)) {
                continue;
            }

            // Normalizar para URL absoluta
            $absoluteUrl = $this->toAbsolute($href, $baseUri);

            if (!$absoluteUrl) {
                continue;
            }

            // Evitar duplicatas de URL
            if (isset($seen[$absoluteUrl])) {
                continue;
            }

            $seen[$absoluteUrl] = true;

            $links[] = [
                'text' => $text,
                'url'  => $absoluteUrl,
            ];

            if (count($links) >= $this->maxLinks) {
                break;
            }
        }

        return $links;
    }

    /**
     * Verifica se um link deve ser ignorado.
     */
    protected function shouldSkip(string $href, string $text): bool
    {
        // Texto vazio ou muito curto demais para ser útil por voz
        if (mb_strlen($text) < 2) {
            return true;
        }

        // Hrefs vazios, âncoras internas ou javascript:
        if (
            $href === ''
            || $href === '#'
            || str_starts_with($href, 'javascript:')
            || str_starts_with($href, 'mailto:')
            || str_starts_with($href, 'tel:')
        ) {
            return true;
        }

        // Apenas âncoras internas (começa com # e tem algo depois)
        if (str_starts_with($href, '#')) {
            return true;
        }

        return false;
    }

    /**
     * Limpa o texto do link para ser lido por voz:
     * - Remove URLs embutidas no textContent
     * - Colapsa espaços/quebras de linha
     * - Trunca a 60 caracteres
     */
    protected function sanitizeLinkText(string $text): string
    {
        // Remove URLs http(s) que aparecem no texto
        $text = preg_replace('/https?:\/\/\S+/i', '', $text);

        // Colapsa espaços e quebras de linha
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Truncar a 60 caracteres para não sobrecarregar o TTS
        if (mb_strlen($text) > 60) {
            $text = mb_substr($text, 0, 57) . '...';
        }

        return $text;
    }

    /**
     * Extrai esquema + host da URL base para montar URLs absolutas.
     */
    protected function parseBaseUri(string $baseUrl): string
    {
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host'] ?? '';
        $path   = $parsed['path'] ?? '/';

        // Remove o último segmento do path (arquivo/página) para ter o diretório base
        $dir = rtrim(dirname($path), '/');

        return $scheme . '://' . $host . $dir;
    }

    /**
     * Converte um href possivelmente relativo em URL absoluta.
     */
    protected function toAbsolute(string $href, string $baseUri): ?string
    {
        // Já é absoluta
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        // Relativa à raiz do domínio (ex: /sobre)
        if (str_starts_with($href, '/')) {
            $parsed = parse_url($baseUri);
            $scheme = $parsed['scheme'] ?? 'https';
            $host   = $parsed['host'] ?? '';
            return $scheme . '://' . $host . $href;
        }

        // Relativa ao diretório atual
        if (!empty($baseUri)) {
            return rtrim($baseUri, '/') . '/' . $href;
        }

        return null;
    }
}
