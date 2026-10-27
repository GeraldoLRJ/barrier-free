<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

class DomLinkExtractorService
{
    /**
     * Número máximo de links retornados para cobrir menus e conteúdo de portais grandes.
     */
    protected int $maxLinks = 500;

    /**
     * Tags que devem ser removidas antes de extrair links.
     */
    protected array $removeTags = [
        'script', 'style', 'noscript', 'iframe',
        'object', 'embed', 'applet', 'link', 'meta', 'head',
    ];

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

        // Carregar o HTML com wrapper UTF-8 sem deprecation warning no PHP 8.2+
        $dom = new DOMDocument();
        $wrapped = '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        $encoded = mb_encode_numericentity($wrapped, [0x80, 0x10FFFF, 0, ~0], 'UTF-8');
        @$dom->loadHTML($encoded, LIBXML_NOERROR | LIBXML_NOWARNING);

        $this->removeUnsafeTags($dom);
        $this->removeHiddenElements($dom);

        $xpath = new DOMXPath($dom);

        // Ordem de prioridade:
        // 1. Links do conteúdo principal (<main>, role="main") — têm prioridade em caso de empate (ex: resultado vs aba do buscador)
        // 2. Links de navegação (<nav>, role="navigation", <header>) — editorias do site ("Jogos", "Economia", etc.)
        // 3. Demais links da página
        // Com $maxLinks = 500, mesmo portais grandes mantêm tanto o <main> quanto todo o <nav>/<header>.
        $mainNodes = $xpath->query('(//main | //*[@role="main"])//a[@href]');
        $navNodes  = $xpath->query('(//nav | //*[@role="navigation"] | //header)//a[@href]');
        $allNodes  = $xpath->query('//a[@href]');

        $nodes = array_merge(
            $mainNodes ? array_slice(iterator_to_array($mainNodes), 0, 350) : [],
            $navNodes ? iterator_to_array($navNodes) : [],
            $allNodes ? iterator_to_array($allNodes) : []
        );

        $links         = [];
        $internalLinks = [];
        $seen          = [];
        $baseUri       = $this->parseBaseUri($baseUrl);
        $isSearchPage  = $this->isSearchEnginePage($baseUrl);
        $baseHost      = strtolower(parse_url($baseUrl, PHP_URL_HOST) ?? '');

        foreach ($nodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }

            $href = trim($node->getAttribute('href'));
            $rawText = $this->extractAccessibleLinkText($node);
            $text = $this->sanitizeLinkText($rawText);

            // Filtrar hrefs inúteis e textos que ficaram vazios após sanitização
            if ($this->shouldSkip($href, $text)) {
                continue;
            }

            // Normalizar para URL absoluta (desempacotando redirecionamentos do Google se houver)
            $absoluteUrl = $this->toAbsolute($href, $baseUri);

            if (!$absoluteUrl) {
                continue;
            }

            // Em páginas de busca (ex: Google), ignorar controles internos que causam confusão ("Modo IA", login, etc.)
            if ($isSearchPage && $this->shouldSkipSearchEngineInternalLink($absoluteUrl, $text)) {
                continue;
            }

            // Evitar duplicatas de URL (normalizando barra final)
            $dedupKey = rtrim($absoluteUrl, '/');
            if (isset($seen[$dedupKey])) {
                continue;
            }

            $seen[$dedupKey] = true;

            $linkItem = [
                'text' => $text,
                'url'  => $absoluteUrl,
            ];

            // Em páginas de resultados de busca, priorizar sites externos antes de abas internas do buscador
            $linkHost = strtolower(parse_url($absoluteUrl, PHP_URL_HOST) ?? '');
            if ($isSearchPage && $linkHost !== '' && ($linkHost === $baseHost || str_contains($linkHost, 'google.'))) {
                $internalLinks[] = $linkItem;
            } else {
                $links[] = $linkItem;
            }

