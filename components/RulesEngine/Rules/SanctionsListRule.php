<?php

namespace App\Components\RulesEngine\Rules;

use App\Models\FraudRequest;

class SanctionsListRule implements RuleInterface
{
    private array $sanctionedNames;
    private array $sanctionedSins;

    public function __construct()
    {
        // In production, these would be loaded from a database or external service
        $this->sanctionedNames = [
            'JOHN DOE',
            'JANE SMITH',
            'TERRORIST EXAMPLE',
            // Add more sanctioned names here
        ];

        $this->sanctionedSins = [
            '123456789',
            '987654321',
            // Add more sanctioned SINs here
        ];
    }

    public function evaluate(FraudRequest $request): array
    {
        $data = $request->application_data;
        $matches = [];

        // Check name against sanctions list
        if (isset($data['applicant']['first_name']) && isset($data['applicant']['last_name'])) {
            $fullName = strtoupper(trim($data['applicant']['first_name'] . ' ' . $data['applicant']['last_name']));
            
            foreach ($this->sanctionedNames as $sanctionedName) {
                if ($this->fuzzyMatch($fullName, $sanctionedName)) {
                    $matches[] = [
                        'type' => 'name',
                        'value' => $fullName,
                        'matched_against' => $sanctionedName,
                        'confidence' => $this->calculateConfidence($fullName, $sanctionedName),
                    ];
                }
            }
        }

        // Check SIN against sanctions list
        if (isset($data['applicant']['sin'])) {
            $sin = preg_replace('/\D/', '', $data['applicant']['sin']);
            
            if (in_array($sin, $this->sanctionedSins)) {
                $matches[] = [
                    'type' => 'sin',
                    'value' => $sin,
                    'matched_against' => $sin,
                    'confidence' => 100,
                ];
            }
        }

        $triggered = count($matches) > 0;

        return [
            'triggered' => $triggered,
            'reason' => $triggered ? 'Applicant matches sanctions list' : null,
            'details' => [
                'matches' => $matches,
                'match_count' => count($matches),
            ],
        ];
    }

    private function fuzzyMatch(string $name1, string $name2, float $threshold = 0.85): bool
    {
        // Simple fuzzy matching using Levenshtein distance
        $maxLength = max(strlen($name1), strlen($name2));
        if ($maxLength === 0) {
            return true;
        }

        $distance = levenshtein($name1, $name2);
        $similarity = 1 - ($distance / $maxLength);

        return $similarity >= $threshold;
    }

    private function calculateConfidence(string $name1, string $name2): float
    {
        $maxLength = max(strlen($name1), strlen($name2));
        if ($maxLength === 0) {
            return 100;
        }

        $distance = levenshtein($name1, $name2);
        $similarity = 1 - ($distance / $maxLength);

        return round($similarity * 100, 2);
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'Sanctions List Rule',
            'type' => 'hard_fail',
            'description' => 'Checks applicant name and SIN against government sanctions lists',
            'criteria' => [
                'Name fuzzy match against sanctions list (85% confidence)',
                'SIN exact match against sanctions list',
            ],
            'action' => 'Hard fail - reject application immediately',
            'data_sources' => [
                'Government sanctions lists',
                'Terrorist watch lists',
                'Politically exposed persons (PEP) lists',
            ],
        ];
    }
}
