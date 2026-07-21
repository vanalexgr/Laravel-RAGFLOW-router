<?php

namespace App\Http\Controllers;

use App\Services\CaseStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CaseStateController extends Controller
{
    public function __construct(
        private readonly CaseStateService $caseState,
    ) {}

    public function show(string $chatId): JsonResponse|Response
    {
        $record = $this->caseState->get($chatId);

        return $record === null ? response()->noContent() : response()->json($record);
    }

    public function update(Request $request, string $chatId): JsonResponse
    {
        $validated = $request->validate([
            'provisional_diagnosis' => 'required|string|max:2000',
            'guidelines' => 'required|array|max:6',
            'guidelines.*' => 'string|max:100',
            'retrieval_query' => 'required|string|max:2000',
        ]);

        return response()->json($this->caseState->put($chatId, $validated));
    }

    public function destroy(string $chatId): Response
    {
        $this->caseState->forget($chatId);

        return response()->noContent();
    }
}
