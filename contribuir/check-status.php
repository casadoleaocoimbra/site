<?php
// check-status.php
header('Content-Type: application/json');
require_once 'generate-qr.php';

$paymentId = $_GET['payment_id'] ?? '';
$qr_code = $_GET['qr_code'] ?? false;
$file = __DIR__ . "/payments/{$paymentId}.json";

$response = [];

// Verificar se o ficheiro existe
// {"identifier":"ID_690dfbd02428c_Kids","created_at":"2025-11-07T14:03:59+00:00","updated_at":"2025-11-07T14:03:59+00:00","trid":"","entity":"10076","reference":"52597285","method":"MW:PT","amount":"5.00000","currency":"EUR","date":"2025-11-07T14:03:58"}
if ($paymentId && file_exists($file)) {
    $data = json_decode(file_get_contents($file), true);
    $status = $data['status'] ?? 'pending';
    $key = $data['key'] ?? '';
    
    $response = [
        'status' => $status,
        'messages' => $data['messages'] ?? '',
        'key' => $key,
        'qr_code_path' => $data['qr_code_path'] ?? null,
    ];
    $paid = in_array($status, ["success", "paid"]);
    // Generate QR code synchronously if key exists and QR doesn't exist yet
    if ($paid && $key && empty($data['qr_code_path']) && $qr_code) {
        try {
            $filePath = generateQRcodeKey($key, __DIR__ . "/payments/");
            
            // Update the payment file with QR code path
            $data['qr_code_path'] = $filePath;
            file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            // Update response with QR code URL
            $response['qr_code_path'] = '/contribuir/payments/' . basename($filePath);

            // Update json file with QR code URL
            $data['qr_code_path'] = '/contribuir/payments/' . basename($filePath);
            file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            
            error_log("QR code generated synchronously for payment: " . $paymentId);
        } catch (Exception $e) {
            error_log("QR code generation failed: " . $e->getMessage());
        }
    }

    echo json_encode($response);
} else {
    echo json_encode(['status' => 'unknown', 'messages' => 'Payment ID not found']);
}
exit;
?>