<?php
/**
 * Helper functions for the attachee management system
 */

/**
 * Get Kenyan financial year ranges
 * @return array Array of financial years in format ['2023-2024', '2024-2025', ...]
 */
function getFinancialYears() {
    $currentYear = date('Y');
    $currentMonth = date('n');
    $years = [];
    
    // Kenyan financial year runs July to June
    $startYear = ($currentMonth >= 7) ? $currentYear : $currentYear - 1;
    
    // Generate 5 years before and after current financial year
    for ($i = -5; $i <= 5; $i++) {
        $year = $startYear + $i;
        $years[] = $year . '-' . ($year + 1);
    }
    
    return $years;
}

/**
 * Get start and end dates for a financial year
 * @param string $financialYear Format 'YYYY-YYYY'
 * @return array ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD']
 */
function getFinancialYearDates($financialYear) {
    $years = explode('-', $financialYear);
    if (count($years) !== 2 || !is_numeric($years[0]) || !is_numeric($years[1])) {
        throw new InvalidArgumentException('Invalid financial year format. Expected YYYY-YYYY');
    }
    return [
        'start' => $years[0] . '-07-01',
        'end' => $years[1] . '-06-30'
    ];
}

/**
 * Get current financial year based on today's date
 * @return string Current financial year in 'YYYY-YYYY' format
 */
function getCurrentFinancialYear() {
    $currentMonth = date('n');
    $currentYear = date('Y');
    return ($currentMonth >= 7) ? $currentYear . '-' . ($currentYear + 1) : ($currentYear - 1) . '-' . $currentYear;
}

/**
 * Validate a financial year string
 * @param string $financialYear
 * @return bool True if valid, false otherwise
 */
function isValidFinancialYear($financialYear) {
    return preg_match('/^\d{4}-\d{4}$/', $financialYear) && 
           (int)substr($financialYear, 0, 4) === (int)substr($financialYear, 5, 4) - 1;
}

/**
 * Get short form of financial year (e.g., '23-24' for '2023-2024')
 * @param string $financialYear
 * @return string Shortened financial year
 */
function getShortFinancialYear($financialYear) {
    if (!isValidFinancialYear($financialYear)) {
        return '';
    }
    return substr($financialYear, 2, 2) . '-' . substr($financialYear, 7, 2);
}

/**
 * Get financial year for a specific date
 * @param string $date Date in YYYY-MM-DD format
 * @return string Financial year in YYYY-YYYY format
 */
function getFinancialYearForDate($date) {
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return getCurrentFinancialYear();
    }
    
    $month = date('n', $timestamp);
    $year = date('Y', $timestamp);
    
    return ($month >= 7) ? $year . '-' . ($year + 1) : ($year - 1) . '-' . $year;
}

/**
 * Get financial quarter for a specific date
 * @param string $date Date in YYYY-MM-DD format
 * @return array ['quarter' => 'Q1', 'start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD']
 */
function getFinancialQuarterForDate($date) {
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return null;
    }
    
    $month = date('n', $timestamp);
    $year = date('Y', $timestamp);
    
    // Determine financial year
    $financialYear = ($month >= 7) ? $year . '-' . ($year + 1) : ($year - 1) . '-' . $year;
    $years = explode('-', $financialYear);
    
    if ($month >= 7 && $month <= 9) {
        return [
            'quarter' => 'Q1',
            'start' => $years[0] . '-07-01',
            'end' => $years[0] . '-09-30'
        ];
    } elseif ($month >= 10 && $month <= 12) {
        return [
            'quarter' => 'Q2',
            'start' => $years[0] . '-10-01',
            'end' => $years[0] . '-12-31'
        ];
    } elseif ($month >= 1 && $month <= 3) {
        return [
            'quarter' => 'Q3',
            'start' => $years[1] . '-01-01',
            'end' => $years[1] . '-03-31'
        ];
    } else {
        return [
            'quarter' => 'Q4',
            'start' => $years[1] . '-04-01',
            'end' => $years[1] . '-06-30'
        ];
    }
}

/**
 * Get all quarters for a financial year
 * @param string $financialYear Format 'YYYY-YYYY'
 * @return array Array of quarters with start and end dates
 */
function getFinancialYearQuarters($financialYear) {
    if (!isValidFinancialYear($financialYear)) {
        return [];
    }
    
    $years = explode('-', $financialYear);
    
    return [
        'Q1' => [
            'name' => 'First Quarter',
            'start' => $years[0] . '-07-01',
            'end' => $years[0] . '-09-30'
        ],
        'Q2' => [
            'name' => 'Second Quarter',
            'start' => $years[0] . '-10-01',
            'end' => $years[0] . '-12-31'
        ],
        'Q3' => [
            'name' => 'Third Quarter',
            'start' => $years[1] . '-01-01',
            'end' => $years[1] . '-03-31'
        ],
        'Q4' => [
            'name' => 'Fourth Quarter',
            'start' => $years[1] . '-04-01',
            'end' => $years[1] . '-06-30'
        ]
    ];
}

/**
 * Get current financial quarter
 * @return array Current quarter info
 */
function getCurrentFinancialQuarter() {
    return getFinancialQuarterForDate(date('Y-m-d'));
}

/**
 * Get start and end dates for a specific quarter in a financial year
 * @param string $financialYear Format 'YYYY-YYYY'
 * @param string $quarter Quarter identifier (1, 2, 3, 4, or 'all')
 * @return array ['start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD']
 */
function getQuarterDates($financialYear, $quarter = 'all') {
    if (!isValidFinancialYear($financialYear)) {
        throw new InvalidArgumentException('Invalid financial year format. Expected YYYY-YYYY');
    }
    
    $quarters = getFinancialYearQuarters($financialYear);
    
    if ($quarter === 'all') {
        return [
            'start' => $quarters['Q1']['start'],
            'end' => $quarters['Q4']['end']
        ];
    }
    
    $quarterKey = 'Q' . $quarter;
    if (!isset($quarters[$quarterKey])) {
        throw new InvalidArgumentException('Invalid quarter. Expected 1, 2, 3, or 4');
    }
    
    return [
        'start' => $quarters[$quarterKey]['start'],
        'end' => $quarters[$quarterKey]['end']
    ];
}