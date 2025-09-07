<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TrainingDataset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'version',
        'description',
        'file_path',
        'file_type',
        'file_size',
        'record_count',
        'metadata',
        'quality_metrics',
        'status',
        'error_message',
        'uploaded_by',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'quality_metrics' => 'array',
        'is_active' => 'boolean',
        'file_size' => 'integer',
        'record_count' => 'integer',
    ];

    /**
     * Get the training jobs for this dataset
     */
    public function trainingJobs(): HasMany
    {
        return $this->hasMany(TrainingJob::class, 'dataset_id');
    }

    /**
     * Get the training labels for this dataset
     */
    public function trainingLabels(): HasMany
    {
        return $this->hasMany(TrainingLabel::class, 'dataset_id');
    }

    /**
     * Scope to get only active datasets
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get only ready datasets
     */
    public function scopeReady($query)
    {
        return $query->where('status', 'ready');
    }

    /**
     * Get formatted file size
     */
    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Get data quality score
     */
    public function getQualityScoreAttribute(): float
    {
        if (!$this->quality_metrics) {
            return 0.0;
        }

        $metrics = $this->quality_metrics;
        $score = 0.0;
        $count = 0;

        // Calculate overall quality score from various metrics
        if (isset($metrics['completeness'])) {
            $score += $metrics['completeness'];
            $count++;
        }
        
        if (isset($metrics['validity'])) {
            $score += $metrics['validity'];
            $count++;
        }
        
        if (isset($metrics['consistency'])) {
            $score += $metrics['consistency'];
            $count++;
        }

        return $count > 0 ? $score / $count : 0.0;
    }

    /**
     * Check if dataset is ready for training
     */
    public function isReadyForTraining(): bool
    {
        return $this->status === 'ready' && 
               $this->is_active && 
               $this->record_count > 0 &&
               $this->getQualityScoreAttribute() >= 0.7; // Minimum 70% quality
    }

    /**
     * Get the latest training job for this dataset
     */
    public function getLatestTrainingJobAttribute()
    {
        return $this->trainingJobs()->latest()->first();
    }
}
