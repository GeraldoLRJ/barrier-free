<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDomRequest;
use App\Actions\ProcessDomAction;
use Illuminate\Http\JsonResponse;

class DomProcessController extends Controller
{
    /**
     * Handle the incoming DOM data.
     */
    public function __invoke(StoreDomRequest $request, ProcessDomAction $processDomAction): JsonResponse
    {
        // Pega HTML validado do payload
        $validated = $request->validated();
        
        // Passa os dados para a Action responsável por processar a regra de negócio
        $result = $processDomAction->execute($validated['html_content'], $validated['url'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'DOM recebido e processado com sucesso.',
            'data' => $result
        ]);
    }
}
