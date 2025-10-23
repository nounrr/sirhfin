<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\JourFerie;
use DateTime;

class TimeCalculationService
{
    // same rounding policy as controllers
    private static function customRound($number)
    {
        if ($number == 0) return 0;
        $isNegative = $number < 0;
        $number = abs($number);
        $intPart = intval($number);
        $decimalPart = $number - $intPart;
        $result = ($decimalPart >= 0.5) ? $intPart + 1 : $intPart;
        return $isNegative ? -$result : $result;
    }

    // compute raw hours for a pointage row (expects heureEntree/heureSortie strings)
    public static function calculateDailyHoursFromTimes($startTs, $endTs)
    {
        if ($endTs < $startTs) $endTs += 86400;
        $seconds = $endTs - $startTs;
        if ($seconds <= 0) return 0.0;
        if ($seconds > 86400) {
            Log::warning('Durée de travail > 24h détectée (service)', ['start' => $startTs, 'end' => $endTs, 'seconds' => $seconds]);
            return 0.0;
        }
        $hours = $seconds / 3600.0;
        return max(0.0, $hours);
    }

    // compute total adjusted hours for temporaries: per-pointage, split day/night and -1h per portion >7h
    public static function computeDailyTotalHoursForTemporary($pointages)
    {
        if (!$pointages || count($pointages) === 0) return 0.0;
        $total = 0.0;
        foreach ($pointages as $p) {
            if (empty($p->heureEntree) || empty($p->heureSortie)) continue;
            $start = strtotime($p->heureEntree);
            $end   = strtotime($p->heureSortie);
            if ($start === false || $end === false) continue;
            if ($end < $start) $end += 86400;
            $seconds = $end - $start;
            if ($seconds <= 0) continue;
            $hours = $seconds / 3600.0;

            $s = $start / 3600.0;
            $e = $end   / 3600.0;
            $night1 = max(0.0, min($e, 8.0)  - max($s, 0.0));
            $night2 = max(0.0, min($e, 32.0) - max($s, 24.0));
            $nightHours = $night1 + $night2;

            $dayHours = max(0.0, $hours - $nightHours);

            if ($dayHours > 7.0) $dayHours -= 1.0;
            if ($nightHours > 7.0) $nightHours -= 1.0;

            $total += max(0.0, $dayHours) + max(0.0, $nightHours);
        }
        return max(0.0, $total);
    }

    // compute raw total (sum of calculateDailyHoursFromTimes) before pause adjustment
    public static function computeDailyRawHours($pointages)
    {
        if (!$pointages || count($pointages) === 0) return 0.0;
        $totalRaw = 0.0;
        foreach ($pointages as $p) {
            if (empty($p->heureEntree) || empty($p->heureSortie)) continue;
            $start = strtotime($p->heureEntree);
            $end   = strtotime($p->heureSortie);
            if ($start === false || $end === false) continue;
            $totalRaw += self::calculateDailyHoursFromTimes($start, $end);
        }
        return max(0.0, $totalRaw);
    }

    // check if any pointage overlaps night hours (0-9 or 24-33 windows as used historically)
    public static function checkNightOverlap($pointages)
    {
        if (!$pointages || count($pointages) === 0) return false;
        foreach ($pointages as $p) {
            if (empty($p->heureEntree) || empty($p->heureSortie)) continue;
            $start = strtotime($p->heureEntree);
            $end   = strtotime($p->heureSortie);
            if ($start === false || $end === false) continue;
            if ($end < $start) $end += 86400;
            $s = $start / 3600.0;
            $e = $end   / 3600.0;
            $ov1 = max(0.0, min($e, 9.0)  - max($s, 0.0));
            $ov2 = max(0.0, min($e, 33.0) - max($s, 24.0));
            if (($ov1 + $ov2) > 0) return true;
        }
        return false;
    }

    // Compute detailed temporary stats for a user over a date range: heures_normales, hs_25, hs_50, total_heures, jours_travailles
    public static function computeDetailedTemporaryStats($user, $dateRange)
    {
        $heuresNormales = 0.0;
        $hs25 = 0.0;
        $hs50 = 0.0;
        $totalHeures = 0.0;
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
            $isSunday = ($dayOfWeek === 0);

            $conge = DB::table('absence_requests')
                ->where('user_id', $user->id)
                ->whereIn('type', ['Congé', 'maladie'])
                ->where('statut', 'approuvé')
                ->whereDate('dateDebut', '<=', $dateStr)
                ->whereDate('dateFin', '>=', $dateStr)
                ->first();

            if ($conge) { $currentDate->modify('+1 day'); continue; }

            $pointages = DB::table('pointages')
                ->where('user_id', $user->id)
                ->whereDate('date', $dateStr)
                ->get();

            $dailyHours = self::computeDailyTotalHoursForTemporary($pointages);
            if ($dailyHours > 0) {
                $joursTravailles++;
                $totalHeures += $dailyHours;
                if ($isSunday || $isHoliday) {
                    $hs50 += $dailyHours;
                } else {
                    // adjusted hours already exclude pause according to temp rule
                    if ($dailyHours <= 8) {
                        $heuresNormales += $dailyHours;
                    } else {
                        $heuresNormales += 8;
                        $hs25 += ($dailyHours - 8);
                    }
                }
            }
            $currentDate->modify('+1 day');
        }

        return [
            'heures_normales' => $heuresNormales,
            'hs_25' => $hs25,
            'hs_50' => $hs50,
            'total_heures' => $totalHeures,
            'jours_travailles' => $joursTravailles
        ];
    }
}
