<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\TodoTask;

// Trouver une tâche avec des assignés
$task = TodoTask::with(['assignees', 'assignedUser'])
    ->whereHas('assignees')
    ->where('status', '!=', 'Terminée')
    ->first();

if (!$task) {
    echo "Aucune tâche trouvée avec des assignés pour le test\n";
    exit;
}

echo "=== AVANT CLÔTURE ===\n";
echo "Tâche #{$task->id}\n";
echo "Statut: {$task->status}\n";
echo "Assigné principal: " . ($task->assigned_to ?: 'Aucun') . "\n";
echo "Assignés secondaires: " . $task->assignees->count() . "\n";
if ($task->assignees->count() > 0) {
    foreach ($task->assignees as $assignee) {
        echo "  - {$assignee->name} (ID: {$assignee->id})\n";
    }
}

echo "\n=== SIMULATION DE CLÔTURE ===\n";
echo "Changement du statut vers 'Terminée'...\n";

// Simuler ce que fait le frontend
$task->status = 'Terminée';
$task->save();

// Recharger la tâche pour voir l'état final
$task->refresh();
$task->load(['assignees', 'assignedUser']);

echo "\n=== APRÈS CLÔTURE ===\n";
echo "Tâche #{$task->id}\n";
echo "Statut: {$task->status}\n";
echo "Assigné principal: " . ($task->assigned_to ?: 'Aucun') . "\n";
echo "Assignés secondaires: " . $task->assignees->count() . "\n";
if ($task->assignees->count() > 0) {
    foreach ($task->assignees as $assignee) {
        echo "  - {$assignee->name} (ID: {$assignee->id})\n";
    }
} else {
    echo "  ❌ PROBLÈME: Aucun assigné secondaire !\n";
}

echo "\nRésultat: " . ($task->assignees->count() > 0 ? "✅ Assignés préservés" : "❌ Assignés supprimés") . "\n";
