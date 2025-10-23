<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Schema;

use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Models\JourFerie;
use App\Services\TimeCalculationService;
use App\Services\PresenceSheetService;
use DateTime;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalaryExportController extends Controller
{
    // Cache des statistiques des temporaires (heures totales, heures supp, jours travaillés)
    private array $temporaryStats = [];
    
    // Fonction d'arrondi personnalisée : si décimale >= 0.5 arrondir vers le haut, sinon supprimer la décimale
    
 private function formatRange($sheet, string $range, int $decimals = 0): void
{
   $nbsp = "\xC2\xA0"; // espace insécable
    $fmt = $decimals > 0
        ? "[$-fr-FR] #{$nbsp}##0." . str_repeat('0', $decimals)
        : "[$-fr-FR] #{$nbsp}##0";
    $sheet->getStyle($range)->getNumberFormat()->setFormatCode($fmt);
}
    // Fonction pour formater les nombres avec séparateur de milliers
    

    // Fonction pour déterminer si un utilisateur est permanent
    private function isPermanent($user)
    {
        $typeContrat = strtolower(trim($user->typeContrat ?? ''));
        return in_array($typeContrat, ['permanent', 'permanente', 'cdi', 'indéterminée', 'indeterminee']) ||
               str_contains($typeContrat, 'permanent') ||
               str_contains($typeContrat, 'cdi');
    }

    public function export(Request $request)
    {
        // Étendre limites pour gros exports
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        
        $exportType = $this->determineExportType($request);
        $dateRange  = $this->calculateDateRange($request, $exportType);
        if (!$dateRange) {
            return response()->json(['error' => 'Paramètres de date requis'], 400);
        }

        $userAuth = Auth::user();
        
        // Vérification des rôles pour l'accès aux feuilles salaires
        $userRoleRaw = (string)($userAuth->role ?? '');
        $userRole = strtolower(trim($userRoleRaw));
        $spatieRoles = [];
        if (method_exists($userAuth, 'getRoleNames')) {
            try {
                $spatieRoles = $userAuth->getRoleNames()->map(fn($r)=>strtolower(trim($r)))->toArray();
            } catch (\Throwable $e) {
                Log::warning('Impossible de récupérer les rôles Spatie', ['err'=>$e->getMessage()]);
            }
        }
        
        // Vérifier l'accès aux feuilles salaires
        $exclusionList = ['gest_rh','rh_manager'];
        $shouldExclude = in_array($userRole, $exclusionList, true) || count(array_intersect($exclusionList, $spatieRoles))>0;
        
        if ($shouldExclude) {
            return response()->json(['error' => 'Accès non autorisé aux données de salaires'], 403);
        }

        $spreadsheet = new Spreadsheet();

        /* ===================== FEUILLES PRÉSENCE (même logique que Monthly) ===================== */
        $presenceController = new MonthlyPresenceExportController();
        $presenceCollections = $presenceController->getPresenceUserCollections($userAuth->societe_id);
        $presenceCallbacks = $presenceController->getPresenceCallbacks();

        $presenceService = new PresenceSheetService();
        $presenceService->createPermanentSheet($spreadsheet, $dateRange, $presenceCollections['permanent'], $presenceCallbacks);
        $presenceService->createTemporarySheet($spreadsheet, $dateRange, $presenceCollections['temporary'], $presenceCallbacks);

        /* ===================== FEUILLE SALAIRE PERMANENTS ===================== */
        $this->createSalairePermanentSheet($spreadsheet, $userAuth->societe_id, $dateRange);

        /* ===================== FEUILLE SALAIRE TEMPORAIRES ===================== */
        $this->createSalaireTemporaireSheet($spreadsheet, $userAuth->societe_id, $dateRange);

        /* ===================== FEUILLE RECAP CHARGE PERSONNEL ===================== */
        $this->createRecapChargePersonnelSheet($spreadsheet, $userAuth->societe_id, $dateRange);

        /* ===================== FEUILLE RECAP DÉPARTEMENTS ===================== */
        $this->createRecapDepartementsSheet($spreadsheet, $userAuth->societe_id, $dateRange);

        return $this->exportExcel($spreadsheet, $dateRange, 'Salaires');
    }

    /* ----------------------- Helpers: dates & type ----------------------- */
    private function determineExportType(Request $request)
    {
        if ($request->has('month')) return 'month';
        if ($request->has('specificDate')) return 'day';
        if ($request->has('startDate') && $request->has('endDate')) return 'period';
        if ($request->has('exportAll')) return 'all';
        return null;
    }

    private function calculateDateRange(Request $request, $exportType)
    {
        switch ($exportType) {
            case 'month':
                $month = $request->input('month');
                if (!$month) return null;
                $startDate = new DateTime("$month-01");
                $endDate   = (clone $startDate)->modify('last day of this month');
                $currentMonth = date('Y-m');
                $currentDay   = new DateTime();
                if ($month === $currentMonth) {
                    $endDate   = $currentDay;
                    $totalDays = $startDate->diff($endDate)->days + 1;
                } else {
                    $totalDays = $startDate->format('t');
                }
                return [
                    'type' => 'month',
                    'startDate' => $startDate,
                    'endDate' => $endDate,
                    'totalDays' => $totalDays,
                    'label' => $startDate->format('Y-m'),
                    'is_current_month' => ($month === $currentMonth)
                ];
            case 'day':
                $specificDate = $request->input('specificDate');
                if (!$specificDate) return null;
                $date = new DateTime($specificDate);
                return [
                    'type' => 'day',
                    'startDate' => $date,
                    'endDate'   => $date,
                    'totalDays' => 1,
                    'label' => $date->format('Y-m-d'),
                    'is_current_month' => false
                ];
            case 'period':
                $startDate = $request->input('startDate');
                $endDate   = $request->input('endDate');
                if (!$startDate || !$endDate) return null;
                $start      = new DateTime($startDate);
                $end        = new DateTime($endDate);
                $currentDay = new DateTime();
                if ($end >= $currentDay && $start <= $currentDay) $end = $currentDay;
                $totalDays = $start->diff($end)->days + 1;
                return [
                    'type' => 'period',
                    'startDate' => $start,
                    'endDate'   => $end,
                    'totalDays' => $totalDays,
                    'label' => $start->format('Y-m-d') . '_to_' . $end->format('Y-m-d'),
                    'is_current_month' => false
                ];
            case 'all':
                $start = new DateTime('2020-01-01');
                $end   = new DateTime();
                return [
                    'type' => 'all',
                    'startDate' => $start,
                    'endDate'   => $end,
                    'totalDays' => $start->diff($end)->days + 1,
                    'label'     => 'tous_pointages',
                    'is_current_month' => false
                ];
            default:
                return null;
        }
    }

    private function createSalairePermanentSheet($spreadsheet, $societeId, $dateRange)
    {
        $sheet = new Worksheet($spreadsheet, 'Salaire Permanent');
        $spreadsheet->addSheet($sheet);
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

        // Récupérer tous les employés permanents (y compris inactifs avec pointages)
        $allEmployes = DB::table('users')
            ->leftJoin('departements', 'users.departement_id', '=', 'departements.id')
            ->leftJoin('salaires', 'users.id', '=', 'salaires.user_id')
            ->where('users.societe_id', $societeId)
            ->where(function($query) {
                $query->whereIn(DB::raw('LOWER(TRIM(COALESCE(users.typeContrat, "")))'), 
                    ['permanent', 'permanente', 'cdi', 'indéterminée', 'indeterminee'])
                      ->orWhere('users.typeContrat', 'LIKE', '%permanent%')
                      ->orWhere('users.typeContrat', 'LIKE', '%CDI%');
            })
            ->select(
                'users.id', 'users.name', 'users.prenom', 'users.fonction','users.statut',
                'salaires.salaire_base', 'salaires.salaire_net',
                'departements.nom as departement_nom'
            )
            ->orderBy('users.name')
            ->get();

        // Filtrer les employés : actifs + inactifs avec pointages dans la période
        $employes = $allEmployes->filter(function($user) use ($dateRange) {
            $statutGlobal = strtolower(trim((string)($user->statut ?? '')));
            if ($statutGlobal === 'inactif') {
                return $this->hasPointagesInPeriod($user->id, $dateRange);
            }
            return true; // Utilisateurs actifs
        });

        // Titre principal
        $row = 1;
        $sheet->setCellValue('A' . $row, 'SALAIRES EMPLOYÉS PERMANENTS');
        $sheet->mergeCells('A' . $row . ':Q' . $row);
        $sheet->getStyle('A' . $row . ':Q' . $row)->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EAD3']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THICK]]
        ]);
        $row += 2;

        // En-têtes principales (ligne 3)
        $sheet->setCellValue('A3', 'MATRICULES');
        $sheet->setCellValue('B3', 'NOMS ET PRÉNOMS');
        $sheet->setCellValue('C3', 'FONCTION');
        $sheet->setCellValue('D3', 'DÉPARTEMENT');
        $sheet->setCellValue('E3', 'TOTAL JOURS TRAVAILLÉS');
        $sheet->setCellValue('F3', 'CONGÉ');
        
        // Fusionner la cellule "Salaires" (G3:J3)
        $sheet->mergeCells('G3:J3');
        $sheet->setCellValue('G3', 'SALAIRES');
        
        // Fusionner "Prime d'ancienneté" (K3)
        $sheet->setCellValue('K3', "PRIME D'ANCIENNETÉ");
        
        // Fusionner "Primes et indemnités" (L3:O3)
        $sheet->mergeCells('L3:O3');
        $sheet->setCellValue('L3', 'PRIMES ET INDEMNITÉS');
        
        // Fusionner "CNSS Parts patronales" (P3:Q3)
        $sheet->mergeCells('P3:Q3');
        $sheet->setCellValue('P3', 'CNSS PARTS PATRONALES');
        
        $sheet->setCellValue('R3', 'COÛT TOTAL PAR SALARIÉS');

        // Sous-en-têtes (ligne 4)
        $sheet->setCellValue('A4', ''); // Matricules
        $sheet->setCellValue('B4', ''); // Noms
        $sheet->setCellValue('C4', ''); // Fonction
        $sheet->setCellValue('D4', ''); // Département
        $sheet->setCellValue('E4', ''); // Total Jours
        $sheet->setCellValue('F4', ''); // Congé
        
        // Sous-cellules pour "Salaires"
        $sheet->setCellValue('G4', 'SALAIRE NET 26J');
        $sheet->setCellValue('H4', 'SALAIRE DE BASE');
        $sheet->setCellValue('I4', 'JOURNALIERS');
        $sheet->setCellValue('J4', 'MENSUELS');
        
        $sheet->setCellValue('K4', ''); // Prime ancienneté
        
        // Sous-cellules pour "Primes et indemnités"
        $sheet->setCellValue('L4', 'PANIER');
        $sheet->setCellValue('M4', 'REPRÉSENT.');
        $sheet->setCellValue('N4', 'TRANSP.');
        $sheet->setCellValue('O4', 'DÉPLACEMENT');
        
        // Sous-cellules pour "CNSS Parts patronales"
        $sheet->setCellValue('P4', '8.98%');
        $sheet->setCellValue('Q4', '12.11%');
        
        $sheet->setCellValue('R4', ''); // Coût total

        // Fusionner les cellules qui n'ont pas de sous-cellules (de ligne 3 à 4)
        $sheet->mergeCells('A3:A4');
        $sheet->mergeCells('B3:B4');
        $sheet->mergeCells('C3:C4');
        $sheet->mergeCells('D3:D4');
        $sheet->mergeCells('E3:E4');
        $sheet->mergeCells('F3:F4');
        $sheet->mergeCells('K3:K4');
        $sheet->mergeCells('R3:R4');

        // Style des en-têtes
        $sheet->getStyle('A3:R4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4FD']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // Ajouter AutoFilter pour les en-têtes
        $sheet->setAutoFilter('A4:R4');

        $row = 5;

        // Ajouter les données des employés
        foreach ($employes as $emp) {
            // Calculer les jours travaillés et congés pour cet employé
            $statsEmploye = $this->calculateEmployeeStats($emp->id, $dateRange);
            
            $data = [
                $emp->id, // Matricule
                strtoupper(trim(($emp->name ?? '') . ' ' . ($emp->prenom ?? ''))), // Noms et prénoms
                $emp->fonction ?? '', // Fonction
                $emp->departement_nom ?? 'Non défini', // Département
                $statsEmploye['jours_travailles'], // Total Jours Travaillés
                $statsEmploye['conges'], // Congé
                ($emp->salaire_net ?? 0), // Salaire net 26j (valeur brute)
                ($emp->salaire_base ?? 0), // Salaire de base (valeur brute)
                '', // Journaliers (colonne vide)
                '', // Mensuels (colonne vide)
                '', // Prime d'ancienneté (colonne vide)
                '', // Panier (colonne vide)
                '', // Représentation (colonne vide)
                '', // Transport (colonne vide)
                '', // Déplacement (colonne vide)
                '', // CNSS 8.98% (colonne vide)
                '', // CNSS 12.11% (colonne vide)
                ''  // Coût total (colonne vide)
            ];
            
            $sheet->fromArray($data, null, 'A' . $row);
            
            // Style alterné pour les lignes
            $fillColor = ($row % 2 === 0) ? 'F9F9F9' : 'FFFFFF';
            $sheet->getStyle('A' . $row . ':R' . $row)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillColor]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
            ]);
            
            $row++;
        }

        // Appliquer format d'affichage (display-only)
        $firstDataRow = 5;
        $lastDataRow  = $row - 1;
        if ($lastDataRow >= $firstDataRow) {
            // E (jours) et F (congé) en entier
            $this->formatRange($sheet, 'E' . $firstDataRow . ':F' . $lastDataRow, 0);
            // G (salaire net) et H (salaire base) en entier avec séparateur
            $this->formatRange($sheet, 'G' . $firstDataRow . ':H' . $lastDataRow, 0);
            // R (coût total) si rempli plus tard, garder format entier
            $this->formatRange($sheet, 'R' . $firstDataRow . ':R' . $lastDataRow, 0);
        }

        // Auto-ajuster les colonnes
        foreach (range('A', 'R') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $sheet->freezePane('A5');
    }

    private function createSalaireTemporaireSheet($spreadsheet, $societeId, $dateRange)
    {
        $sheet = new Worksheet($spreadsheet, 'Salaire Temporaire');
        $spreadsheet->addSheet($sheet);
        $spreadsheet->setActiveSheetIndex($spreadsheet->getIndex($sheet));

        // Récupérer tous les temporaires (y compris inactifs) ayant au moins un pointage PRESENT ou RETARD dans la période
        $allTemporaires = DB::table('users')
            ->where('users.societe_id', $societeId)
            ->get()
            ->filter(function($u){ return !$this->isPermanent($u); });

        // Filtrer les temporaires : actifs + inactifs avec pointages dans la période
        $rawTemporaires = $allTemporaires->filter(function($user) use ($dateRange) {
            $statutGlobal = strtolower(trim((string)($user->statut ?? '')));
            if ($statutGlobal === 'inactif') {
                return $this->hasPointagesInPeriod($user->id, $dateRange);
            }
            return true; // Utilisateurs actifs
        });

        $ids = $rawTemporaires->pluck('id')->all();
        $employes = collect();
        if (!empty($ids)) {
            $pointages = DB::table('pointages')
    ->select('user_id')
    ->whereIn('user_id', $ids)
    ->whereDate('date', '>=', $dateRange['startDate']->format('Y-m-d'))
    ->whereDate('date', '<=', $dateRange['endDate']->format('Y-m-d'))
    ->whereIn('statutJour', ['present','retard'])
    ->groupBy('user_id')
    ->pluck('user_id')
    ->toArray();


            if (!empty($pointages)) {
                $employes = DB::table('users')
                            ->leftJoin('departements', 'users.departement_id', '=', 'departements.id')
                            ->leftJoin('salaires', 'users.id', '=', 'salaires.user_id')
                    ->whereIn('users.id', $pointages)
                    ->select(
                        'users.*',
                                'salaires.salaire_base',
                                DB::raw('COALESCE(salaires.panier, salaires.panier, 0) as panier'),
                                'departements.nom as departement_nom'
                    )
                    ->orderBy('users.name')
                    ->get();
            }
        }

        // Titre principal
        $row = 1;
        $sheet->setCellValue('A' . $row, 'SALAIRES EMPLOYÉS TEMPORAIRES');
        $sheet->mergeCells('A' . $row . ':K' . $row);
        $sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EAD3']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THICK]]
        ]);
        $row += 2;

        // Ligne 3 (grandes cellules)
        // A: Nom & Prénom (fusion A3:A4), B: Fonction (fusion B3:B4)
        $sheet->setCellValue('A3', 'NOM ET PRÉNOM');
        $sheet->mergeCells('A3:A4');
        $sheet->setCellValue('B3', 'FONCTION');
        $sheet->mergeCells('B3:B4');

    // C: DEPARTEMENT (fusion C3:C4)
    $sheet->setCellValue('C3', 'DÉPARTEMENT');
    $sheet->mergeCells('C3:C4');

    // D-G: DÉTAIL DES HEURES -> Sous: Heures Normales / HS 25 / HS 50 / Total Heures
    $sheet->mergeCells('D3:G3');
    $sheet->setCellValue('D3', 'DÉTAIL DES HEURES');
    $sheet->setCellValue('D4', 'HEURES NORMALES');
    $sheet->setCellValue('E4', 'HS 25');
    $sheet->setCellValue('F4', 'HS 50');
    $sheet->setCellValue('G4', 'TOTAL HEURES');

    // H: Total Jours Travaillés (fusion H3:H4)
    $sheet->setCellValue('H3', 'TOTAL JOURS TRAVAILLÉS');
    $sheet->mergeCells('H3:H4');

    // I-J: COÛT -> Sous: Taux H / Prime de panier
    $sheet->mergeCells('I3:J3');
    $sheet->setCellValue('I3', 'COÛT');
    $sheet->setCellValue('I4', 'TAUX H');
    $sheet->setCellValue('J4', 'PRIME DE PANIER');

    // K: Salaire net (fusion K3:K4)
    $sheet->setCellValue('K3', 'SALAIRE NET');
    $sheet->mergeCells('K3:K4');

        // Style en-têtes
    $sheet->getStyle('A3:K4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E1F5FE']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
    $sheet->setAutoFilter('A4:K4');

        $row = 5;

        // Pour récupérer les valeurs détaillées des heures, on va calculer les nouvelles métriques
        foreach ($employes as $emp) {
            $detailedStats = \App\Services\TimeCalculationService::computeDetailedTemporaryStats($emp, $dateRange);

            // Taux horaire = salaire_base (si présent) sinon 0
            $tauxH = $emp->salaire_base ?? 0;
            // Prime de panier récupérée depuis la table salaires (colonne panie ou panier)
            $primePanier = $emp->panier ?? 0;

            // Coût total = (HeuresNormales * TauxH) + (HS25 * TauxH * 1.25) + (HS50 * TauxH * 1.5) + (PrimePanier * TotalJours)
            $coutTotal = ($detailedStats['heures_normales'] * $tauxH)
                + ($detailedStats['hs_25'] * $tauxH * 1.25)
                + ($detailedStats['hs_50'] * $tauxH * 1.5)
                + ($primePanier * $detailedStats['jours_travailles']);

            // Set row cells individually so we can put a formula for Salaire Net (col J)
            $sheet->setCellValue('A' . $row, strtoupper(trim(($emp->name ?? '') . ' ' . ($emp->prenom ?? ''))));
            $sheet->setCellValue('B' . $row, strtoupper($emp->fonction ?? ''));
            $sheet->setCellValue('C' . $row, strtoupper($emp->departement_nom ?? ''));
            $sheet->setCellValue('D' . $row, $detailedStats['heures_normales']);
            $sheet->setCellValue('E' . $row, $detailedStats['hs_25']);
            $sheet->setCellValue('F' . $row, $detailedStats['hs_50']);
            $sheet->setCellValue('G' . $row, $detailedStats['total_heures']);
            $sheet->setCellValue('H' . $row, $detailedStats['jours_travailles']);
            $sheet->setCellValue('I' . $row, $tauxH);
            $sheet->setCellValue('J' . $row, $primePanier);

            // Salaire net formula: =D{row}*I{row} + E{row}*I{row}*1.25 + F{row}*I{row}*1.5 + H{row}*J{row}
            $formula = sprintf('=D%1$d*I%1$d + E%1$d*I%1$d*1.25 + F%1$d*I%1$d*1.5 + H%1$d*J%1$d', $row);
            $sheet->setCellValue('K' . $row, $formula);
            $fillColor = ($row % 2 === 0) ? 'F9F9F9' : 'FFFFFF';
            $sheet->getStyle('A' . $row . ':J' . $row)->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fillColor]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
            ]);
            $row++;
        }

        // Appliquer format d'affichage (display-only) pour la feuille temporaires
        $firstDataRow = 5;
        $lastDataRow  = $row - 1;
        if ($lastDataRow >= $firstDataRow) {
            // Heures D..G en affichage entier (arrondi visuel)
            $this->formatRange($sheet, 'D' . $firstDataRow . ':G' . $lastDataRow, 0);
            // Jours H entier
            $this->formatRange($sheet, 'H' . $firstDataRow . ':H' . $lastDataRow, 0);
            // Taux H, Panier et Salaire net (I..K) entier pour affichage
            $this->formatRange($sheet, 'I' . $firstDataRow . ':K' . $lastDataRow, 0);
        }

        foreach (range('A','J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->freezePane('A5');
    }

    

    // computeDailyRawHours and checkNightOverlap are provided by TimeCalculationService

    private function computeTemporarySalaryStats($user, $dateRange)
    {
        if (isset($this->temporaryStats[$user->id])) {
            return $this->temporaryStats[$user->id];
        }

        // Utiliser MonthlyPresenceExportController pour obtenir les données calculées
        $monthlyController = new \App\Http\Controllers\MonthlyPresenceExportController();
        $monthlyStats = $this->getDataFromMonthlyExport($user, $dateRange, false); // false = temporaire
        
        if ($monthlyStats) {
            $result = [
                'total_heures' => $monthlyStats['total_heures'],
                'heures_supp' => $monthlyStats['heures_supp'],
                'jours_travailles' => $monthlyStats['jours_travailles']
            ];
        } else {
            // Fallback: calcul direct si pas trouvé
            $result = $this->computeTemporarySalaryStatsDirect($user, $dateRange);
        }
        
        $this->temporaryStats[$user->id] = $result;
        return $result;
    }

    private function getDataFromMonthlyExport($user, $dateRange, $isPermanent)
    {
        // Simuler la logique de MonthlyPresenceExportController pour récupérer les données
        $joursFeries = JourFerie::whereBetween('date', [
            $dateRange['startDate']->format('Y-m-d'),
            $dateRange['endDate']->format('Y-m-d')
        ])->where('actif', true)->pluck('date')->map(fn($d)=>date('Y-m-d',strtotime($d)))->toArray();

        if ($isPermanent) {
            // Logique permanents du MonthlyPresenceExportController
            $totalHeures = 0.0;
            $heuresSupp = 0.0;
            $joursTravailles = 0;

            $currentDate = clone $dateRange['startDate'];
            while ($currentDate <= $dateRange['endDate']) {
                $dateStr = $currentDate->format('Y-m-d');
                $dayOfWeek = $currentDate->format('w');
                $isHoliday = in_array($dateStr, $joursFeries);

                $pointages = DB::table('pointages')
                    ->where('user_id', $user->id)
                    ->whereDate('date', $dateStr)
                    ->get();

                $conge = DB::table('absence_requests')
                    ->where('user_id', $user->id)
                    ->whereIn('type', ['Congé', 'maladie'])
                    ->where('statut', 'approuvé')
                    ->whereDate('dateDebut', '<=', $dateStr)
                    ->whereDate('dateFin', '>=', $dateStr)
                    ->first();

                $hasPresent = false;
                foreach ($pointages as $pt) {
                    if (in_array($pt->statutJour, ['present','retard'])) $hasPresent = true;
                }

                // temporaires: appliquer règle par shift
                $totalDailyHours = TimeCalculationService::computeDailyTotalHoursForTemporary($pointages);
                $nightBaseHours = $this->calculateNightBaseHours($pointages);

                if ($conge) {
                    // Jour de congé
                } elseif ($isHoliday) {
                    if ($hasPresent) {
                        $totalHeures += $totalDailyHours;
                        // Permanents: HS si > 8h (au lieu de > 9h) pour jours fériés
                        if ($totalDailyHours > 8) $heuresSupp += ($totalDailyHours - 8);
                        $joursTravailles += 1;
                    }
                } elseif (count($pointages) === 0 || !$hasPresent) {
                    // Absence
                } else {
                    if ($dayOfWeek != 0) $joursTravailles += 1;
                    // Permanents: HS si > 8h (au lieu de > 9h) pour jours normaux
                    if ($totalDailyHours > 8 && $nightBaseHours < 8) {
                        $heuresSupp += ($totalDailyHours - 8);
                    }
                    $totalHeures += $totalDailyHours;
                }

                $currentDate->modify('+1 day');
            }

            return [
                'total_heures' => $totalHeures,
                'heures_supp' => $heuresSupp,
                'jours_travailles' => $joursTravailles
            ];
        } else {
            // Logique temporaires du MonthlyPresenceExportController
            $totalHeures = 0.0;
            $heuresSupp = 0.0;
            $joursTravailles = 0;

            $currentDate = clone $dateRange['startDate'];
            while ($currentDate <= $dateRange['endDate']) {
                $dateStr = $currentDate->format('Y-m-d');
                $dayOfWeek = $currentDate->format('w');
                $isHoliday = in_array($dateStr, $joursFeries);

                $pointages = DB::table('pointages')
                    ->where('user_id', $user->id)
                    ->whereDate('date', $dateStr)
                    ->get();

                $conge = DB::table('absence_requests')
                    ->where('user_id', $user->id)
                    ->whereIn('type', ['Congé', 'maladie'])
                    ->where('statut', 'approuvé')
                    ->whereDate('dateDebut', '<=', $dateStr)
                    ->whereDate('dateFin', '>=', $dateStr)
                    ->first();

                if ($conge) {
                    // Jour de congé
                } else {
                    $daily = TimeCalculationService::computeDailyTotalHoursForTemporary($pointages);
                    if ($daily > 0) {
                        $joursTravailles++;
                        $totalHeures += $daily;
                        if ($dayOfWeek == 0 || $isHoliday) {
                            $heuresSupp += $daily; // tout le jour
                        } elseif ($daily > 9) {
                            $heuresSupp += ($daily - 9);
                        }
                    }
                }
                $currentDate->modify('+1 day');
            }

            return [
                'total_heures' => $totalHeures,
                'heures_supp' => $heuresSupp,
                'jours_travailles' => $joursTravailles
            ];
        }
    }

    private function computeTemporarySalaryStatsDirect($user, $dateRange)
    {
        // Ancien calcul direct en fallback
        $totalHeures = 0.0;
        $heuresSupp = 0.0;
        $joursTravailles = 0;

        $joursFeries = JourFerie::whereBetween('date', [
            $dateRange['startDate']->format('Y-m-d'),
            $dateRange['endDate']->format('Y-m-d')
        ])->where('actif', true)->pluck('date')->map(fn($d)=>date('Y-m-d',strtotime($d)))->toArray();

        $currentDate = clone $dateRange['startDate'];
        while ($currentDate <= $dateRange['endDate']) {
            $dateStr = $currentDate->format('Y-m-d');
            $dayOfWeek = (int)$currentDate->format('w');
            $isHoliday = in_array($dateStr, $joursFeries, true);

            $conge = DB::table('absence_requests')
                ->where('user_id', $user->id)
                ->whereIn('type', ['Congé', 'maladie'])
                ->where('statut', 'approuvé')
                ->whereDate('dateDebut', '<=', $dateStr)
                ->whereDate('dateFin', '>=', $dateStr)
                ->first();

            if ($conge) {
                $currentDate->modify('+1 day');
                continue;
            }

            $pointages = DB::table('pointages')
                ->where('user_id', $user->id)
                ->whereDate('date', $dateStr)
                ->get();

            $daily = TimeCalculationService::computeDailyTotalHoursForTemporary($pointages);
            if ($daily > 0) {
                $joursTravailles++;
                $totalHeures += $daily;
                if ($dayOfWeek === 0 || $isHoliday) {
                    $heuresSupp += $daily;
                } elseif ($daily > 9) {
                    $heuresSupp += ($daily - 9);
                }
            }
            $currentDate->modify('+1 day');
        }

        return [
            'total_heures' => $totalHeures,
            'heures_supp' => $heuresSupp,
            'jours_travailles' => $joursTravailles
        ];
    }

    // ==== LOGIQUE ALIGNÉE AVEC MonthlyPresenceExportController (calcul heures) ====
    private function parseTime($timeString)
    {
        if (empty($timeString)) return false;
        $timeString = trim($timeString);

        if (preg_match('/^(\d{1,2}):(\d{2}):(\d{2})$/', $timeString, $m)) {
            $h=intval($m[1]); $i=intval($m[2]); $s=intval($m[3]);
            if ($h>=0&&$h<=23 && $i>=0&&$i<=59 && $s>=0&&$s<=59) return mktime($h,$i,$s);
        }
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $timeString, $m)) {
            $h=intval($m[1]); $i=intval($m[2]);
            if ($h>=0&&$h<=23 && $i>=0&&$i<=59) return mktime($h,$i,0);
        }
        if (preg_match('/^(\d):(\d{2})$/', $timeString, $m)) {
            $h=intval($m[1]); $i=intval($m[2]);
            if ($h>=0&&$h<=9 && $i>=0&&$i<=59) return mktime($h,$i,0);
        }

        $ts = strtotime($timeString);
        if ($ts !== false) {
            $h = date('H', $ts);
            $i = date('i', $ts);
            $s = date('s', $ts);
            return mktime($h,$i,$s);
        }
        Log::warning("Format d'heure non reconnu", ['heure_originale' => $timeString]);
        return false;
    }

    private function calculateDailyHours($pointage)
    {
        if (empty($pointage->heureEntree) || empty($pointage->heureSortie)) return 0.0;

        $start = $this->parseTime($pointage->heureEntree);
        $end   = $this->parseTime($pointage->heureSortie);

        if ($start === false || $end === false) return 0.0;

        if ($end < $start) $end += 86400;

        $seconds = $end - $start;
        if ($seconds <= 0) return 0.0;
        if ($seconds > 86400) {
            Log::warning('Durée de travail > 24h détectée', [
                'in' => $pointage->heureEntree, 'out' => $pointage->heureSortie, 'seconds' => $seconds
            ]);
            return 0.0;
        }

        $hours = $seconds / 3600.0;
        return max(0.0, $hours);
    }

    private function calculateNightBaseHours($pointages)
    {
        $total = 0.0;
        foreach ($pointages as $p) {
            $start = $this->parseTime($p->heureEntree);
            $end   = $this->parseTime($p->heureSortie);
            if ($start === false || $end === false) continue;
            if ($end < $start) $end += 86400;

            $s = $start / 3600.0;
            $e = $end   / 3600.0;

            $ov1 = max(0.0, min($e, 8.0)  - max($s, 0.0));
            $ov2 = max(0.0, min($e, 32.0) - max($s, 24.0));

            $total += ($ov1 + $ov2);
        }
        return $total;
    }

    private function computeDailyTotalHours($pointages)
    {
        if (!$pointages || count($pointages) === 0) return 0.0;

        $totalRaw = 0.0;
        $overlapsNight = false;

        foreach ($pointages as $p) {
            $totalRaw += $this->calculateDailyHours($p);

            $start = $this->parseTime($p->heureEntree);
            $end   = $this->parseTime($p->heureSortie);
            if ($start === false || $end === false) continue;
            if ($end < $start) $end += 86400;

            $s = $start / 3600.0;
            $e = $end   / 3600.0;
            $ovNight1 = max(0.0, min($e, 9.0)  - max($s, 0.0));
            $ovNight2 = max(0.0, min($e, 33.0) - max($s, 24.0));
            if (($ovNight1 + $ovNight2) > 0) $overlapsNight = true;
        }

        if (!$overlapsNight && $totalRaw > 8.0) {
            $totalRaw -= 1.0;
        }

        return max(0.0, $totalRaw);
    }

    // computeDailyTotalHoursForTemporary is provided by TimeCalculationService

    private function getMoisFrancais($moisNum)
    {
        $mois = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];
        return $mois[$moisNum] ?? 'Mois';
    }

    private function calculateEmployeeStats($userId, $dateRange)
    {
        // Récupérer les données depuis MonthlyPresenceExportController pour plus de cohérence
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            return ['jours_travailles' => 0, 'conges' => 0, 'total_heures' => 0, 'heures_supp' => 0];
        }

        $monthlyStats = $this->getDataFromMonthlyExport($user, $dateRange, true); // true = permanent
        
        if ($monthlyStats) {
            return [
                'jours_travailles' => $monthlyStats['jours_travailles'],
                'conges' => $this->calculateConges($userId, $dateRange),
                'total_heures' => $monthlyStats['total_heures'],
                'heures_supp' => $monthlyStats['heures_supp']
            ];
        }

        // Fallback: calcul direct
        return $this->calculateEmployeeStatsDirect($userId, $dateRange);
    }

    private function calculateConges($userId, $dateRange)
    {
        $conges = 0;
        $currentDate = clone $dateRange['startDate'];
        while ($currentDate <= $dateRange['endDate']) {
            $dateStr = $currentDate->format('Y-m-d');
            
            $conge = DB::table('absence_requests')
                ->where('user_id', $userId)
                ->whereIn('type', ['Congé', 'maladie'])
                ->where('statut', 'approuvé')
                ->whereDate('dateDebut', '<=', $dateStr)
                ->whereDate('dateFin', '>=', $dateStr)
                ->first();
            
            if ($conge) {
                $conges++;
            }
            
            $currentDate->modify('+1 day');
        }
        
        return $conges;
    }

    private function calculateEmployeeStatsDirect($userId, $dateRange)
    {
        $joursTravailles = 0;
        $conges = 0;
        
        $currentDate = clone $dateRange['startDate'];
        while ($currentDate <= $dateRange['endDate']) {
            $dateStr = $currentDate->format('Y-m-d');
            
            // Vérifier les pointages
            $pointage = DB::table('pointages')
                ->where('user_id', $userId)
                ->whereDate('date', $dateStr)
                ->where('statutJour', 'present')
                ->first();
            
            // Vérifier les congés
            $conge = DB::table('absence_requests')
                ->where('user_id', $userId)
                ->whereIn('type', ['Congé', 'maladie'])
                ->where('statut', 'approuvé')
                ->whereDate('dateDebut', '<=', $dateStr)
                ->whereDate('dateFin', '>=', $dateStr)
                ->first();
            
            if ($conge) {
                $conges++;
            } elseif ($pointage) {
                $joursTravailles++;
            }
            
            $currentDate->modify('+1 day');
        }
        
        return [
            'jours_travailles' => $joursTravailles,
            'conges' => $conges,
            'total_heures' => 0,
            'heures_supp' => 0
        ];
    }

    private function exportExcel($spreadsheet, $dateRange, $prefix = 'Export')
    {
        $filename = $prefix . "_" . $dateRange['label'] . ".xlsx";
        $writer   = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer, $spreadsheet) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            $writer->save('php://output');
            if (method_exists($spreadsheet, 'disconnectWorksheets')) {
                $spreadsheet->disconnectWorksheets();
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0, no-cache, no-store, must-revalidate',
            'Pragma' => 'public',
        ]);
    }

    private function createRecapChargePersonnelSheet($spreadsheet, $societeId, $dateRange)
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('Recap');

        // Titre principal
        $row = 1;
        $sheet->setCellValue('A' . $row, 'CHARGE PERSONNEL DCT');
        $sheet->mergeCells('A' . $row . ':N' . $row);
        $sheet->getStyle('A' . $row . ':N' . $row)->applyFromArray([
            'font' => ['bold' => true, 'size' => 16],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EAD3']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THICK]]
        ]);
        $row += 2;

        // Générer les en-têtes de mois (12 mois de l'année courante)
        $currentYear = date('Y');
        $monthHeaders = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthHeaders[] = date('M-y', mktime(0, 0, 0, $m, 1, $currentYear));
        }

        // En-têtes du tableau
        $headers = [''];  // Première colonne vide pour les libellés
        $headers = array_merge($headers, $monthHeaders);

        foreach ($headers as $index => $header) {
            $colLetter = Coordinate::stringFromColumnIndex($index + 1);
            $sheet->setCellValue($colLetter . $row, $header);
        }

        $lastCol = Coordinate::stringFromColumnIndex(count($headers));
        $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);
        $row++;

        // Lignes de données selon l'image
        $dataRows = [
            'PERMANENT',
            'CHARGES',
            'TEMPORAIRES', 
            'CHARGES',
            'AUTRES CHARGES RH',
            'TOTAL CHARGE PERSONNEL'
        ];

        // Calculer les montants pour chaque ligne selon chaque mois
        $startRowData = $row;
        foreach ($dataRows as $index => $rowLabel) {
            $sheet->setCellValue('A' . $row, $rowLabel);
            
            if ($rowLabel === 'PERMANENT') {
                // Calculer pour chaque mois
                for ($col = 2; $col <= count($headers); $col++) {
                    $colLetter = Coordinate::stringFromColumnIndex($col);
                    $monthIndex = $col - 2; // Index du mois (0-11)
                    $monthNumber = $monthIndex + 1; // Numéro du mois (1-12)
                    
                    $totalSalaireNet = $this->calculatePermanentSalaryTotalForMonth($societeId, $currentYear, $monthNumber);
                    // Utiliser la valeur numérique brute (sans arrondi) pour permettre les calculs Excel
                    $sheet->setCellValue($colLetter . $row, $totalSalaireNet);
                }
            } elseif ($rowLabel === 'CHARGES' && isset($dataRows[$index - 1]) && $dataRows[$index - 1] === 'PERMANENT') {
                // Charges permanents: 27% des salaires permanents
                for ($col = 2; $col <= count($headers); $col++) {
                    $colLetter = Coordinate::stringFromColumnIndex($col);
                    $permanentRow = $row - 1;
                    $sheet->setCellValue($colLetter . $row, "=". $colLetter . $permanentRow . "*0.27");
                }
            } elseif ($rowLabel === 'TEMPORAIRES') {
                // Calculer pour chaque mois
                for ($col = 2; $col <= count($headers); $col++) {
                    $colLetter = Coordinate::stringFromColumnIndex($col);
                    $monthIndex = $col - 2; // Index du mois (0-11)
                    $monthNumber = $monthIndex + 1; // Numéro du mois (1-12)
                    
                    $totalCoutTemporaires = $this->calculateTemporaryCostTotalForMonth($societeId, $currentYear, $monthNumber);
                    // Utiliser la valeur numérique brute (sans arrondi) pour permettre les calculs Excel
                    $sheet->setCellValue($colLetter . $row, $totalCoutTemporaires);
                }
            } elseif ($rowLabel === 'CHARGES' && isset($dataRows[$index - 1]) && $dataRows[$index - 1] === 'TEMPORAIRES') {
                // Charges temporaires: 27% des coûts temporaires
                for ($col = 2; $col <= count($headers); $col++) {
                    $colLetter = Coordinate::stringFromColumnIndex($col);
                    $temporaireRow = $row - 1;
                    $sheet->setCellValue($colLetter . $row, "=". $colLetter . $temporaireRow . "*0.27");
                }
            } elseif ($rowLabel === 'AUTRES CHARGES RH') {
                // Laisser à 0 (valeur numérique)
                for ($col = 2; $col <= count($headers); $col++) {
                    $colLetter = Coordinate::stringFromColumnIndex($col);
                    $sheet->setCellValue($colLetter . $row, 0);
                }
            } elseif ($rowLabel === 'TOTAL CHARGE PERSONNEL') {
                // Formule Excel: somme des lignes précédentes
                for ($col = 2; $col <= count($headers); $col++) {
                    $colLetter = Coordinate::stringFromColumnIndex($col);
                    $permanentRow = $startRowData;
                    $chargesPermanentRow = $startRowData + 1;
                    $temporaireRow = $startRowData + 2;
                    $chargesTemporaireRow = $startRowData + 3;
                    $autresChargesRow = $startRowData + 4;
                    
                    $sheet->setCellValue($colLetter . $row, 
                        "={$colLetter}{$permanentRow}+{$colLetter}{$chargesPermanentRow}+{$colLetter}{$temporaireRow}+{$colLetter}{$chargesTemporaireRow}+{$colLetter}{$autresChargesRow}"
                    );
                }
            }

            // Style spécial pour la ligne total
            if ($rowLabel === 'TOTAL CHARGE PERSONNEL') {
                $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
                ]);
            } else {
                $sheet->getStyle('A' . $row . ':' . $lastCol . $row)->applyFromArray([
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
                ]);
            }
            $row++;
        }

        // Appliquer format d'affichage (display-only) pour toutes les cellules numériques du bloc données
        $endRowData = $row - 1;
        if ($endRowData >= $startRowData) {
            $this->formatRange($sheet, 'B' . $startRowData . ':' . $lastCol . $endRowData, 0);
        }

        // Auto-ajuster les colonnes
        for ($c = 1; $c <= count($headers); $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }

        $sheet->freezePane('B4');
    }

    private function calculatePermanentSalaryTotalForMonth($societeId, $year, $month)
    {
        // Créer les dates de début et fin du mois
        $startOfMonth = new DateTime("$year-$month-01");
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month');
        
        // Récupérer la somme des salaires nets des employés permanents actifs dans ce mois
        $total = DB::table('users')
            ->leftJoin('salaires', 'users.id', '=', 'salaires.user_id')
            ->where('users.societe_id', $societeId)
            ->where(function($query) use ($startOfMonth, $endOfMonth) {
                $query->where(function($q) {
                    // Employés actifs (pas de statut inactif OU pas de date de sortie)
                    $q->whereRaw('LOWER(TRIM(COALESCE(users.statut, ""))) != "inactif"')
                      ->orWhereNull('users.date_sortie');
                })
                ->orWhere(function($q) use ($endOfMonth) {
                    // OU employés inactifs mais avec date de sortie >= fin du mois
                    $q->whereRaw('LOWER(TRIM(COALESCE(users.statut, ""))) = "inactif"')
                      ->whereNotNull('users.date_sortie')
                      ->whereDate('users.date_sortie', '>=', $endOfMonth->format('Y-m-d'));
                });
            })
            ->where(function($query) {
                $query->whereIn(DB::raw('LOWER(TRIM(COALESCE(users.typeContrat, "")))'), 
                    ['permanent', 'permanente', 'cdi', 'indéterminée', 'indeterminee'])
                      ->orWhere('users.typeContrat', 'LIKE', '%permanent%')
                      ->orWhere('users.typeContrat', 'LIKE', '%CDI%');
            })
            ->sum('salaires.salaire_net');
            
        return $total ?? 0;
    }

    private function calculateTemporaryCostTotalForMonth($societeId, $year, $month)
    {
        // Créer la période du mois
        $startOfMonth = new DateTime("$year-$month-01");
        $endOfMonth = (clone $startOfMonth)->modify('last day of this month');
        
        $monthDateRange = [
            'startDate' => $startOfMonth,
            'endDate' => $endOfMonth
        ];
        
        // Récupérer les temporaires actifs dans ce mois
        $rawTemporaires = DB::table('users')
            ->where('users.societe_id', $societeId)
            ->where(function($query) use ($startOfMonth, $endOfMonth) {
                $query->where(function($q) {
                    // Employés actifs (pas de statut inactif OU pas de date de sortie)
                    $q->whereRaw('LOWER(TRIM(COALESCE(users.statut, ""))) != "inactif"')
                      ->orWhereNull('users.date_sortie');
                })
                ->orWhere(function($q) use ($endOfMonth) {
                    // OU employés inactifs mais avec date de sortie >= fin du mois
                    $q->whereRaw('LOWER(TRIM(COALESCE(users.statut, ""))) = "inactif"')
                      ->whereNotNull('users.date_sortie')
                      ->whereDate('users.date_sortie', '>=', $endOfMonth->format('Y-m-d'));
                });
            })
            ->get()
            ->filter(function($u){ return !$this->isPermanent($u); });

        $ids = $rawTemporaires->pluck('id')->all();
        $totalCout = 0;
        
        if (!empty($ids)) {
            $pointages = DB::table('pointages')
                ->select('user_id')
                ->whereIn('user_id', $ids)
                ->whereBetween('date', [
                    $startOfMonth->format('Y-m-d'),
                    $endOfMonth->format('Y-m-d')
                ])
                ->whereIn('statutJour', ['present','retard'])
                ->groupBy('user_id')
                ->pluck('user_id')
                ->toArray();

            if (!empty($pointages)) {
                $employes = DB::table('users')
                    ->leftJoin('salaires', 'users.id', '=', 'salaires.user_id')
                    ->whereIn('users.id', $pointages)
                    ->select(
                        'users.*',
                        'salaires.salaire_base',
                        DB::raw('COALESCE(salaires.panier, salaires.panier, 0) as panier')
                    )
                    ->get();

                foreach ($employes as $emp) {
                    $detailedStats = \App\Services\TimeCalculationService::computeDetailedTemporaryStats($emp, $monthDateRange);
                    $tauxH = $emp->salaire_base ?? 0;
                    $primePanier = $emp->panier ?? 0;
                    
                    // Nouveau calcul avec les heures détaillées
                    $coutTotal = ($detailedStats['heures_normales'] * $tauxH)
                        + ($detailedStats['hs_25'] * $tauxH * 1.25)
                        + ($detailedStats['hs_50'] * $tauxH * 1.5)
                        + ($primePanier * $detailedStats['jours_travailles']);
                        
                    $totalCout += $coutTotal;
                }
            }
        }
        
        return $totalCout;
    }

    public function exportSalaries(Request $request)
    {
        return $this->export($request);
    }

