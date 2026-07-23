<?php

namespace App\GateEval\Contracts;

interface GateSubject
{
    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $turn
     * @param  array<int, array<string, mixed>>  $priorOutputs
     * @return array<string, mixed>
     */
    public function runTurn(array $scenario, array $turn, int $turnIndex, array $priorOutputs): array;

    public function identity(): string;
}