            if ((count($links) + count($internalLinks)) >= $this->maxLinks) {
                break;
            }
        }

        return array_slice(array_merge($links, $internalLinks), 0, $this->maxLinks);
    }

    /**
     * Remove tags de script/estilo para não poluir o texto dos links.
     */
    protected function removeUnsafeTags(DOMDocument $dom): void
    {
        foreach ($this->removeTags as $tagName) {
            $elements = $dom->getElementsByTagName($tagName);
            $toRemove = [];
            for ($i = 0; $i < $elements->length; $i++) {
                $toRemove[] = $elements->item($i);
            }
            foreach ($toRemove as $element) {
                $element->parentNode?->removeChild($element);
            }
        }
    }

    /**
     * Remove elementos ocultos (aria-hidden="true", hidden, display:none, visibility:hidden).
     */
    protected function removeHiddenElements(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $toRemove = [];

        foreach ($xpath->query('//*[@aria-hidden="true"] | //*[@hidden]') as $node) {
            $toRemove[] = $node;
        }

        foreach ($xpath->query('//*[@style]') as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            $style = $node->getAttribute('style');
            if (
                preg_match('/display\s*:\s*none/i', $style) ||
                preg_match('/visibility\s*:\s*hidden/i', $style)
            ) {
                $toRemove[] = $node;
            }
        }

        foreach ($toRemove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /**
     * Extrai o texto acessível de um elemento <a>:
     * - Se houver um heading (h1..h6) interno (comum em resultados de busca e cards de portais),
     *   prioriza-o e preserva o nome do site/fonte caso esteja fora do heading (ex: span em resultados do Google)
     * - Caso contrário, concatena textos dos nós filhos separados por espaço
     * - Faz fallback para aria-label, title ou alt de imagens internas
     */
    protected function extractAccessibleLinkText(DOMElement $node): string
    {
        // Se o link envolve um card com título (h1-h6), priorizar o texto do heading
        foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6'] as $headingTag) {
            $headings = $node->getElementsByTagName($headingTag);
            if ($headings->length > 0) {
                $headingNode = $headings->item(0);
                $headingText = trim($this->collectNodeTextWithSpaces($headingNode));
                if ($headingText !== '') {
                    $extraText = trim($this->collectNodeTextWithSpaces($node, $headingNode));
                    $extraText = trim(preg_replace('/https?:\/\/\S+/i', '', $extraText) ?? $extraText);
                    if (
                        $extraText !== ''
                        && mb_strlen($extraText) <= 50
                        && mb_stripos($headingText, $extraText) === false
                    ) {
                        return "{$headingText} - {$extraText}";
                    }
                    return $headingText;
                }
            }
        }

        $ariaLabel = trim($node->getAttribute('aria-label') ?? '');
        if ($ariaLabel !== '') {
            return $ariaLabel;
        }

        $visibleText = trim($this->collectNodeTextWithSpaces($node));
        if ($visibleText !== '') {
            return $visibleText;
        }

        $title = trim($node->getAttribute('title') ?? '');
        if ($title !== '') {
            return $title;
        }

        // Fallback para alt de imagem dentro do link
        $images = $node->getElementsByTagName('img');
        for ($i = 0; $i < $images->length; $i++) {
            $img = $images->item($i);
            if ($img instanceof DOMElement) {
                $alt = trim($img->getAttribute('alt') ?? '');
                if ($alt !== '') {
                    return $alt;
                }
            }
        }

        return '';
    }

    /**
     * Coleta recursivamente o texto de um nó inserindo espaço entre elementos adjacentes.
     */
    protected function collectNodeTextWithSpaces(DOMNode $node, ?DOMNode $excludeNode = null): string
    {
        if ($excludeNode !== null && $node->isSameNode($excludeNode)) {
            return '';
        }

        if ($node->nodeType === XML_TEXT_NODE) {
            return $node->textContent;
        }

        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return '';
        }

        if (in_array(strtolower($node->nodeName), ['svg', 'cite'], true)) {
            return '';
        }

        $parts = [];
        foreach ($node->childNodes as $child) {
            $childText = trim($this->collectNodeTextWithSpaces($child, $excludeNode));
            if ($childText !== '') {
                $parts[] = $childText;
            }
        }

        return implode(' ', $parts);
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
     * - Trunca a 120 caracteres para preservar tanto o título quanto o nome do site
     */
    protected function sanitizeLinkText(string $text): string
    {
        // Remove URLs http(s) que aparecem no texto
        $text = preg_replace('/https?:\/\/\S+/i', '', $text);

        // Colapsa espaços e quebras de linha
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        // Truncar a 120 caracteres para não cortar o nome da fonte ao final do título
        if (mb_strlen($text) > 120) {
            $text = mb_substr($text, 0, 117) . '...';
        }

        return $text;
    }

    /**
     * Identifica se a URL analisada é uma página de resultados de busca (ex: Google, Bing).
     */
    protected function isSearchEnginePage(string $url): bool
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');

        return (str_contains($host, 'google.') || str_contains($host, 'bing.com'))
            && str_starts_with($path, '/search');
    }

    /**
     * Filtra links internos de interface do buscador que não são destinos úteis de navegação por voz
     * (ex: "Modo IA", login, acessibilidade, feedback, termos).
     */
    protected function shouldSkipSearchEngineInternalLink(string $absoluteUrl, string $text): bool
    {
        $lowerText = mb_strtolower(trim($text));

        // Ignorar explicitamente o "Modo IA" e controles utilitários do Google
        $blockedTexts = [
            'modo ia',
            'ai mode',
            'fazer login',
            'ir para o conteúdo principal',
            'ajuda sobre acessibilidade',
            'feedback',
            'enviar feedback',
            'privacidade',
            'termos',
            'configurações',
            'ferramentas',
            'mais',
            'tudo',
        ];

        if (in_array($lowerText, $blockedTexts, true)) {
            return true;
        }

        // Ignorar parâmetro udm=50 (Modo IA do Google) ou links de login/conta do Google
        if (str_contains($absoluteUrl, 'udm=50') || str_contains($absoluteUrl, 'accounts.google.')) {
            return true;
        }

        return false;
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
        $resolved = null;

        // Já é absoluta
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            $resolved = $href;
        } elseif (str_starts_with($href, '/')) {
            // Relativa à raiz do domínio (ex: /sobre)
            $parsed = parse_url($baseUri);
            $scheme = $parsed['scheme'] ?? 'https';
            $host   = $parsed['host'] ?? '';
            $resolved = $scheme . '://' . $host . $href;
        } elseif (!empty($baseUri)) {
            // Relativa ao diretório atual
            $resolved = rtrim($baseUri, '/') . '/' . $href;
        }

        if ($resolved !== null) {
            return $this->unwrapSearchRedirect($resolved);
        }

        return null;
    }

    /**
     * Se a URL for um redirecionamento do Google (/url?q=https://... ou /url?url=https://...),
     * extrai a URL final do site de destino.
     */
    protected function unwrapSearchRedirect(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        if (str_contains($host, 'google.') && $path === '/url') {
            $query = parse_url($url, PHP_URL_QUERY) ?? '';
            parse_str($query, $params);
            $target = $params['q'] ?? ($params['url'] ?? null);
            if (is_string($target) && (str_starts_with($target, 'http://') || str_starts_with($target, 'https://'))) {
                return $target;
            }
        }

        return $url;
    }
}
