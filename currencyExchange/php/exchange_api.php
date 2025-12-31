<?php
/**
 * Exchange Rate Helper: fetches live rates and applies markup rules.
 * - Settings file: php/rate_settings.json
 * - Free API used: https://api.exchangerate.host/convert
 */

function er_load_settings($path = __DIR__ . '/rate_settings.json') {
    if (!file_exists($path)) {
        return [
            'global' => ['mode' => 'percent', 'value' => 0],
            'targets' => []
        ];
    }
    $json = file_get_contents($path);
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return [
            'global' => ['mode' => 'percent', 'value' => 0],
            'targets' => []
        ];
    }
    // sanitize
    $data['global']['mode'] = isset($data['global']['mode']) ? $data['global']['mode'] : 'percent';
    $data['global']['value'] = isset($data['global']['value']) ? floatval($data['global']['value']) : 0.0;
    $data['targets'] = isset($data['targets']) && is_array($data['targets']) ? $data['targets'] : [];
    return $data;
}

function er_save_settings($settings, $path = __DIR__ . '/rate_settings.json') {
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    return file_put_contents($path, $json) !== false;
}

function er_fetch_live_rate($base, $target, $timeout = 10) {
    $base = strtoupper(trim($base));
    $target = strtoupper(trim($target));
    if ($base === $target) return 1.0;
    
    // Block fetching for specific pairs (multi-currencies feature)
    $blocked_pairs = [
        ['USD', 'JPY'],
        ['JPY', 'USD'],
        ['USD', 'THB'],
        ['THB', 'USD']
    ];
    foreach ($blocked_pairs as $pair) {
        if (($base === $pair[0] && $target === $pair[1]) || ($base === $pair[1] && $target === $pair[0])) {
            error_log("Blocked rate fetch for: $base/$target");
            return null;
        }
    }

    // Try multiple APIs in order of preference
    $apis = [
        // API 1: exchangerate-api.com (free, no key needed for basic)
        "https://open.er-api.com/v6/latest/" . urlencode($base),
        // API 2: frankfurter.app (free, reliable)
        "https://api.frankfurter.app/latest?from=" . urlencode($base) . "&to=" . urlencode($target),
        // API 3: exchangerate.host (backup)
        "https://api.exchangerate.host/convert?from=" . urlencode($base) . "&to=" . urlencode($target)
    ];

    foreach ($apis as $index => $url) {
        // Prefer curl if available
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local dev
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $resp = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => $timeout,
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            $resp = @file_get_contents($url, false, $ctx);
            $http_code = 200; // Assume success if we got response
        }

        if (!$resp) continue;
        
        $data = json_decode($resp, true);
        if (!$data) continue;

        // Parse response based on API format
        if ($index === 0) {
            // exchangerate-api.com format
            if (isset($data['rates'][$target])) {
                $rate = floatval($data['rates'][$target]);
                if ($rate > 0) return $rate;
            }
        } elseif ($index === 1) {
            // frankfurter.app format
            if (isset($data['rates'][$target])) {
                $rate = floatval($data['rates'][$target]);
                if ($rate > 0) return $rate;
            }
        } elseif ($index === 2) {
            // exchangerate.host format
            if (isset($data['result'])) {
                $rate = floatval($data['result']);
                if ($rate > 0) return $rate;
            }
        }
    }

    return null; // All APIs failed
}

function er_apply_markup($rate, $base, $target, $settings) {
    $base = strtoupper($base);
    $target = strtoupper($target);
    $effective = $rate;

    // Target-specific override first
    if (isset($settings['targets'][$target])) {
        $t = $settings['targets'][$target];
        $mode = isset($t['mode']) ? $t['mode'] : 'percent';
        $value = isset($t['value']) ? floatval($t['value']) : 0.0;
        if ($mode === 'percent') {
            $effective = $rate * (1.0 + ($value / 100.0));
        } elseif ($mode === 'absolute') {
            $effective = $rate + $value;
        }
        return $effective;
    }

    // Global fallback
    $mode = isset($settings['global']['mode']) ? $settings['global']['mode'] : 'percent';
    $value = isset($settings['global']['value']) ? floatval($settings['global']['value']) : 0.0;
    if ($mode === 'percent') {
        $effective = $rate * (1.0 + ($value / 100.0));
    } elseif ($mode === 'absolute') {
        $effective = $rate + $value;
    }
    return $effective;
}

/**
 * Get effective rate (live + markup). Returns [live, effective].
 */
function er_get_effective_rate($base, $target) {
    $live = er_fetch_live_rate($base, $target);
    if ($live === null) return [null, null];
    $settings = er_load_settings();
    $eff = er_apply_markup($live, $base, $target, $settings);
    return [$live, $eff];
}
