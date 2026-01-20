<?php
// webhook-eupago.php
header('Content-Type: application/json');

// Read raw POST body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Extract transaction info
$transaction = $data['transaction'] ?? null;

if ($transaction) {

    // Get key fields
    $identifier = $transaction['identifier'] ?? null;
    $status = strtolower($transaction['status'] ?? '');
    $trid = $transaction['trid'] ?? null;
    $entity = $transaction['entity'] ?? null;
    $reference = $transaction['reference'] ?? null;
    $method = $transaction['method'] ?? null;
    $amount = $transaction['amount']['value'] ?? null;
    $currency = $transaction['amount']['currency'] ?? null;
    $date = $transaction['date'] ?? null;

    if ($identifier && $status) {
        $file = __DIR__ . "/payments/{$identifier}.json";

        if (file_exists($file)) {
            $paymentData = json_decode(file_get_contents($file), true);

            // Update status
            $paymentData['status'] = $status;
        } else {
            $paymentData = [
                'identifier' => $identifier,
                'status' => $status,
                'created_at' => date('c'),
            ];
        }

        // Add transaction details
        $paymentData['updated_at'] = date('c');
        $paymentData['trid'] = $trid;
        $paymentData['entity'] = $entity;
        $paymentData['reference'] = $reference;
        $paymentData['method'] = $method;
        $paymentData['amount'] = $amount;
        $paymentData['currency'] = $currency;
        $paymentData['date'] = $date;

        file_put_contents($file, json_encode($paymentData));
    }
} else {
    // Log error if no transaction data found
    error_log("No transaction data found: " . print_r($data, true));
    http_response_code(400);
    echo json_encode(['error' => 'No transaction data found']);
    exit;
}


// Optionally clean old files
include __DIR__ . '/prune-payments-files.php';

http_response_code(200);
echo json_encode(['ok' => true]);
exit;
