<?php

namespace App\Mcp;

use Laravel\Mcp\Server;
use App\Mcp\Tools\ConsultGuidelines;

class VascularServer extends Server
{
    protected string $name = 'Vascular Guidelines Server';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        This server provides access to ESVS Vascular Guidelines.
        Use it to retrieve evidence-based recommendations for clinical queries.
    MARKDOWN;

    protected array $tools = [
        ConsultGuidelines::class,
    ];
}
