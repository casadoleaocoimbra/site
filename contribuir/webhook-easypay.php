<?php
// webhook-easypay.php
header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$event = json_decode($raw, true);

$paymentId = $event['id'] ?? null;
$status = strtolower($event['status'] ?? '');

if ($paymentId && $status) {
    $file = __DIR__ . "/payments/{$paymentId}.json";
    if (file_exists($file)) {
        // Read existing payment data
        $paymentData = json_decode(file_get_contents($file), true);
        // Update the status in the local file
        $paymentData['status'] = $status;
        // Optionally, append messages if provided
        if (!empty($event["messages"])) {
            if (!isset($paymentData['messages'])) {
                $paymentData['messages'] = [];
            }
            if (is_array($event["messages"])) {
                $paymentData['messages'] = array_merge($paymentData['messages'], $event["messages"]);
            } else {
                $paymentData['messages'][] = $event["messages"];
            }
        }
        // Save updated data back to the file
        file_put_contents($file, json_encode($paymentData));
    } else {
        // If file doesn't exist, create it with status and messages
        // Ensure messages is a string for consistency
        // Get the messages array as string
        $messages = is_array($event["messages"]) ? implode("; ", $event["messages"]) : ($event["messages"] ?? '');
        // Update the local file with the new status and messages
        file_put_contents($file, json_encode(["status" => $status, "messages" => $messages]) );
    }
}
// Call prune-payments-files.php to clean up old files
include __DIR__ . '/prune-payments-files.php';

http_response_code(200);
echo json_encode(['ok' => true]);
exit;
?>
