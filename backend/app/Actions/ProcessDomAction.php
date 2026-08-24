<?php

namespace App\Actions;

use App\Services\GeminiService;

class ProcessDomAction
{
    protected GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
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

            return [
                'analyzed_url'   => $url ?? 'unknown',
                'command'        => $command,
                'status'         => 'processed',
                'ai_response'    => $aiResult['text'],
                'search_sources' => $aiResult['search_sources'] ?? null,
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
