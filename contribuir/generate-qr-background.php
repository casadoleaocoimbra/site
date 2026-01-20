<?php
require_once 'generate-qr.php';

$key = $argv[1] ?? '';
$paymentId = $argv[2] ?? '';

if ($key && $paymentId) {
    try {
        // Gera o QR code e salva o arquivo
        $filePath = generateQRcodeKey($key, __DIR__ . "/payments/");

        // Atualiza o arquivo JSON do pagamento com o caminho do QR
        $paymentFile = __DIR__ . "/payments/{$paymentId}.json";
        if (file_exists($paymentFile)) {
            $data = json_decode(file_get_contents($paymentFile), true);
            $data['qr_code_path'] = $filePath;
            file_put_contents($paymentFile, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
        
        // Log success
        error_log("QR code generated successfully for payment: " . $paymentId);
    } catch (Exception $e) {
        // Log error but don't break the payment process
        error_log("QR code generation failed: " . $e->getMessage());
    }
}
exit;
?>