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
        $this->majorCities = [
            'new york', 'los angeles', 'chicago', 'houston', 'phoenix', 'philadelphia',
            'san antonio', 'san diego', 'dallas', 'san jose', 'austin', 'jacksonville',
            'fort worth', 'columbus', 'charlotte', 'san francisco', 'indianapolis', 'seattle',
            'denver', 'washington', 'boston', 'el paso', 'nashville', 'detroit', 'oklahoma city',
            'portland', 'las vegas', 'memphis', 'louisville', 'baltimore', 'milwaukee',
            'albuquerque', 'tucson', 'fresno', 'mesa', 'sacramento', 'atlanta', 'kansas city',
            'colorado springs', 'miami', 'raleigh', 'omaha', 'long beach', 'virginia beach',
            'oakland', 'minneapolis', 'tulsa', 'tampa', 'arlington', 'new orleans', 'wichita',
            'cleveland', 'bakersfield', 'aurora', 'anaheim', 'honolulu', 'santa ana', 'riverside',
            'corpus christi', 'lexington', 'henderson', 'stockton', 'saint paul', 'st. louis',
            'cincinnati', 'pittsburgh', 'greensboro', 'anchorage', 'plano', 'lincoln', 'orlando',
            'irvine', 'newark', 'toledo', 'durham', 'chula vista', 'fort wayne', 'jersey city',
            'st. petersburg', 'laredo', 'madison', 'chandler', 'buffalo', 'lubbock', 'scottsdale',
            'reno', 'glendale', 'gilbert', 'winston-salem', 'north las vegas', 'norfolk', 'chesapeake',
            'garland', 'irving', 'hialeah', 'fremont', 'boise', 'richmond', 'baton rouge',
            'spokane', 'des moines', 'tacoma', 'san bernardino', 'modesto', 'fontana', 'santa clarita',
            'birmingham', 'oxnard', 'fayetteville', 'moreno valley', 'rochester', 'glendale',
            'huntington beach', 'salt lake city', 'grand rapids', 'amarillo', 'yonkers', 'aurora',
            'montgomery', 'akron', 'little rock', 'huntsville', 'augusta', 'port st. lucie',
            'grand prairie', 'mobile', 'tallahassee', 'cape coral', 'shreveport', 'knoxville',
            'worcester', 'ontario', 'fort lauderdale', 'tempe', 'overland park', 'mckinney',
            'providence', 'newport news', 'brownsville', 'vancouver', 'santa rosa', 'sioux falls',
            'peoria', 'elk grove', 'salem', 'pembroke pines', 'eugene', 'corona', 'cary',
            'springfield', 'fort collins', 'jackson', 'alexandria', 'hayward', 'clarksville',
            'lakewood', 'lancaster', 'salinas', 'palmdale', 'hollywood', 'pasadena', 'sunnyvale',
            'macon', 'pomona', 'escondido', 'killeen', 'naperville', 'joliet', 'bellevue',
            'rockford', 'savannah', 'paterson', 'torrance', 'bridgeport', 'mcallen', 'mesquite',
            'syracuse', 'midland', 'pasadena', 'murfreesboro', 'miramar', 'dayton', 'fullerton',
            'brooklyn', 'manhattan', 'queens', 'bronx', 'staten island',
        ];
    }

    protected function loadNames(): void
    {
        if ($this->namesLoaded) {
            return;
        }

        $namesFile = storage_path('app/phi/common_names.json');
        if (file_exists($namesFile)) {
            $data = json_decode(file_get_contents($namesFile), true);
            $this->commonFirstNames = array_map('strtolower', $data['first_names'] ?? []);
            $this->commonLastNames = array_map('strtolower', $data['last_names'] ?? []);
        }
        $this->namesLoaded = true;
    }

    public function scrub(string $text): array
    {
        $this->resetCounts();
        $this->loadNames();

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
        $pattern = '/\b\d{3}[-\s]?\d{2}[-\s]?\d{4}\b/';
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
        $patterns = [
            '/\bMRN[:\s#]*\d{4,15}\b/i',
            '/\bMedical Record[:\s#]*\d{4,15}\b/i',
            '/\bPatient ID[:\s#]*\d{4,15}\b/i',
            '/\bChart[:\s#]*\d{4,15}\b/i',
            '/\bAcct[:\s#]*\d{4,15}\b/i',
            '/\bCase[:\s#]*\d{4,15}\b/i',
        ];

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
        $patterns = [
            '/\b\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/',
            '/\b\d{3}[-.\s]\d{3}[-.\s]\d{4}\b/',
            '/\+1[-.\s]?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/',
            '/\bphone[:\s]*\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/i',
            '/\bfax[:\s]*\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/i',
            '/\bcell[:\s]*\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/i',
        ];

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
        $pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
        $text = preg_replace_callback($pattern, function ($matches) {
            $this->redactionCounts['email']++;
            return '[EMAIL]';
        }, $text);
        return $text;
    }

    protected function scrubIP(string $text): string
    {
        $pattern = '/\b(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\b/';
        $text = preg_replace_callback($pattern, function ($matches) {
            $this->redactionCounts['ip_address']++;
            return '[IP]';
        }, $text);
        return $text;
    }

    protected function scrubURL(string $text): string
    {
        $pattern = '/\bhttps?:\/\/[^\s]+/i';
        $text = preg_replace_callback($pattern, function ($matches) {
            $this->redactionCounts['url']++;
            return '[URL]';
        }, $text);
        return $text;
    }

    protected function scrubDates(string $text): string
    {
        $patterns = [
            '/\b(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])\/(\d{4})\b/' => 'mdy_full',
            '/\b(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])\/(\d{2})\b/' => 'mdy_short',
            '/\b(\d{4})-(0?[1-9]|1[0-2])-(0?[1-9]|[12]\d|3[01])\b/' => 'iso',
            '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(0?[1-9]|[12]\d|3[01]),?\s+(\d{4})\b/i' => 'full_month',
            '/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?\s+(0?[1-9]|[12]\d|3[01]),?\s+(\d{4})\b/i' => 'abbrev_month',
            '/\bDOB[:\s]*(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])\/(\d{2,4})\b/i' => 'dob',
            '/\bborn\s+(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])\/(\d{2,4})\b/i' => 'born',
            '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}\b/i' => 'month_year',
            '/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\.?\s+\d{4}\b/i' => 'month_year_abbrev',
            '/\b(Q[1-4]|first quarter|second quarter|third quarter|fourth quarter)\s+\d{4}\b/i' => 'quarter_year',
        ];

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
                    $suffix = '';
                    if (preg_match('/(year[-\s]*old|yo|y\.o\.|years[-\s]*old|yr[-\s]*old)/i', $matches[0], $suffixMatch)) {
                        $suffix = ' ' . $suffixMatch[1];
                    }
                    if (stripos($matches[0], 'age') === 0) {
                        return 'age 90+';
                    }
                    return '90+' . $suffix;
                }
                return $matches[0];
            }, $text);
        }
        return $text;
    }

    protected function scrubDeviceIdentifiers(string $text): string
    {
        $patterns = [
            '/\bIMEI[:\s]*\d{15,17}\b/i',
            '/\bSerial[:\s#]*[A-Z0-9]{8,20}\b/i',
            '/\bMAC[:\s]*([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}\b/i',
            '/\bDevice ID[:\s]*[A-Z0-9-]{8,36}\b/i',
        ];

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
        $patterns = [
            '/\bVIN[:\s#]*[A-HJ-NPR-Z0-9]{17}\b/i',
            '/\bLicense Plate[:\s#]*[A-Z0-9]{2,8}\b/i',
            '/\bPlate[:\s#]*[A-Z]{1,3}[-\s]?\d{3,4}[-\s]?[A-Z]{0,3}\b/i',
            '/\bVehicle ID[:\s#]*[A-Z0-9]{8,20}\b/i',
        ];

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
        $patterns = [
            '/\b(fingerprint|retina scan|iris scan|voice print|facial recognition)\s*(id|data|record)?[:\s#]*[A-Z0-9-]{4,30}\b/i',
            '/\bbiometric[:\s]*(id|data|record)?[:\s#]*[A-Z0-9-]{4,30}\b/i',
            '/\bDNA\s*(sample|profile|id)?[:\s#]*[A-Z0-9-]{4,30}\b/i',
        ];

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
        $patterns = [
            '/\bDL[:\s#]*[A-Z]?\d{6,12}\b/i',
            '/\bDriver\'?s?\s*License[:\s#]*[A-Z0-9]{6,15}\b/i',
            '/\bLicense[:\s#]*[A-Z]{1,2}\d{6,10}\b/i',
            '/\bDEA[:\s#]*[A-Z]{2}\d{7}\b/i',
            '/\bNPI[:\s#]*\d{10}\b/i',
        ];

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
        $patterns = [
            '/\bAccount[:\s#]*\d{8,17}\b/i',
            '/\bPolicy[:\s#]*[A-Z0-9]{6,20}\b/i',
            '/\bMember ID[:\s#]*[A-Z0-9]{6,20}\b/i',
            '/\bInsurance ID[:\s#]*[A-Z0-9]{6,20}\b/i',
            '/\bGroup[:\s#]*\d{5,12}\b/i',
        ];

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
        $patterns = [
            '/\b\d{1,5}\s+[A-Za-z]+\s+(Street|St|Avenue|Ave|Boulevard|Blvd|Road|Rd|Drive|Dr|Lane|Ln|Court|Ct|Circle|Cir|Way|Place|Pl)\b\.?/i',
            '/\b(Apt|Suite|Unit|#)\s*\d+[A-Za-z]?\b/i',
            '/\bP\.?O\.?\s*Box\s*\d+\b/i',
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function ($matches) {
                $this->redactionCounts['address']++;
                return '[ADDRESS]';
            }, $text);
        }

        $states = [
            'Alabama', 'Alaska', 'Arizona', 'Arkansas', 'California', 'Colorado', 'Connecticut',
            'Delaware', 'Florida', 'Georgia', 'Hawaii', 'Idaho', 'Illinois', 'Indiana', 'Iowa',
            'Kansas', 'Kentucky', 'Louisiana', 'Maine', 'Maryland', 'Massachusetts', 'Michigan',
            'Minnesota', 'Mississippi', 'Missouri', 'Montana', 'Nebraska', 'Nevada', 'New Hampshire',
            'New Jersey', 'New Mexico', 'New York', 'North Carolina', 'North Dakota', 'Ohio',
            'Oklahoma', 'Oregon', 'Pennsylvania', 'Rhode Island', 'South Carolina', 'South Dakota',
            'Tennessee', 'Texas', 'Utah', 'Vermont', 'Virginia', 'Washington', 'West Virginia',
            'Wisconsin', 'Wyoming'
        ];
        $stateAbbrevs = [
            'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'FL', 'GA', 'HI', 'ID', 'IL', 'IN',
            'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO', 'MT', 'NE', 'NV',
            'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN',
            'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY', 'DC'
        ];

        $statePattern = '/\b(' . implode('|', array_merge($states, $stateAbbrevs)) . '),?\s*\d{5}(-\d{4})?\b/i';
        $text = preg_replace_callback($statePattern, function ($matches) {
            $this->redactionCounts['address']++;
            return '[LOCATION]';
        }, $text);

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

        $countyPatterns = [
            '/\b[A-Z][a-z]+\s+County\b/i',
            '/\bCounty\s+of\s+[A-Z][a-z]+\b/i',
        ];
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
