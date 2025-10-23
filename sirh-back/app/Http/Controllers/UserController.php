<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;


class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
// UserController.php

public function updatePlayerId(Request $request) {
        \Log::info('User auth:', ['user' => auth()->user()]);

    $request->validate([
        'onesignal_player_id' => 'required|string|max:64',
    ]);
    $user = auth()->user();
    $user->onesignal_player_id = $request->onesignal_player_id;
    $user->save();
    return response()->json(['status' => 'ok']);
}



    public function index() {
        $authUser = auth()->user();
        $societeId = $authUser->societe_id; 

        if ($authUser->hasRole('Employe')) {
            // Employé : Ne voir que lui-même
            $authUser->load('societe');
            $users = [$authUser];

        } elseif ($authUser->hasAnyRole(['Chef_Dep', 'Chef_Projet', 'Chef_Chant'])) {
            // Chef_Dep / Chef_Projet / Chef_Chant : Voir les employés du même département ET de la même société
            $departementId = $authUser->departement_id;

            $users = User::with('societe')
                         ->where('departement_id', $departementId)
                         ->where('societe_id', $societeId)
                         ->get();

        } elseif ($authUser->hasAnyRole(['RH', 'Gest_RH', 'Gest_Projet'])) {
            // RH : Voir tous les employés sans restriction
            $users = User::with('societe')->where('societe_id', $societeId)->get();
        } else {
            return response()->json(['message' => 'Rôle non autorisé'], 403);
        }

        foreach ($users as $user) {
            $user->profile_picture_url = $user->profile_picture_url;
        }

        return response()->json($users);
    }
    
    public function EmployeTemp(){
        $authUser = auth()->user();
        // $societeId = $authUser->societe_id; 
        
        $users = User::with('societe')
        ->where('typeContrat', "Temporaire")
        ->get();
        return response()->json($users);

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request) {
        $rules = [
            'name' => 'nullable|string|max:100',
            'cin' => 'nullable|string|max:20',
            'rib' => 'nullable|string|max:32',
            'situationFamiliale' => 'nullable|in:Célibataire,Marié,Divorcé',
            'sex' => 'nullable|in:H,F',
            'nbEnfants' => 'nullable|integer|min:0',
            'adresse' => 'nullable|string|max:255',
            'fonction' => 'nullable|string|max:55',
            'prenom' => 'nullable|string|max:50',
            'fonction' => 'nullable|string|max:50',
            'tel' => 'nullable|string|max:20',
            'email' => 'nullable|email|unique:users,email',
            'password' => 'nullable|string|min:6',
            'role' => 'nullable|in:Employe,Chef_Dep,RH,Chef_Projet,Chef_Chant,Gest_RH,Gest_Projet',
            'typeContrat' => 'nullable|in:Permanent,Temporaire',
            'date_naissance' => 'nullable|date',
            'dateEmbauche' => 'nullable|date',
            'statut' => 'nullable|in:Actif,Inactif,Congé,Malade',
            'departement_id' => 'nullable|exists:departements,id',
            'societe_id' => 'required_if:typeContrat,Permanent|nullable|exists:societes,id',
            'picture' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'date_sortie' => 'nullable|date',
            'cnss' => 'nullable|string|max:30',
            'solde_conge' => 'nullable|numeric|min:0|max:365',
            'information_supplementaire' => 'nullable|string',
            'information_supplementaire2' => 'nullable|string',
        ];
    
        $data = $request->all();
    
        if (isset($data[0])) {
            foreach ($data as $record) {
                $validator = Validator::make($record, $rules);
    
                if ($validator->fails()) {
                    return response()->json(['error' => $validator->errors()], 422);
                }
    
                $validated = $validator->validated();
    
                // Handle picture as base64 or skip it
                if (!empty($record['picture'])) {
                    $image = $record['picture'];
                    $fileName = time() . '_' . uniqid() . '.jpg';
                    \Storage::disk('public')->put("profile_picture/$fileName", base64_decode($image));
                    $validated['picture'] = $fileName;
                }
    
                $validated['password'] = Hash::make($validated['password']);
                $user = User::create($validated);
    
                if (isset($validated['role'])) {
                    $user->assignRole($validated['role']);
                }
            }
    
            return response()->json(['message' => 'Employés ajoutés']);
        }
    
        // Single user
        $validated = $request->validate($rules);
    
        if ($request->hasFile('picture') && $request->file('picture')->isValid()) {
            $profilePicture = $request->file('picture');
            $fileName = time() . '_' . $profilePicture->getClientOriginalName();
            $profilePicture->storeAs('profile_picture', $fileName, 'public');
            $validated['picture'] = $fileName;
        }
    
        $validated['password'] = Hash::make($validated['password']);
        $user = User::create($validated);
    
        if (isset($validated['role'])) {
            $user->assignRole($validated['role']);
        }
    
        return $user;
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        $user->load('societe');
        $user->profile_picture_url = $user->profile_picture_url;
        return $user;
    }

    public function update(Request $request, $id)
{
    $user = User::findOrFail($id);

    $rules = [
        'cin' => 'sometimes|string|max:20',
        'rib' => 'sometimes|string|max:32',
        'date_naissance' => 'nullable|date',
        'sex' => 'nullable|in:H,F',
        'situationFamiliale' => 'sometimes|in:Célibataire,Marié,Divorcé',
        'nbEnfants' => 'sometimes|integer|min:0',
        'fonction' => 'nullable|string|max:55',
        'adresse' => 'sometimes|string|max:255',
        'name' => 'sometimes|string|max:50',
        'prenom' => 'sometimes|string|max:50',
        'tel' => 'sometimes|string|max:20',
        'email' => 'sometimes|email|unique:users,email,' . $id,
        'password' => 'sometimes|string|min:6',
        'role' => 'sometimes|in:Employe,Chef_Dep,RH,Chef_Projet,Chef_Chant,Gest_RH,Gest_Projet',
        'typeContrat' => 'sometimes|in:Permanent,Temporaire',
        'dateEmbauche' => 'nullable|date',
        'statut' => 'sometimes|in:Actif,Inactif,Congé,Malade',
        'departement_id' => 'sometimes|exists:departements,id',
        'societe_id' => 'sometimes|required_if:typeContrat,Permanent|nullable|exists:societes,id',
        'picture' => 'sometimes|file|image|mimes:jpeg,png,jpg,gif|max:2048',
        'date_sortie' => 'nullable|date',
        'cnss' => 'nullable|string|max:30',
        'solde_conge' => 'nullable|numeric|min:0|max:365',
        'information_supplementaire' => 'nullable|string',
        'information_supplementaire2' => 'nullable|string',
    ];

    $validator = Validator::make($request->all(), $rules);
    if ($validator->fails()) {
        return response()->json(['error' => $validator->errors()], 422);
    }

    $data = $validator->validated();

    // Gérer l'image si elle est envoyée
    if ($request->hasFile('picture')) {
        $file = $request->file('picture');
        $fileName = time() . '_' . $file->getClientOriginalName();
        $file->storeAs('profile_picture', $fileName, 'public');

        // Supprimer l'ancienne image si elle existe
        if ($user->picture && Storage::disk('public')->exists('profile_picture/' . $user->picture)) {
            Storage::disk('public')->delete('profile_picture/' . $user->picture);
        }

        $data['picture'] = $fileName;
    }

    // Hasher le mot de passe si fourni
    if (isset($data['password'])) {
        $data['password'] = Hash::make($data['password']);
    }

    $user->update($data);

    // Gérer le rôle si fourni
    if (isset($data['role'])) {
        $user->syncRoles([$data['role']]);
    }

    return response()->json(['message' => 'Utilisateur mis à jour avec succès', 'user' => $user]);
}

    public function updateSocieteDepartement(Request $request, $id)
    {
        $authUser = auth()->user();

        if (!$authUser->hasAnyRole(['RH','Chef_Dep', 'Chef_Projet', 'Chef_Chant', 'Gest_RH', 'Gest_Projet'])) {
            return response()->json(['message' => 'Non autorisé'], 403);
        }

        $user = User::findOrFail($id);
        $user->departement_id = $authUser->departement_id;
        $user->societe_id = $authUser->societe_id;
        $user->save();

        return response()->json(['message' => 'Employé affecté avec succès']);
    }

