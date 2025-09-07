<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TrainingJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_id',
        'dataset_id',
        'name',
        'description',
        'config',
        'status',
        'progress',
        'status_message',
        'started_at',
        'completed_at',
        'duration_seconds',
        'metrics',
        'validation_results',
        'feature_importance',
        'model_path',
        'error_message',
        'logs',
        'created_by',
    ];

    protected $casts = [
        'config' => 'array',
        'metrics' => 'array',
        'validation_results' => 'array',
        'feature_importance' => 'array',
        'logs' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'progress' => 'integer',
        'duration_seconds' => 'integer',
    ];

    /**
     * Get the dataset for this training job
     */
    public function dataset(): BelongsTo
    {
        return $this->belongsTo(TrainingDataset::class, 'dataset_id');
    }

    /**
     * Get the model registry entry for this training job
     */
    public function modelRegistry(): HasOne
    {
        return $this->hasOne(ModelRegistry::class, 'training_job_id');
    }

    /**
     * Get the training metrics for this job
     */
    public function trainingMetrics(): HasMany
    {
        return $this->hasMany(TrainingMetric::class, 'training_job_id');
    }

    /**
     * Scope to get running jobs
     */
    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    /**
     * Scope to get completed jobs
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Scope to get failed jobs
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Check if job is running
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Check if job is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if job has failed
     */
    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration_seconds) {
            return 'N/A';
        }

        $hours = floor($this->duration_seconds / 3600);
        $minutes = floor(($this->duration_seconds % 3600) / 60);
        $seconds = $this->duration_seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %dm %ds', $hours, $minutes, $seconds);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $seconds);
        } else {
            return sprintf('%ds', $seconds);
        }
    }

    /**
     * Get the best validation metric
     */
    public function getBestValidationMetricAttribute(): ?float
    {
        if (!$this->validation_results || !isset($this->validation_results['cv_scores'])) {
            return null;
        }

        $scores = $this->validation_results['cv_scores'];
        return is_array($scores) ? max($scores) : null;
    }

    /**
     * Get training configuration summary
     */
    public function getConfigSummaryAttribute(): array
    {
        $config = $this->config ?? [];
        
        return [
            'algorithm' => $config['algorithm'] ?? 'lightgbm',
            'cv_folds' => $config['cv_folds'] ?? 5,
            'test_size' => $config['test_size'] ?? 0.2,
            'random_state' => $config['random_state'] ?? 42,
            'hyperparameters' => $config['hyperparameters'] ?? [],
        ];
    }

    /**
     * Update job progress
     */
    public function updateProgress(int $progress, string $message = null): void
    {
        $this->update([
            'progress' => min(100, max(0, $progress)),
            'status_message' => $message,
        ]);
    }

    /**
     * Mark job as started
     */
    public function markAsStarted(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
            'progress' => 0,
        ]);
    }

    /**
     * Mark job as completed
     */
    public function markAsCompleted(array $metrics = [], string $modelPath = null): void
    {
        $duration = $this->started_at ? now()->diffInSeconds($this->started_at) : null;

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'duration_seconds' => $duration,
            'progress' => 100,
            'metrics' => $metrics,
            'model_path' => $modelPath,
        ]);
    }

    /**
     * Mark job as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $duration = $this->started_at ? now()->diffInSeconds($this->started_at) : null;

        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'duration_seconds' => $duration,
            'error_message' => $errorMessage,
        ]);
    }
}
