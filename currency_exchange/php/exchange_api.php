<?php
/**
 * Exchange Rate Helper: fetches live rates and applies markup rules.
 * - Settings file: php/rate_settings.json
 * - Cache file: php/exchange_rates_cache.json
 * - Rate limit file: php/rate_limit.json
 */

define('RATES_CACHE_FILE', __DIR__ . '/exchange_rates_cache.json');
define('RATES_CACHE_TIME', 24 * 60 * 60); // 24 hours in seconds
define('RATE_LIMIT_FILE', __DIR__ . '/rate_limit.json');

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

function update_rate_limits() {
    $rate_limit = file_exists(RATE_LIMIT_FILE) 
        ? json_decode(file_get_contents(RATE_LIMIT_FILE), true) 
        : ['last_request' => 0, 'count' => 0];
    
    // Reset counter if it's been more than an hour
    if (time() - $rate_limit['last_request'] > 3600) {
        $rate_limit = ['last_request' => time(), 'count' => 0];
    }
    
    // Check rate limit (1000 requests/hour)
    if ($rate_limit['count'] >= 1000) {
        error_log("API rate limit reached");
        return false;
    }
    
    // Update rate limit
    $rate_limit['count']++;
    $rate_limit['last_request'] = time();
    file_put_contents(RATE_LIMIT_FILE, json_encode($rate_limit));
    
    return true;
}

function get_cached_rate($base, $target) {
    if (!file_exists(RATES_CACHE_FILE)) {
        return null;
    }
    
    $cached_data = json_decode(file_get_contents(RATES_CACHE_FILE), true);
    if (!$cached_data || !isset($cached_data['timestamp']) || !isset($cached_data['rates'])) {
        return null;
    }
    
    // Check if cache is still valid
    if (time() - $cached_data['timestamp'] < RATES_CACHE_TIME) {
        $cache_key = "{$base}_{$target}";
        return $cached_data['rates'][$cache_key] ?? null;
    }
    
    return null;
}

function update_rate_cache($base, $target, $rate) {
    $cached_data = [];
    if (file_exists(RATES_CACHE_FILE)) {
        $cached_data = json_decode(file_get_contents(RATES_CACHE_FILE), true) ?: [];
    }
    
    $cached_data['timestamp'] = time();
    $cached_data['rates'] = $cached_data['rates'] ?? [];
    $cached_data['rates']["{$base}_{$target}"] = $rate;
    
    return file_put_contents(RATES_CACHE_FILE, json_encode($cached_data, JSON_PRETTY_PRINT)) !== false;
}

function get_daily_rate($base, $target) {
    $base = strtoupper(trim($base));
    $target = strtoupper(trim($target));
    
    if ($base === $target) {
        return 1.0;
    }
    
    // Try to get from cache first
    $cached_rate = get_cached_rate($base, $target);
    if ($cached_rate !== null) {
        return $cached_rate;
    }
    
    // If not in cache or expired, fetch live rate
    $live_rate = er_fetch_live_rate($base, $target);
    
    if ($live_rate !== null) {
        // Update cache with the new rate
        update_rate_cache($base, $target, $live_rate);
        return $live_rate;
    }
    
    // If we couldn't get a new rate, try to return the last known rate even if expired
    if (file_exists(RATES_CACHE_FILE)) {
        $cached_data = json_decode(file_get_contents(RATES_CACHE_FILE), true);
        $cache_key = "{$base}_{$target}";
        if (isset($cached_data['rates'][$cache_key])) {
            return $cached_data['rates'][$cache_key];
        }
    }
    
    return null;
}

function update_all_rates() {
    $pairs = [
        ['USD', 'MMK'],
        ['SGD', 'MMK'],
        ['EUR', 'MMK'],
        ['THB', 'MMK'],
        ['CNY', 'MMK']
    ];
    
    $results = [];
    $success = true;
    
    foreach ($pairs as $pair) {
        $rate = er_fetch_live_rate($pair[0], $pair[1]);
        if ($rate !== null) {
            update_rate_cache($pair[0], $pair[1], $rate);
            $results["{$pair[0]}_{$pair[1]}"] = $rate;
        } else {
            $success = false;
            error_log("Failed to update rate for {$pair[0]}/{$pair[1]}");
        }
        
        // Be nice to the API
        if (count($pairs) > 1) {
            sleep(1);
        }
    }
    
    return [
        'success' => $success,
        'updated' => count($results),
        'rates' => $results
    ];
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

    // Check rate limits before making API calls
    if (!update_rate_limits()) {
        error_log("Rate limit exceeded for {$base}/{$target}");
        return null;
    }

    // Try multiple APIs in order of preference
    $apis = [
        // API 1: exchangerate-api.com (free, no key needed for basic)
        [
            'url' => "https://open.er-api.com/v6/latest/" . urlencode($base),
            'format' => 'er_api'
        ],
        // API 2: frankfurter.app (free, reliable)
        [
            'url' => "https://api.frankfurter.app/latest?from=" . urlencode($base) . "&to=" . urlencode($target),
            'format' => 'frankfurter'
        ],
        // API 3: exchangerate.host (backup)
        [
            'url' => "https://api.exchangerate.host/convert?from=" . urlencode($base) . "&to=" . urlencode($target),
            'format' => 'exchangerate_host'
        ]
    ];

    foreach ($apis as $api) {
        $url = $api['url'];
        $format = $api['format'];
        
        error_log("Trying API: $url");
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
        if ($format === 'er_api' && isset($data['rates'][$target])) {
            // exchangerate-api.com format
            $rate = floatval($data['rates'][$target]);
            if ($rate > 0) {
                return $rate;
            }
        } elseif ($format === 'frankfurter' && isset($data['rates'][$target])) {
            // frankfurter.app format
            $rate = floatval($data['rates'][$target]);
            if ($rate > 0) {
                return $rate;
            }
        } elseif ($format === 'exchangerate_host' && isset($data['result'])) {
            // exchangerate.host format
            $rate = floatval($data['result']);
            if ($rate > 0) {
                return $rate;
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
