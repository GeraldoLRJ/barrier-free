<?php

namespace App\Services;

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
        // 1. Remover tags perigosas/irrelevantes com seu conteúdo
        foreach ($this->removeTags as $tag) {
            $htmlContent = preg_replace(
                '/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is',
                '',
                $htmlContent
            );
            // Também remove tags self-closing (ex: <link />, <meta />)
            $htmlContent = preg_replace(
                '/<' . $tag . '\b[^>]*\/?>/is',
                '',
                $htmlContent
            );
        }

        // 2. Remover comentários HTML
        $htmlContent = preg_replace('/<!--.*?-->/s', '', $htmlContent);

        // 3. Remover atributos perigosos (event handlers, data-*, style)
        $htmlContent = preg_replace('/\s+on\w+\s*=\s*["\'][^"\']*["\']/i', '', $htmlContent);
        $htmlContent = preg_replace('/\s+data-[\w-]+\s*=\s*["\'][^"\']*["\']/i', '', $htmlContent);
        $htmlContent = preg_replace('/\s+style\s*=\s*["\'][^"\']*["\']/i', '', $htmlContent);

        // 4. Remover elementos ocultos (hidden, display:none, aria-hidden="true")
        $htmlContent = preg_replace('/<[^>]+\bhidden\b[^>]*>.*?<\/[^>]+>/is', '', $htmlContent);
        $htmlContent = preg_replace('/<[^>]+aria-hidden\s*=\s*["\']true["\'][^>]*>.*?<\/[^>]+>/is', '', $htmlContent);

        // 5. Limpar espaços excessivos
        $htmlContent = preg_replace('/\s+/', ' ', $htmlContent);
        $htmlContent = trim($htmlContent);

        // 6. Truncar para o limite máximo
        if (mb_strlen($htmlContent) > $this->maxLength) {
            $htmlContent = mb_substr($htmlContent, 0, $this->maxLength) . '... [conteúdo truncado]';
        }

        return $htmlContent;
    }
}
