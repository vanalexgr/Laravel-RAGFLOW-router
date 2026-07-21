<?php

namespace App\Http\Controllers;

use App\Services\PendingCaseStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PendingCaseStateController extends Controller
{
    public function __construct(
        private readonly PendingCaseStateService $pendingState,
    ) {}

    public function show(string $chatId): JsonResponse|Response
    {
        $record = $this->pendingState->get($chatId);

        return $record === null ? response()->noContent() : response()->json($record);
    }

    public function update(Request $request, string $chatId): JsonResponse
    {
        $validated = $request->validate([
            'proceed' => 'required|boolean',
            'soft_warn' => 'required|boolean',
            'clarification_questions' => 'present|array|max:6',
            'clarification_questions.*' => 'string|max:2000',
            'provisional_diagnosis' => 'present|nullable|string|max:2000',
            'guidelines' => 'required|array|max:6',
            'guidelines.*' => ['string', 'max:100', 'regex:/^[a-z0-9_]+$/'],
            'retrieval_query' => 'required|string|max:2000',
            'scope' => 'required|string|in:knowledge_question,single_guideline,multi_guideline',
            'confirmation_message' => 'present|nullable|string|max:6000',
        ]);

        return response()->json($this->pendingState->put($chatId, $validated));
    }

    public function destroy(string $chatId): Response
    {
        $this->pendingState->forget($chatId);

        return response()->noContent();
    }
}