private function createRecapDepartementsSheet($spreadsheet, $societeId, $dateRange)
{
    $sheet = $spreadsheet->createSheet();
    $sheet->setTitle('Recap Départements');

    // Titre
    $sheet->setCellValue('A1', 'RÉCAPITULATIF PAR DÉPARTEMENT (source: pointages.column_id, règles Monthly)');
    $sheet->mergeCells('A1:G1');
    $sheet->getStyle('A1:G1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 16],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9EAD3']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THICK]]
    ]);

    // En-têtes
    $row = 3;
    $headers = [
        'AFFECTATION/DÉPARTEMENT (pointage)',
        'TOTAL EFFECTIF PRÉSENT DURANT LA PÉRIODE',
        'TOTAL HEURES TRAVAILLÉES',
        'TOTAL HEURES SUPP',
        'COÛT',
        'CHARGES',
        'COÛT TOTAL'
    ];
    foreach ($headers as $i => $h) {
        $col = chr(65 + $i);
        $sheet->setCellValue($col.$row, $h);
    }
    $sheet->getStyle('A3:G3')->applyFromArray([
        'font' => ['bold' => true, 'size' => 10],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F4FD']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
    ]);

    // Données (par column_id)
    $departements = $this->getDepartmentStatsByPointageColumn($societeId, $dateRange);

    $row = 4;
    $totEff = 0; $totH = 0.0; $totHS = 0.0; $totC = 0.0; $totCh = 0.0; $totCT = 0.0;
    $first = $row;
    foreach ($departements as $d) {
        $sheet->fromArray([
            $d['nom'], $d['effectif'], $d['total_heures'], $d['heures_supp'], $d['cout'], $d['charges'], $d['cout_total']
        ], null, "A{$row}");

        $totEff += (int)$d['effectif'];
        $totH   += (float)$d['total_heures'];
        $totHS  += (float)$d['heures_supp'];
        $totC   += (float)$d['cout'];
        $totCh  += (float)$d['charges'];
        $totCT  += (float)$d['cout_total'];

        $fill = ($row % 2 === 0) ? 'F9F9F9' : 'FFFFFF';
        $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $fill]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
        ]);

        $row++;
    }

    $last = $row - 1;
    if ($last >= $first) {
        $this->formatRange($sheet, "B{$first}:B{$last}", 0);  // effectif
        $this->formatRange($sheet, "C{$first}:D{$last}", 0);  // heures
        $this->formatRange($sheet, "E{$first}:G{$last}", 0);  // coûts
    }

    // Total général
    $sheet->fromArray(['TOTAL GÉNÉRAL', $totEff, $totH, $totHS, $totC, $totCh, $totCT], null, "A{$row}");
    $sheet->getStyle("A{$row}:G{$row}")->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFE6CC']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THICK]]
    ]);
    $this->formatRange($sheet, "B{$row}:B{$row}", 0);
    $this->formatRange($sheet, "C{$row}:D{$row}", 0);
    $this->formatRange($sheet, "E{$row}:G{$row}", 0);

    foreach (range('A','G') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
    $sheet->freezePane('A4');
}

    private function getDepartmentStatsByPointageColumn($societeId, $dateRange): array
{
    // 1) Lister les départements vus dans les pointages de la période
    $deptRows = DB::table('pointages')
        ->leftJoin('users','pointages.user_id','=','users.id')
        ->leftJoin('departements','pointages.departement_id','=','departements.id')
        ->where('users.societe_id',$societeId)
        ->whereBetween('pointages.date', [
            $dateRange['startDate']->format('Y-m-d'),
            $dateRange['endDate']->format('Y-m-d')
        ])
        ->whereIn('pointages.statutJour',['present','retard'])
        ->select(
            'pointages.departement_id',
            DB::raw("UPPER(TRIM(COALESCE(NULLIF(TRIM(departements.nom), ''), 'NON AFFECTÉ'))) as dept_nom")
        )
        ->groupBy('pointages.departement_id','departements.nom')
        ->get();

        $departements=[];
        foreach ($deptRows as $dr) {
            $label = strtoupper(trim((string)$dr->dept_nom));
            // NA si id null/0 OU nom vide/"NON AFFECTÉ"
            $isNA = (is_null($dr->departement_id) || (int)$dr->departement_id === 0 || $label === 'NON AFFECTÉ');
            $key  = $isNA ? 'NA' : (string)$dr->departement_id;
            $departements[$key]=[
                'departement_id' => $isNA ? null : (int)$dr->departement_id,
                'nom'            => $label ?: 'NON AFFECTÉ',
                'effectif'       => 0,
                'total_heures'   => 0.0,
                'heures_supp'    => 0.0,
                'cout'           => 0.0,
                'charges'        => 0.0,
                'cout_total'     => 0.0,
            ];
        }
    if (empty($departements)) return [];

    // 2) Récupérer tous les utilisateurs (cache)
    $users = DB::table('users')
        ->leftJoin('salaires','users.id','=','salaires.user_id')
        ->where('users.societe_id',$societeId)
        ->select(
            'users.*',
            DB::raw('COALESCE(salaires.salaire_base,0) as salaire_base'),
            DB::raw('COALESCE(salaires.panier,0) as panier')
        )->get()->keyBy('id');

    // 3) Calcul pour chaque département
        foreach ($departements as $deptKey=>&$dept) {
            $deptId = $dept['departement_id'];

        // Liste des utilisateurs ayant travaillé dans ce département
            $userQuery = DB::table('pointages')
                ->whereBetween('date', [
                    $dateRange['startDate']->format('Y-m-d'),
                    $dateRange['endDate']->format('Y-m-d')
                ])
                ->whereIn('statutJour',['present','retard']);
            if (is_null($deptId)) {
                $userQuery->where(function($q){
                    $q->whereNull('departement_id')->orWhere('departement_id', 0);
                });
            } else {
                $userQuery->where('departement_id', $deptId);
            }
            $userIds = $userQuery->distinct()->pluck('user_id')->toArray();

        $dept['effectif'] = count($userIds);

        foreach ($userIds as $uid) {
            $u = $users[$uid] ?? null; if (!$u) continue;

            if ($this->isPermanent($u)) {
                // Permanents — appliquer les mêmes règles que Monthly (HS si >8h et base nuit <8, dimanche/jour férié gérés)
                $stats = $this->getDataFromMonthlyExportForDepartement($u, $dateRange, $deptId);
                $total = (float)($stats['total_heures'] ?? 0);
                $hs    = (float)($stats['heures_supp']   ?? 0);
                $hn    = max(0.0, $total - $hs);

                $taux    = ((float)$u->salaire_base) / 173.33;
                $cout    = ($hn * $taux) + ($hs * $taux * 1.5);
                $charges = $cout * 0.21;

                $dept['total_heures'] += $total;
                $dept['heures_supp']  += $hs;
                $dept['cout']         += $cout;
                $dept['charges']      += $charges;
                $dept['cout_total']   += ($cout + $charges);

            } else {
                // Temporaires
                $d    = $this->computeDetailedTemporaryStatsForDepartement($u, $dateRange, $deptId);
                $hn   = (float)$d['heures_normales'];
                $hs25 = (float)$d['hs_25'];
                $hs50 = (float)$d['hs_50'];
                $hs   = $hs25 + $hs50;
                $tot  = $hn + $hs;

                $tauxH   = (float)$u->salaire_base;
                $panier  = (float)$u->panier;
                $jours   = (int)$d['jours_travailles'];

                $cout    = ($hn * $tauxH) + ($hs25 * $tauxH * 1.25) + ($hs50 * $tauxH * 1.5) + ($panier * $jours);
                $charges = $cout * 0.21;

                $dept['total_heures'] += $tot;
                $dept['heures_supp']  += $hs;
                $dept['cout']         += $cout;
                $dept['charges']      += $charges;
                $dept['cout_total']   += ($cout + $charges);
            }
        }
    }

    return array_values($departements);
}

