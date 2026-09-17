<?php

namespace App\Actions;

use App\Services\GeminiService;
use App\Services\DomLinkExtractorService;

class ProcessDomAction
{
    protected GeminiService $geminiService;
    protected DomLinkExtractorService $linkExtractor;

    public function __construct(GeminiService $geminiService, DomLinkExtractorService $linkExtractor)
    {
        $this->geminiService = $geminiService;
        $this->linkExtractor = $linkExtractor;
    }

    /**
     * Processa o DOM recebido da extensão.
     * Se o comando for de IA (resumir, explicar, orientar), delega ao GeminiService.
     * Caso contrário, mantém o processamento padrão.
     */
    public function execute(string $htmlContent, ?string $url = null, string $command = 'analisar', ?string $userPrompt = null): array
    {
        $aiCommands = ['resumir', 'orientar', 'buscar'];

        if (in_array($command, $aiCommands)) {
            $aiResult = $this->geminiService->analyze($htmlContent, $command, $url, $userPrompt);

            // Extrair links navegáveis da página para os comandos que analisam o DOM.
            // O comando "buscar" não analisa o DOM da página atual, então não extrai links.
            $pageLinks = null;
            if (in_array($command, ['resumir', 'orientar']) && !empty($htmlContent) && !empty($url)) {
                $pageLinks = $this->linkExtractor->extract($htmlContent, $url);
            }

            return [
                'analyzed_url'   => $url ?? 'unknown',
                'command'        => $command,
                'status'         => 'processed',
                'ai_response'    => $aiResult['text'],
                'search_sources' => $aiResult['search_sources'] ?? null,
                'page_links'     => $pageLinks,
            ];
        }

        // Comando não reconhecido — retornar erro
        return [
            'analyzed_url' => $url ?? 'unknown',
            'command'      => $command,
            'status'       => 'unknown_command',
        ];
    }
}
