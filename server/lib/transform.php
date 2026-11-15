<?php
// Transform step: pure functions that turn raw form values into clean, consistent fields.
// Every rule that can change over time comes from rules/rules.json, which the learning agent maintains.
declare(strict_types=1);

/** Kenyan phone numbers to E.164 (+2547XXXXXXXX / +2541XXXXXXXX). Returns null if it cannot be made valid. */
function jt_phone(?string $raw): ?string {
    if ($raw === null) return null;
    $d = preg_replace('/\D+/', '', $raw);
    if ($d === '') return null;
    if (strpos($d, '00254') === 0) $d = substr($d, 2);
    if (strlen($d) === 10 && $d[0] === '0') $d = '254' . substr($d, 1);
    elseif (strlen($d) === 9 && ($d[0] === '7' || $d[0] === '1')) $d = '254' . $d;
    if (preg_match('/^254[17]\d{8}$/', $d)) return '+' . $d;
    if (preg_match('/^\d{10,15}$/', $d) && strpos($d, '254') !== 0) return '+' . $d; // foreign number, kept as-is
    return null;
}

function jt_email(?string $raw): ?string {
    $e = strtolower(trim((string) $raw));
    return ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : null;
}

/** Collapse spaces; fix ALL CAPS or all lowercase names to Title Case. */
function jt_name(?string $raw): ?string {
    $n = trim(preg_replace('/\s+/u', ' ', (string) $raw));
    if ($n === '') return null;
    $n = mb_substr($n, 0, 150);
    if ($n === mb_strtoupper($n) || $n === mb_strtolower($n)) $n = mb_convert_case(mb_strtolower($n), MB_CASE_TITLE, 'UTF-8');
    return $n;
}

function jt_text(?string $raw, int $max = 255): ?string {
    $t = trim(preg_replace('/\s+/u', ' ', (string) $raw));
    return $t === '' ? null : mb_substr($t, 0, $max);
}

/** Canonical county name, or null. $unmatched collects values the rules could not map. */
function jt_county(?string $raw, array $rules, array &$unmatched): ?string {
    $v = trim((string) $raw);
    if ($v === '') return null;
    $key = strtolower(preg_replace('/\s+county$/i', '', $v));
    $key = trim(str_replace(['’', '`'], "'", $key));
    foreach ($rules['counties'] ?? [] as $c) {
        if (strtolower($c) === $key || strtolower(str_replace(['-', "'"], [' ', ''], $c)) === str_replace(['-', "'"], [' ', ''], $key)) return $c;
    }
    $aliases = $rules['county_aliases'] ?? [];
    if (isset($aliases[$key])) return $aliases[$key];
    foreach ($aliases as $alias => $county) {           // e.g. "Thika town, Kiambu road" contains "thika"
        if (strlen($alias) >= 4 && preg_match('/\b' . preg_quote($alias, '/') . '\b/', $key)) return $county;
    }
    foreach ($rules['counties'] ?? [] as $c) {
        if (preg_match('/\b' . preg_quote(strtolower($c), '/') . '\b/', $key)) return $c;
    }
    $unmatched[] = ['county', $v];
    return null;
}

/**
 * Parse a free-text quantity ("300 t", "2 lorry loads", "50 m3", "1,200 tonnes per month") into tonnes.
 * Returns [tonnes|null, unit|null]. Units like "trees" are recognised but not converted.
 */
function jt_tonnes(?string $raw, array $rules, array &$unmatched, ?string $unitHint = null): array {
    $text = strtolower(trim((string) $raw));
    if ($text === '' && $unitHint === null) return [null, null];
    if (!preg_match('/(\d[\d,]*(?:\.\d+)?)/', $text, $m)) {
        if ($text !== '') $unmatched[] = ['volume', $raw];
        return [null, null];
    }
    $num = (float) str_replace(',', '', $m[1]);
    $rest = trim(substr($text, strpos($text, $m[1]) + strlen($m[1])));
    $unit = $unitHint !== null ? strtolower(trim($unitHint)) : $rest;
    foreach ($rules['non_tonnage_units'] ?? [] as $u) {
        if ($unit === $u || strpos($unit, $u) === 0) return [null, $u];
    }
    $units = $rules['units_to_tonnes'] ?? [];
    uksort($units, fn($a, $b) => strlen($b) <=> strlen($a));   // longest match first ("lorry loads" before "lorry")
    foreach ($units as $u => $factor) {
        if ($unit === $u || preg_match('/^' . preg_quote($u, '/') . '\b/u', $unit)) return [round($num * (float) $factor, 1), $u];
    }
    if ($unit === '' ) return [$num, 'assumed tonnes'];
    $unmatched[] = ['unit', mb_substr($unit, 0, 40)];
    return [null, null];
}

/** Lead score 0-100 from rules.scoring. */
function jt_score(array $f, array $rules): int {
    $s = $rules['scoring'] ?? [];
    $score = 0;
    if (!empty($f['phone'])) $score += (int) ($s['has_phone'] ?? 0);
    if (!empty($f['email'])) $score += (int) ($s['has_email'] ?? 0);
    if (!empty($f['company'])) $score += (int) ($s['has_company'] ?? 0);
    if (!empty($f['tonnes'])) {
        foreach ($s['volume_tonnes_bands'] ?? [] as [$min, $pts]) { if ($f['tonnes'] >= $min) { $score += (int) $pts; break; } }
    }
    if (!empty($f['county']) && in_array($f['county'], $rules['core_counties'] ?? [], true)) $score += (int) ($s['core_county'] ?? 0);
    if (($f['availability'] ?? '') === 'Now') $score += (int) ($s['available_now'] ?? 0);
    if (!empty($f['repeat'])) $score += (int) ($s['repeat_contact'] ?? 0);
    return min(100, $score);
}

/** Next action + due date for a new or returning contact. */
function jt_next_action(string $role, ?string $availability, array $rules): array {
    $fu = $rules['follow_up'] ?? [];
    $rule = $role === 'buyer' ? ($fu['buyer_new'] ?? null)
        : (($availability === 'Now') ? ($fu['supplier_now'] ?? null) : ($fu['supplier_new'] ?? null));
    $hours = (int) ($rule['hours'] ?? 48);
    return [$rule['action'] ?? 'Follow up', gmdate('Y-m-d H:i:s', time() + $hours * 3600)];
}

/** A value is safe for the GitHub report only if it doesn't look like personal data. */
function jt_report_safe(string $v): bool {
    return !preg_match('/@|\d{6,}|\+\d/', $v) && mb_strlen($v) <= 40;
}
