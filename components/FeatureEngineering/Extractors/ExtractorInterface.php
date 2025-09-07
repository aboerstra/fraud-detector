<?php

namespace App\Components\FeatureEngineering\Extractors;

use App\Models\FraudRequest;

interface ExtractorInterface
{
    /**
     * Extract features from a fraud request
     */
    public function extract(FraudRequest $request): array;

    /**
     * Get feature definitions for documentation
     */
    public function getDefinitions(): array;
}
