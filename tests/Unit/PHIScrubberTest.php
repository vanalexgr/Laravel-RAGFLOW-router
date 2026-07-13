<?php

namespace Tests\Unit;

use App\Services\PHIScrubberService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PHIScrubberTest extends TestCase
{
    protected PHIScrubberService $scrubber;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock configuration
        Config::set('phi.patterns.ssn', '/\b\d{3}[-\s]?\d{2}[-\s]?\d{4}\b/');
        Config::set('phi.patterns.email', '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/');
        Config::set('phi.files.common_names', '/tmp/nonexistent_names.json'); // Simulate missing file
        Config::set('phi.files.major_cities', '/tmp/nonexistent_cities.json'); // Simulate missing file

        $this->scrubber = new PHIScrubberService;
    }

    public function test_scrubs_ssn()
    {
        $text = 'My SSN is 123-45-6789.';
        $result = $this->scrubber->scrub($text);

        $this->assertStringContainsString('[SSN]', $result['scrubbed_text']);
        $this->assertStringNotContainsString('123-45-6789', $result['scrubbed_text']);
        $this->assertEquals(1, $result['redaction_counts']['ssn']);
    }

    public function test_scrubs_email()
    {
        $text = 'Contact me at test@example.com.';
        $result = $this->scrubber->scrub($text);

        $this->assertStringContainsString('[EMAIL]', $result['scrubbed_text']);
        $this->assertStringNotContainsString('test@example.com', $result['scrubbed_text']);
        $this->assertEquals(1, $result['redaction_counts']['email']);
    }

    public function test_handles_missing_files_gracefully()
    {
        Config::set('phi.files.common_names', '/tmp/nonexistent_names_'.uniqid().'.json');
        Config::set('phi.files.major_cities', '/tmp/nonexistent_cities_'.uniqid().'.json');

        // Expect a log warning or error
        Log::shouldReceive('channel')->with('retrieval')->andReturnSelf();
        Log::shouldReceive('error')->once(); // For missing names
        Log::shouldReceive('warning')->once(); // For missing cities (if it logs warning)

        $text = 'John Doe lived in New York.';
        $result = (new PHIScrubberService)->scrub($text);

        // Since dictionary is empty, it might not scrub the name "John Doe" or "New York"
        // unless regex logic catches it.
        // We mainly verify it doesn't crash.
        $this->assertIsArray($result);
    }

    public function test_name_scrubbing_preserves_the_following_clinical_word(): void
    {
        $namesFile = tempnam(sys_get_temp_dir(), 'phi_names_');
        file_put_contents($namesFile, json_encode([
            'first_names' => ['John'],
            'last_names' => ['Smith'],
        ]));
        Config::set('phi.files.common_names', $namesFile);

        $result = (new PHIScrubberService)->scrub('John Smith presented with claudication');

        $this->assertSame('[NAME] presented with claudication', $result['scrubbed_text']);
        unlink($namesFile);
    }

    public function test_age_over_90_uses_the_initialized_counter(): void
    {
        Config::set('phi.patterns.ages_over_90', [
            '/\b(?:9[1-9]|1[01][0-9]|120)[ -]?(?:years? old|y\/o)\b/i',
        ]);

        $result = $this->scrubber->scrub('The patient is 94 years old.');

        $this->assertSame(1, $result['redaction_counts']['ages_over_90']);
        $this->assertSame(
            array_sum($result['redaction_counts']),
            $result['total_redactions']
        );
    }
}
