<?php
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/config.php';

$response = [
    'ok' => false,
    'rate' => null,
    'error' => null,
];

try {
    $base = isset($_GET['base']) ? strtoupper(trim($_GET['base'])) : '';
    $target = isset($_GET['target']) ? strtoupper(trim($_GET['target'])) : '';
    if ($base === '' || $target === '' || $base === $target) {
        throw new Exception('Invalid base/target');
    }

    $conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) {
        throw new Exception('DB connection failed');
    }

    // Find currency IDs
    $stmt = $conn->prepare("SELECT currency_id, symbol FROM currencies WHERE symbol IN (?, ?) ");
    $stmt->bind_param('ss', $base, $target);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) { $ids[$row['symbol']] = (int)$row['currency_id']; }
    $stmt->close();

    if (!isset($ids[$base]) || !isset($ids[$target])) {
        throw new Exception('Unknown currency symbol');
    }
    $base_id = $ids[$base];
    $target_id = $ids[$target];

    $rate = null;
    // 1) Try today's live rate from exchange_rates
    $stmt_live = $conn->prepare("SELECT rate FROM exchange_rates WHERE base_currency_id = ? AND target_currency_id = ? AND DATE(timestamp) = CURDATE() ORDER BY timestamp DESC LIMIT 1");
    if ($stmt_live) {
        $stmt_live->bind_param('ii', $base_id, $target_id);
        $stmt_live->execute();
        $live_res = $stmt_live->get_result();
        if ($row = $live_res->fetch_assoc()) { $rate = (float)$row['rate']; }
        $stmt_live->close();
    }

    // 2) Fallback to history base->target
    if (!$rate) {
        $stmt_hist = $conn->prepare("SELECT rate FROM exchange_rate_history WHERE base_currency_id = ? AND target_currency_id = ? ORDER BY timestamp DESC LIMIT 1");
        if ($stmt_hist) {
            $stmt_hist->bind_param('ii', $base_id, $target_id);
            $stmt_hist->execute();
            $hist_res = $stmt_hist->get_result();
            if ($row = $hist_res->fetch_assoc()) { $rate = (float)$row['rate']; }
            $stmt_hist->close();
        }
    }

    // 3) Fallback to inverse history target->base and invert
    if (!$rate) {
        $stmt_inv = $conn->prepare("SELECT rate FROM exchange_rate_history WHERE base_currency_id = ? AND target_currency_id = ? ORDER BY timestamp DESC LIMIT 1");
        if ($stmt_inv) {
            $stmt_inv->bind_param('ii', $target_id, $base_id);
            $stmt_inv->execute();
            $inv_res = $stmt_inv->get_result();
            if ($row = $inv_res->fetch_assoc()) {
                $inv = (float)$row['rate'];
                if ($inv > 0) { $rate = 1 / $inv; }
            }
            $stmt_inv->close();
        }
    }

    // 4) currencies.exchange_rate_to_usd inversion if applicable
    if (!$rate && $base === 'USD') {
        $stmt_cur = $conn->prepare("SELECT symbol, exchange_rate_to_usd FROM currencies WHERE symbol IN ('USD', ?) ");
        $stmt_cur->bind_param('s', $target);
        $stmt_cur->execute();
        $cur_res = $stmt_cur->get_result();
        $target_to_usd = null;
        while ($row = $cur_res->fetch_assoc()) {
            if ($row['symbol'] === $target) { $target_to_usd = (float)$row['exchange_rate_to_usd']; }
        }
        $stmt_cur->close();
        if ($target_to_usd && $target_to_usd > 0) { $rate = 1 / $target_to_usd; }
    }

    if ($rate && $rate > 0) {
        $response['ok'] = true;
        $response['rate'] = $rate;
    } else {
        throw new Exception('Rate not found');
    }
} catch (Throwable $e) {
    $response['ok'] = false;
    $response['error'] = $e->getMessage();
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