private function resolvePointageColumnLookup(): ?array
{
    // Liste de tables/colonnes possibles : adapte si besoin
    $candidates = [
        // table,         id,     label
        ['pointage_columns','id', 'nom'],
        ['pointage_columns','id', 'name'],
        ['columns_pointage','id', 'nom'],
        ['columns_pointage','id', 'name'],
        ['board_columns',  'id',  'title'],
        ['departements',   'id',  'nom'],   // parfois column_id = departement_id
        ['departments',    'id',  'name'],
    ];

    foreach ($candidates as [$table,$id,$label]) {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $id) && Schema::hasColumn($table, $label)) {
            return ['table'=>$table,'id'=>$id,'label'=>$label];
        }
    }
    return null; // pas de table trouvée → on n'utilise pas de jointure
}



    /**
     * Vérifier si un utilisateur a des pointages dans la période donnée
     */
    private function hasPointagesInPeriod($userId, $dateRange)
{
    $start = $dateRange['startDate']->format('Y-m-d');
    $end   = $dateRange['endDate']->format('Y-m-d');

    return DB::table('pointages')
        ->where('user_id', $userId)
        ->whereDate('date', '>=', $start)
        ->whereDate('date', '<=', $end)
        ->whereIn('statutJour', ['present','retard'])
        ->exists();
}
// Temporaires — règles Monthly, filtrées par column_id
private function computeDetailedTemporaryStatsForColumn($user, $dateRange, $columnId): array
{
    $hn=0.0; $hs25=0.0; $hs50=0.0; $tot=0.0; $jours=0;

    $joursFeries = JourFerie::whereBetween('date', [
        $dateRange['startDate']->format('Y-m-d'), $dateRange['endDate']->format('Y-m-d')
    ])->where('actif', true)->pluck('date')->map(fn($d)=>date('Y-m-d',strtotime($d)))->toArray();

    $d = clone $dateRange['startDate'];
    while ($d <= $dateRange['endDate']) {
        $ds = $d->format('Y-m-d');
        $isHoliday = in_array($ds,$joursFeries,true);
        $isSunday = ((int)$d->format('w')===0);

        // congé ?
        $conge = DB::table('absence_requests')
            ->where('user_id',$user->id)->whereIn('type',['Congé','maladie'])
            ->where('statut','approuvé')->whereDate('dateDebut','<=',$ds)->whereDate('dateFin','>=',$ds)->first();
        if ($conge){ $d->modify('+1 day'); continue; }

        $pts = DB::table('pointages')
            ->where('user_id',$user->id)->where('column_id',$columnId)
            ->whereDate('date',$ds)->get();

        $daily = TimeCalculationService::computeDailyTotalHoursForTemporary($pts);
        if ($daily > 0){
            $jours++; $tot += $daily;
            if ($isSunday || $isHoliday){
                $hs50 += $daily;
            } else {
                $adjusted = $daily;
                if ($adjusted <= 8) $hn += $adjusted;
                else { $hn += 8; $hs25 += ($adjusted - 8); }
            }
        }
        $d->modify('+1 day');
    }
    return ['heures_normales'=>$hn,'hs_25'=>$hs25,'hs_50'=>$hs50,'total_heures'=>$tot,'jours_travailles'=>$jours];
}
private function computeDetailedTemporaryStatsForDepartement($user, $dateRange, $departementId): array
{
    $hn=0.0; $hs25=0.0; $hs50=0.0; $tot=0.0; $jours=0;

    $joursFeries = JourFerie::whereBetween('date', [
        $dateRange['startDate']->format('Y-m-d'),
        $dateRange['endDate']->format('Y-m-d')
    ])->where('actif', true)->pluck('date')->map(fn($d)=>date('Y-m-d',strtotime($d)))->toArray();

    $d = clone $dateRange['startDate'];
    while ($d <= $dateRange['endDate']) {
        $ds = $d->format('Y-m-d');
        $isHoliday = in_array($ds,$joursFeries,true);
        $isSunday = ((int)$d->format('w')===0);

        $conge = DB::table('absence_requests')
            ->where('user_id',$user->id)
            ->whereIn('type',['Congé','maladie'])
            ->where('statut','approuvé')
            ->whereDate('dateDebut','<=',$ds)
            ->whereDate('dateFin','>=',$ds)
            ->first();
        if ($conge){ $d->modify('+1 day'); continue; }

        $ptsQ = DB::table('pointages')
            ->where('user_id',$user->id)
            ->whereDate('date',$ds);
        if (is_null($departementId)) {
            $ptsQ->where(function($q){ $q->whereNull('departement_id')->orWhere('departement_id',0); });
        } else {
            $ptsQ->where('departement_id',$departementId);
        }
        $pts = $ptsQ->get();

        $daily = TimeCalculationService::computeDailyTotalHoursForTemporary($pts);
        if ($daily > 0){
            $jours++; $tot += $daily;
            if ($isSunday || $isHoliday){
                $hs50 += $daily;
            } else {
                if ($daily <= 8) $hn += $daily;
                else { $hn += 8; $hs25 += ($daily - 8); }
            }
        }
        $d->modify('+1 day');
    }

    return [
        'heures_normales'=>$hn,
        'hs_25'=>$hs25,
        'hs_50'=>$hs50,
        'total_heures'=>$tot,
        'jours_travailles'=>$jours
    ];
}
private function getDataFromMonthlyExportForDepartement($user, $dateRange, $departementId): array
{
    $joursFeries = JourFerie::whereBetween('date', [
        $dateRange['startDate']->format('Y-m-d'),
        $dateRange['endDate']->format('Y-m-d')
    ])->where('actif', true)->pluck('date')->map(fn($d)=>date('Y-m-d',strtotime($d)))->toArray();

    $totalHeures=0.0; $heuresSupp=0.0; $joursTravailles=0;

    $d = clone $dateRange['startDate'];
    while ($d <= $dateRange['endDate']) {
        $ds = $d->format('Y-m-d');
        $dow=(int)$d->format('w');
        $isHoliday=in_array($ds,$joursFeries,true);

        $ptsQ = DB::table('pointages')
            ->where('user_id',$user->id)
            ->whereDate('date',$ds);
        if (is_null($departementId)) {
            $ptsQ->where(function($q){ $q->whereNull('departement_id')->orWhere('departement_id',0); });
        } else {
            $ptsQ->where('departement_id',$departementId);
        }
        $pts = $ptsQ->get();

        $conge = DB::table('absence_requests')
            ->where('user_id',$user->id)
            ->whereIn('type',['Congé','maladie'])
            ->where('statut','approuvé')
            ->whereDate('dateDebut','<=',$ds)
            ->whereDate('dateFin','>=',$ds)
            ->first();
        if ($conge){ $d->modify('+1 day'); continue; }

        $daily = TimeCalculationService::computeDailyTotalHoursForTemporary($pts);
        $nightBase = $this->calculateNightBaseHours($pts);

        if ($isHoliday) {
            if ($daily > 0) {
                $totalHeures += $daily;
                $joursTravailles += 1; // Compté comme travaillé si présent un jour férié
                if ($daily > 8) $heuresSupp += ($daily - 8);
            }
        } else {
            if ($daily > 0) {
                if ($dow !== 0) $joursTravailles += 1; // pas de jour ouvré pour dimanche
                if ($daily > 8 && $nightBase < 8) $heuresSupp += ($daily - 8);
                $totalHeures += $daily;
            }
        }
        $d->modify('+1 day');
    }

    return [
        'total_heures'=>$totalHeures,
        'heures_supp'=>$heuresSupp,
        'jours_travailles'=>$joursTravailles
    ];
}


