<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AbsenceRequest extends Model
{
    protected $fillable = ['user_id', 'type', 'dateDebut', 'dateFin', 'motif', 'statut', 'justification'];

    public function user() {
        return $this->belongsTo(User::class);
    }
}
