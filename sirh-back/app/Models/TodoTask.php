<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use App\Models\TodoTaskProof;

/**
 * @property-read \Illuminate\Database\Eloquent\Collection<int, User> $assignees
 */

class TodoTask extends Model
{
    protected $fillable = ['todo_list_id', 'description', 'status', 'start_date', 'end_date', 'assigned_to', 'pourcentage', 'type', 'origine'];

    protected $with = ['comments', 'attachments', 'assignees', 'cancellationRequests', 'proofs'];

    protected $appends = ['source'];
    
    protected static function booted()
    {
        static::created(function ($todoTask) {
            static::auditLog($todoTask, 'created');
            $sync = (bool) config('twilio.sync_on_task_events', false);
            if ($sync) {
                $taskId = $todoTask->id;
                DB::afterCommit(function () use ($taskId) {
                    \App\Jobs\SendTaskAssignedNotifications::dispatchSync($taskId);
                });
            } else {
                \App\Jobs\SendTaskAssignedNotifications::dispatch($todoTask->id)->afterCommit();
            }
        });

        static::updated(function ($todoTask) {
            static::auditLog($todoTask, 'updated');
            // Notifications on update are dispatched by the controller
            // where we can accurately detect new assignees and primary changes.
            if ($todoTask->wasChanged('pourcentage') || $todoTask->wasChanged('status')) {
                $status = strtolower((string)$todoTask->status);
                if ($status === 'terminée' || $status === 'terminee' || (is_numeric($todoTask->pourcentage) && (int)$todoTask->pourcentage >= 100)) {
                    $sync = (bool) config('twilio.sync_on_task_events', false);
                    if ($sync) {
                        $taskId = $todoTask->id;
                        DB::afterCommit(function () use ($taskId) {
                            \App\Jobs\SendTaskCompletedNotifications::dispatchSync($taskId);
                        });
                    } else {
                        \App\Jobs\SendTaskCompletedNotifications::dispatch($todoTask->id)->afterCommit();
                    }
                }
            }
        });

        static::deleted(function ($todoTask) {
            static::auditLog($todoTask, 'deleted');

            $todoTask->assignees()->detach();
            $todoTask->attachments()->each(function ($attachment) {
                $attachment->delete();
            });
            $todoTask->proofs()->each(function ($proof) {
                $proof->delete();
            });
        });
    }
    
    protected static function auditLog($model, $event)
    {
        if (auth()->check()) {
            $user = auth()->user();
            $oldValues = $event !== 'created' ? $model->getOriginal() : null;
            $newValues = $event !== 'deleted' ? $model->getAttributes() : null;
            
            Audit::create([
                'user_id' => $user->id,
                'auditable_type' => get_class($model),
                'auditable_id' => $model->id,
                'event' => $event,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'url' => request()->fullUrl(),
                'ip_address' => request()->ip(),
            ]);
        }
    }

    public function list()
    {
        return $this->belongsTo(TodoList::class, 'todo_list_id');
    }

    public function comments()
    {
        return $this->hasMany(TaskComment::class, 'todo_task_id')->orderBy('created_at', 'desc');
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function assignees()
    {
        return $this->belongsToMany(User::class, 'todo_task_user')->withTimestamps();
    }

    public function attachments()
    {
        return $this->hasMany(TodoTaskAttachment::class, 'todo_task_id')->orderBy('created_at', 'desc');
    }

    public function proofs()
    {
        return $this->hasMany(TodoTaskProof::class, 'todo_task_id')
            ->orderBy('created_at', 'desc')
            ->with(['uploader:id,name,prenom']);
    }

    public function cancellationRequests()
    {
        return $this->hasMany(TodoTaskCancellationRequest::class, 'todo_task_id')
            ->orderBy('created_at', 'desc')
            ->with(['requester:id,name,prenom']);
    }

    public function getSourceAttribute()
    {
        return $this->origine;
    }
}
