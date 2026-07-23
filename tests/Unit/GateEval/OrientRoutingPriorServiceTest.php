<?php

namespace Tests\Unit\GateEval;

use App\Ai\Gate\Routing\OrientRoutingPriorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OrientRoutingPriorServiceTest extends TestCase
{
    #[DataProvider('routes')]
    public function test_deterministic_priors(string $query, array $expected): void
    {
        $this->assertSame($expected, (new OrientRoutingPriorService)->candidates($query));
    }

    public static function routes(): array
    {
        return [
            ['management of graft infection after TEVAR', ['vascular_graft_infections', 'descending_thoracic_aorta']],
            ['infected dialysis access fistula', ['vascular_access']],
            ['AAA with CLTI Rutherford 5 tissue loss', ['abdominal_aortic_aneurysm', 'clti']],
            ['post EVAR antiplatelet decision', ['abdominal_aortic_aneurysm', 'antithrombotic_therapy']],
            ['carotid stenosis major disabling stroke mRS 4', ['carotid_vertebral']],
        ];
    }
}