// Permanents — règles Monthly, filtrées par column_id
private function getDataFromMonthlyExportForColumn($user, $dateRange, $columnId): array
{
    $joursFeries = JourFerie::whereBetween('date', [
        $dateRange['startDate']->format('Y-m-d'), $dateRange['endDate']->format('Y-m-d')
    ])->where('actif', true)->pluck('date')->map(fn($d)=>date('Y-m-d',strtotime($d)))->toArray();

    $totalHeures=0.0; $heuresSupp=0.0; $joursTravailles=0;

    $d = clone $dateRange['startDate'];
    while ($d <= $dateRange['endDate']) {
        $ds = $d->format('Y-m-d'); $dow=(int)$d->format('w');
        $isHoliday=in_array($ds,$joursFeries,true);

        $pts = DB::table('pointages')
            ->where('user_id',$user->id)->where('column_id',$columnId)
            ->whereDate('date',$ds)->get();

        $conge = DB::table('absence_requests')
            ->where('user_id',$user->id)->whereIn('type',['Congé','maladie'])
            ->where('statut','approuvé')->whereDate('dateDebut','<=',$ds)->whereDate('dateFin','>=',$ds)->first();
        if ($conge){ $d->modify('+1 day'); continue; }

        $daily = TimeCalculationService::computeDailyTotalHoursForTemporary($pts);
        $nightBase = $this->calculateNightBaseHours($pts);

        if ($isHoliday) {
            if ($daily > 0) {
                $totalHeures += $daily; $joursTravailles += 1;
                if ($daily > 8) $heuresSupp += ($daily - 8);
            }
        } else {
            if ($daily > 0) {
                if ($dow !== 0) $joursTravailles += 1;
                if ($daily > 8 && $nightBase < 8) $heuresSupp += ($daily - 8);
                $totalHeures += $daily;
            }
        }
        $d->modify('+1 day');
    }
    return ['total_heures'=>$totalHeures,'heures_supp'=>$heuresSupp,'jours_travailles'=>$joursTravailles];
}

}