public function affecterSocieteDepartement(Request $request)
{
    // Vérification des droits de l'utilisateur authentifié
    $authUser = auth()->user();
    if (!$authUser->hasAnyRole(['RH', 'Chef_Dep', 'Chef_Projet', 'Chef_Chant', 'Gest_RH', 'Gest_Projet'])) {
        return response()->json(['message' => 'Non autorisé'], 403);
    }

    // Validation des données reçues
    $rules = [
        'departement_id' => 'required|exists:departements,id',
        'societe_id' => 'required|exists:societes,id',
        'user_ids' => 'required|array|min:1',
        'user_ids.*' => 'exists:users,id'
    ];
    $validator = Validator::make($request->all(), $rules);
    if ($validator->fails()) {
        return response()->json(['error' => $validator->errors()], 422);
    }

    // Mise à jour en masse
    $userIds = $request->input('user_ids');
    $departementId = $request->input('departement_id');
    $societeId = $request->input('societe_id');

    User::whereIn('id', $userIds)
        ->update([
            'departement_id' => $departementId,
            'societe_id' => $societeId
        ]);

    return response()->json([
        'message' => 'Affectation réussie pour les utilisateurs sélectionnés.'
    ]);
}

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request) {
        $ids = $request->input('ids');
        User::whereIn('id', $ids)->delete();
        return response()->json(['message' => 'Employés supprimés']);
    }
}