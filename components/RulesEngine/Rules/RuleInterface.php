<?php

namespace App\Components\RulesEngine\Rules;

use App\Models\FraudRequest;

interface RuleInterface
{
    /**
     * Evaluate the rule against a fraud request
     */
    public function evaluate(FraudRequest $request): array;

    /**
     * Get rule definition for documentation/debugging
     */
    public function getDefinition(): array;
}
