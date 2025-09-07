<?php

namespace App\Components\RulesEngine\Rules;

use App\Models\FraudRequest;
use Illuminate\Support\Facades\DB;

class DuplicateApplicationRule implements RuleInterface
{
    public function evaluate(FraudRequest $request): array
    {
        $data = $request->application_data;
        
        // Check for duplicate applications within 24 hours
        $duplicateCount = DB::table('fraud_requests')
            ->where('created_at', '>=', now()->subHours(24))
            ->where('id', '!=', $request->id)
            ->where(function ($query) use ($data) {
                // Check by SIN
                if (isset($data['applicant']['sin'])) {
                    $query->orWhereJsonContains('application_data->applicant->sin', $data['applicant']['sin']);
                }
                
                // Check by email
                if (isset($data['applicant']['email'])) {
                    $query->orWhereJsonContains('application_data->applicant->email', $data['applicant']['email']);
                }
                
                // Check by phone
                if (isset($data['applicant']['phone'])) {
                    $query->orWhereJsonContains('application_data->applicant->phone', $data['applicant']['phone']);
                }
                
                // Check by address combination
                if (isset($data['applicant']['address'])) {
                    $address = $data['applicant']['address'];
                    if (isset($address['street']) && isset($address['postal_code'])) {
                        $query->orWhere(function ($subQuery) use ($address) {
                            $subQuery->whereJsonContains('application_data->applicant->address->street', $address['street'])
                                    ->whereJsonContains('application_data->applicant->address->postal_code', $address['postal_code']);
                        });
                    }
                }
            })
            ->count();

        $triggered = $duplicateCount > 0;

        return [
            'triggered' => $triggered,
            'reason' => $triggered ? 'Duplicate application detected within 24 hours' : null,
            'details' => [
                'duplicate_count' => $duplicateCount,
                'time_window_hours' => 24,
            ],
        ];
    }

    public function getDefinition(): array
    {
        return [
            'name' => 'Duplicate Application Rule',
            'type' => 'hard_fail',
            'description' => 'Detects duplicate applications within 24 hours based on SIN, email, phone, or address',
            'criteria' => [
                'Same SIN within 24 hours',
                'Same email within 24 hours',
                'Same phone within 24 hours',
                'Same street address and postal code within 24 hours',
            ],
            'action' => 'Hard fail - reject application immediately',
        ];
    }
}
