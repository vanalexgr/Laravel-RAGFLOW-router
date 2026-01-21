<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class PHIScrubberService
{
    protected array $commonFirstNames = [];
    protected array $commonLastNames = [];
    protected bool $namesLoaded = false;

    protected array $redactionCounts = [];
    protected array $majorCities = [];

    public function __construct()
    {
        $this->resetCounts();
        $this->loadMajorCities();
    }

    protected function resetCounts(): void
    {
        $this->redactionCounts = [
            'names' => 0,
            'dates' => 0,
            'ages_over_90' => 0,
            'ssn' => 0,
            'mrn' => 0,
            'phone' => 0,
            'email' => 0,
            'ip_address' => 0,
            'address' => 0,
            'geographic' => 0,
            'device_id' => 0,
            'account_number' => 0,
            'license_number' => 0,
            'vehicle_id' => 0,
            'biometric' => 0,
            'url' => 0,
        ];
    }

    protected function loadMajorCities(): void
    {
        if (!empty($this->majorCities)) {
            return;
        }

        $citiesFile = config('phi.files.major_cities', storage_path('app/phi/major_cities.json'));
        if (file_exists($citiesFile)) {
            $data = json_decode(file_get_contents($citiesFile), true);
            $this->majorCities = array_map('strtolower', $data['cities'] ?? []);
        } else {
            Log::channel('retrieval')->warning('[PHI SCRUBBER] Major cities file missing.', [
                'path' => $citiesFile
            ]);
            $this->majorCities = [];
        }
    }

    protected function loadNames(): void
    {
        if ($this->namesLoaded) {
            return;
        }

        $namesFile = config('phi.files.common_names', storage_path('app/phi/common_names.json'));
        if (file_exists($namesFile)) {
            $data = json_decode(file_get_contents($namesFile), true);
            $this->commonFirstNames = array_map('strtolower', $data['first_names'] ?? []);
            $this->commonLastNames = array_map('strtolower', $data['last_names'] ?? []);
        } else {
            Log::channel('retrieval')->error('[PHI SCRUBBER] Common names file missing! Name redaction will be limited.', [
                'path' => $namesFile
            ]);
        }
        $this->namesLoaded = true;
    }

    public function scrub(string $text): array
    {
        $this->resetCounts();
        $this->loadNames();
        $this->loadMajorCities();

        $original = $text;
        $scrubbed = $text;

        $scrubbed = $this->scrubSSN($scrubbed);
        $scrubbed = $this->scrubMRN($scrubbed);
        $scrubbed = $this->scrubPhone($scrubbed);
        $scrubbed = $this->scrubEmail($scrubbed);
        $scrubbed = $this->scrubIP($scrubbed);
        $scrubbed = $this->scrubURL($scrubbed);
        $scrubbed = $this->scrubDates($scrubbed);
        $scrubbed = $this->scrubAgesOver90($scrubbed);
        $scrubbed = $this->scrubDeviceIdentifiers($scrubbed);
        $scrubbed = $this->scrubVehicleIdentifiers($scrubbed);
        $scrubbed = $this->scrubBiometricDescriptors($scrubbed);
        $scrubbed = $this->scrubLicenseNumbers($scrubbed);
        $scrubbed = $this->scrubAccountNumbers($scrubbed);
        $scrubbed = $this->scrubAddresses($scrubbed);
        $scrubbed = $this->scrubGeographicLocations($scrubbed);
        $scrubbed = $this->scrubNames($scrubbed);

        $wasModified = $original !== $scrubbed;
        $totalRedactions = array_sum($this->redactionCounts);

        return [
            'original_length' => strlen($original),
            'scrubbed_text' => $scrubbed,
            'was_modified' => $wasModified,
            'total_redactions' => $totalRedactions,
            'redaction_counts' => $this->redactionCounts,
        ];
    }

    protected function scrubSSN(string $text): string
    {
        $pattern = config('phi.patterns.ssn');
        $text = preg_replace_callback($pattern, function ($matches) {
            if (preg_match('/^\d{3}-\d{2}-\d{4}$/', $matches[0]) || 
                preg_match('/^\d{9}$/', preg_replace('/[-\s]/', '', $matches[0]))) {
                $this->redactionCounts['ssn']++;
                return '[SSN]';
            }
            return $matches[0];
        }, $text);
        return $text;
    }

    protected function scrubMRN(string $text): string
    {
        $patterns = config('phi.patterns.mrn', []);

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $this->redactionCounts['mrn']++;
                return '[MRN]';
            }, $text);
        }
        return $text;
    }

    protected function scrubPhone(string $text): string
    {
        $patterns = config('phi.patterns.phone', []);

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $this->redactionCounts['phone']++;
                return '[PHONE]';
            }, $text);
        }
        return $text;
    }

    protected function scrubEmail(string $text): string
    {
        $pattern = config('phi.patterns.email');
        $text = preg_replace_callback($pattern, function ($matches) {
            $this->redactionCounts['email']++;
            return '[EMAIL]';
        }, $text);
        return $text;
    }

    protected function scrubIP(string $text): string
    {
        $pattern = config('phi.patterns.ip_address');
        $text = preg_replace_callback($pattern, function ($matches) {
            $this->redactionCounts['ip_address']++;
            return '[IP]';
        }, $text);
        return $text;
    }

    protected function scrubURL(string $text): string
    {
        $pattern = config('phi.patterns.url');
        $text = preg_replace_callback($pattern, function ($matches) {
            $this->redactionCounts['url']++;
            return '[URL]';
        }, $text);
        return $text;
    }

    protected function scrubDates(string $text): string
    {
        $patterns = config('phi.patterns.dates', []);

        foreach ($patterns as $pattern => $type) {
            $text = preg_replace_callback($pattern, function ($matches) use ($type) {
                $this->redactionCounts['dates']++;
                if ($type === 'dob') {
                    return 'DOB [DATE]';
                } elseif ($type === 'born') {
                    return 'born [DATE]';
                }
                return '[DATE]';
            }, $text);
        }
        return $text;
    }

    protected function scrubAgesOver90(string $text): string
    {
        $patterns = [
            '/\b(9[0-9]|1[0-9]{2})[-\s]*(year[-\s]*old|yo|y\.o\.|years[-\s]*old|yr[-\s]*old)\b/i',
            '/\b(age|aged)[:\s]*(9[0-9]|1[0-9]{2})\b/i',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $age = null;
                foreach ($matches as $match) {
                    if (is_numeric($match) && (int)$match >= 90) {
                        $age = (int)$match;
                        break;
                    }
                }
                
                if ($age !== null && $age >= 90) {
                    $this->redactionCounts['ages_over_90']++;
        $pattern = config('phi.patterns.age_over_90');
        $text = preg_replace_callback($pattern, function ($matches) {
            $this->redactionCounts['ages']++;
            return '[AGE>90]';
        }, $text);
        return $text;
    }

    protected function scrubDeviceIdentifiers(string $text): string
    {
        $patterns = config('phi.patterns.device_id', []);

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $this->redactionCounts['device_id']++;
                return '[DEVICE_ID]';
            }, $text);
        }
        return $text;
    }

    protected function scrubVehicleIdentifiers(string $text): string
    {
        $patterns = config('phi.patterns.vehicle_id', []);

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $this->redactionCounts['vehicle_id']++;
                return '[VEHICLE_ID]';
            }, $text);
        }
        return $text;
    }

    protected function scrubBiometricDescriptors(string $text): string
    {
        $patterns = config('phi.patterns.biometric', []);

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $this->redactionCounts['biometric']++;
                return '[BIOMETRIC]';
            }, $text);
        }
        return $text;
    }

    protected function scrubLicenseNumbers(string $text): string
    {
        $patterns = config('phi.patterns.license_number', []);

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $this->redactionCounts['license_number']++;
                return '[LICENSE]';
            }, $text);
        }
        return $text;
    }

    protected function scrubAccountNumbers(string $text): string
    {
        $patterns = config('phi.patterns.account_number', []);

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $this->redactionCounts['account_number']++;
                return '[ACCOUNT]';
            }, $text);
        }
        return $text;
    }

    protected function scrubAddresses(string $text): string
    {
        $patterns = config('phi.patterns.address', []);

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $this->redactionCounts['address']++;
                return '[ADDRESS]';
            }, $text);
        }

        $states = config('phi.dictionaries.us_states', []);
        $stateAbbrevs = config('phi.dictionaries.us_state_abbreviations', []);

        if (!empty($states) && !empty($stateAbbrevs)) {
            $statePattern = '/\b(' . implode('|', array_merge($states, $stateAbbrevs)) . '),?\s*\d{5}(-\d{4})?\b/i';
            $text = preg_replace_callback($statePattern, function ($matches) {
                $this->redactionCounts['address']++;
                return '[LOCATION]';
            }, $text);
        }

        return $text;
    }

    protected function scrubGeographicLocations(string $text): string
    {
        $textLower = strtolower($text);
        
        foreach ($this->majorCities as $city) {
            if (stripos($textLower, $city) !== false) {
                $pattern = '/\b' . preg_quote($city, '/') . '\b/i';
                $text = preg_replace_callback($pattern, function ($matches) {
                    $this->redactionCounts['geographic']++;
                    return '[CITY]';
                }, $text);
                $textLower = strtolower($text);
            }
        }

        $text = preg_replace_callback('/\b\d{5}(-\d{4})?\b/', function ($matches) {
            if (preg_match('/^\d{5}(-\d{4})?$/', $matches[0])) {
                $zip = (int)substr($matches[0], 0, 5);
                if ($zip >= 501 && $zip <= 99950) {
                    $this->redactionCounts['geographic']++;
                    return '[ZIP]';
                }
            }
            return $matches[0];
        }, $text);

        $countyPatterns = config('phi.patterns.geographic', []);
        foreach ($countyPatterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $this->redactionCounts['geographic']++;
                return '[COUNTY]';
            }, $text);
        }

        return $text;
    }

    protected function scrubNames(string $text): string
    {
        if (empty($this->commonFirstNames) && empty($this->commonLastNames)) {
            return $text;
        }

        $words = preg_split('/(\s+)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $result = [];
        $skipNext = false;

        for ($i = 0; $i < count($words); $i++) {
            $word = $words[$i];
            
            if (preg_match('/^\s+$/', $word)) {
                $result[] = $word;
                continue;
            }

            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            $cleanWord = preg_replace('/[^a-zA-Z]/', '', $word);
            $lowerWord = strtolower($cleanWord);

            if (strlen($cleanWord) < 2) {
                $result[] = $word;
                continue;
            }

            $isFirstName = in_array($lowerWord, $this->commonFirstNames);
            $isLastName = in_array($lowerWord, $this->commonLastNames);

            if ($isFirstName && preg_match('/^[A-Z]/', $cleanWord)) {
                $nextWordIndex = $i + 2;
                if ($nextWordIndex < count($words)) {
                    $nextWord = preg_replace('/[^a-zA-Z]/', '', $words[$nextWordIndex]);
                    $nextLower = strtolower($nextWord);
                    
                    if (in_array($nextLower, $this->commonLastNames) && preg_match('/^[A-Z]/', $nextWord)) {
                        $this->redactionCounts['names']++;
                        $result[] = '[NAME]';
                        $skipNext = true;
                        $i += 2;
                        continue;
                    }
                }
            }

            $result[] = $word;
        }

        return implode('', $result);
    }

    public function getRedactionCounts(): array
    {
        return $this->redactionCounts;
    }

    public function logAudit(string $correlationId, array $scrubResult): void
    {
        if (!$scrubResult['was_modified']) {
            return;
        }

        Log::channel('retrieval')->info('[PHI SCRUBBER] De-identification applied', [
            'correlation_id' => $correlationId,
            'original_length' => $scrubResult['original_length'],
            'scrubbed_length' => strlen($scrubResult['scrubbed_text']),
            'total_redactions' => $scrubResult['total_redactions'],
            'redaction_counts' => array_filter($scrubResult['redaction_counts']),
        ]);
    }
}
