<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDomRequest;
use App\Actions\ProcessDomAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DomProcessController extends Controller
{
    /**
     * Handle the incoming DOM data.
     */
    public function __invoke(StoreDomRequest $request, ProcessDomAction $processDomAction): JsonResponse
    {
        // Pega dados validados do payload
        $validated = $request->validated();

        Log::info('URL: ' . ($validated['url'] ?? ''));
        Log::info('Comando: ' . ($validated['command'] ?? ''));
        Log::info('Conteúdo HTML: ' . ($validated['html_content'] ?? ''));

        // Passa os dados para a Action responsável por processar a regra de negócio
        $result = $processDomAction->execute(
            $validated['html_content'] ?? null,
            $validated['url'] ?? null,
            $validated['command'] ?? 'analisar',
            $validated['user_prompt'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'DOM recebido e processado com sucesso.',
            'data' => $result
        ]);
    }
}
