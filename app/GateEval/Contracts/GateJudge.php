<?php

namespace App\GateEval\Contracts;

interface GateJudge
{
    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $turn
     * @param  array<string, mixed>  $output
     * @return array{grade:string, failure_labels:array<int, string>, reason:string}
     */
    public function judge(array $scenario, array $turn, array $output): array;

    public function identity(): string;
}
