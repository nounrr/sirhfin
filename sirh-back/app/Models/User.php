<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use  HasApiTokens, HasRoles, HasFactory, Notifiable;


    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'cin', 'rib', 'situationFamiliale', 'nbEnfants', 'adresse','name',"password", 'prenom', 'date_naissance', 'tel', 'email', 'role', 'onesignal_player_id',   'statut', 'typeContrat', 'departement_id', 'picture', 'societe_id','sex','dateEmbauche','fonction', 'date_sortie','cnss','solde_conge','salaire',   'information_supplementaire',
    'information_supplementaire2',
    ];



    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];
    public function getProfilePictureUrlAttribute()
    {
        if ($this->profile_picture) {
            return Storage::url('profile_picture/' . $this->profile_picture);
        }
        return null;
    }
    
    public function departement() {
        return $this->belongsTo(Departement::class);
    }
    public function pointages() {
        return $this->hasMany(Pointage::class);
    }

    public function absenceRequests() {
        return $this->hasMany(AbsenceRequest::class);
    }

    public function societe() {
        return $this->belongsTo(Societe::class);
    }

    public function todoListsAssigned()
{
    return $this->hasMany(TodoList::class, 'assigned_to');
}

public function todoListsCreated()
{
    return $this->hasMany(TodoList::class, 'created_by');
}
    public function todoTasks()
    {
        return $this->hasMany(TodoTask::class, 'assigned_to');
    }


   
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }


    public function userTypeDocs()
    {
        return $this->hasMany(UserTypeDoc::class);
    }

    public function typeDocs()
    {
        return $this->belongsToMany(TypeDoc::class, 'user_type_docs')
                    ->withPivot('is_provided', 'file_path')
                    ->withTimestamps();
    }

    public function salaires()
    {
        return $this->hasMany(Salaire::class);
    }

    public function salaireActuel()
    {
        return $this->hasOne(Salaire::class)->latest();
    }
}




