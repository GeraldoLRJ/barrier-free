<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class DomSanitizerService
{
    /**
     * Tags que devem ser completamente removidas (tag + conteúdo).
     */
    protected array $removeTags = [
        'script', 'style', 'noscript', 'svg', 'iframe',
        'object', 'embed', 'applet', 'link', 'meta', 'head',
    ];

    /**
     * Limite máximo de caracteres do conteúdo sanitizado
     * para enviar ao modelo de IA.
     */
    protected int $maxLength = 15000;

    /**
     * Sanitiza o conteúdo HTML removendo elementos irrelevantes e perigosos,
     * preservando apenas o conteúdo textual e a estrutura semântica.
     */
    public function sanitize(string $htmlContent): string
    {
        Log::info('DomSanitizer: tamanho do HTML bruto recebido: ' . mb_strlen($htmlContent) . ' caracteres');

        // 0. Extrair apenas o conteúdo do <body> para reduzir o tamanho
        //    e evitar processar <head>, scripts do GTM, etc.
        if (preg_match('/<body\b[^>]*>(.*)<\/body>/is', $htmlContent, $matches)) {
            $htmlContent = $matches[1];
            Log::info('DomSanitizer: extraído conteúdo do body: ' . mb_strlen($htmlContent) . ' caracteres');
        }

        // 1. Remover tags perigosas/irrelevantes com seu conteúdo
        //    Usa operador ?? para proteger contra falha do PCRE (retorna null em backtracking overflow)
        foreach ($this->removeTags as $tag) {
            $htmlContent = preg_replace(
                '/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is',
                '',
                $htmlContent
            ) ?? $htmlContent;
            // Também remove tags self-closing (ex: <link />, <meta />)
            $htmlContent = preg_replace(
                '/<' . $tag . '\b[^>]*\/?>/is',
                '',
                $htmlContent
            ) ?? $htmlContent;
        }

        // 2. Remover comentários HTML
        $htmlContent = preg_replace('/<!--.*?-->/s', '', $htmlContent) ?? $htmlContent;

        // 3. Remover atributos perigosos (event handlers, data-*, style)
        $htmlContent = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*[\'"]/i', '', $htmlContent) ?? $htmlContent;
        $htmlContent = preg_replace('/\s+data-[\w-]+\s*=\s*["\'][^"\']*[\'"]/i', '', $htmlContent) ?? $htmlContent;
        $htmlContent = preg_replace('/\s+style\s*=\s*["\'][^"\']*[\'"]/i', '', $htmlContent) ?? $htmlContent;

        // 4. Extrair apenas o texto visível (strip_tags), preservando quebras de linha
        // Isso é mais seguro que tentar remover elementos hidden/aria-hidden com regex,
        // pois os regexes podem acidentalmente remover blocos grandes de conteúdo.
        $htmlContent = str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>', '</li>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>'], "\n", $htmlContent);
        $htmlContent = strip_tags($htmlContent);

        // 5. Limpar espaços excessivos
        $htmlContent = preg_replace('/[ \t]+/', ' ', $htmlContent) ?? $htmlContent;
        $htmlContent = preg_replace('/\n{3,}/', "\n\n", $htmlContent) ?? $htmlContent;
        $htmlContent = trim($htmlContent);

        Log::info('DomSanitizer: tamanho do HTML sanitizado: ' . mb_strlen($htmlContent) . ' caracteres');

        // 6. Truncar para o limite máximo
        if (mb_strlen($htmlContent) > $this->maxLength) {
            $htmlContent = mb_substr($htmlContent, 0, $this->maxLength) . '... [conteúdo truncado]';
        }

        return $htmlContent;
    }
}
