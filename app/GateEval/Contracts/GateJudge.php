<?php

namespace App\GateEval\Contracts;

interface GateJudge
{
    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, mixed>  $turn
     * @param  array<string, mixed>  $output
     *
     * The top-level grade/failure_labels/reason fields are retained for old
     * artifact readers. New judges additionally return independent clinical
     * and mechanical dimension groups.
     * @return array<string, mixed>
     */
    public function judge(array $scenario, array $turn, array $output): array;

    public function identity(): string;
}
