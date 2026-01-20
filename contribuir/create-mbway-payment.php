<?php
// Ativar exibição de erros para debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Rate limiting - prevent rapid successive calls
session_start();
$current_time = time();
$rate_limit_window = 30; // 30 seconds between submissions
$max_attempts = 50; // Maximum attempts per hour per session

// Initialize session tracking
if (!isset($_SESSION['last_submission_time'])) {
    $_SESSION['last_submission_time'] = 0;
    $_SESSION['submission_count'] = 0;
    $_SESSION['hour_start'] = $current_time;
}

// Reset hourly counter
if ($current_time - $_SESSION['hour_start'] > 3600) {
    $_SESSION['submission_count'] = 0;
    $_SESSION['hour_start'] = $current_time;
}

// Check rate limits
if ($current_time - $_SESSION['last_submission_time'] < $rate_limit_window) {
    http_response_code(429);
    echo json_encode([
        "success" => false,
        "error" => "Aguarde " . ($rate_limit_window - ($current_time - $_SESSION['last_submission_time'])) . " segundos antes de tentar novamente.",
        "retry_after" => $rate_limit_window - ($current_time - $_SESSION['last_submission_time'])
    ]);
    exit;
}

if ($_SESSION['submission_count'] >= $max_attempts) {
    http_response_code(429);
    echo json_encode([
        "success" => false,
        "error" => "Limite de tentativas excedido. Tente novamente em um minuto.",
        "retry_after" => 3600 - ($current_time - $_SESSION['hour_start'])
    ]);
    exit;
}

$env_path = '../../.env';
$env = parse_ini_file($env_path);

if (!$env) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Não foi possível ler o ficheiro de configuração .env",
        "env_path" => $env_path
    ]);
    exit;
}

// Configurações Easypay API 2.0
$ACCOUNT_ID = $env['ACCOUNT_ID'];
$API_KEY    = $env['API_KEY'];
$EASYPAY_URL = $env['EASYPAY_URL'] ?? "https://api.test.easypay.pt";

// ==============================
// LER INPUT JSON
// ==============================
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

$name = trim($data['name'] ?? '');
$phone = preg_replace('/[^0-9]/', '', $data['phone'] ?? '');
$amount = floatval($data['amount'] ?? 0);
$email = filter_var($data['email'] && !empty($data['email']) ? $data['email'] : 'cliente@casadoleaocoimbra.pt', FILTER_VALIDATE_EMAIL);

// Validar JSON e campos obrigatórios
if (!$data || !isset($data['name'], $data['phone'], $data['amount'])) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "error" => "JSON inválido ou campos obrigatórios em falta.",
        "raw_input" => $raw
    ]);
    exit;
}
if (!$name || !preg_match('/^9[1236][0-9]{7}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['error' => 'Dados inválidos']);
    exit;
}
if ($amount < 5.00 || $amount > 5000.00) {
    http_response_code(400);
    echo json_encode(['error' => 'Valor fora dos limites']);
    exit;
}

// ==============================
// PREPARAR PAYLOAD PARA Easypay
// ==============================
$name        = trim($data['name']);
$phone       = trim($data['phone']);
$amount      = floatval($data['amount']); // em euros
$description = $data['description'] ? "CDLC: " . $data['description'] : "CDLC: Pagamento MB WAY";
$key         = "[" . uniqid() . "] " . $description;
$transaction_key = "tx_" . uniqid();

// Check for duplicate submission based on recent transactions
$duplicate_check_file = __DIR__ . "/recent_transactions.json";
$recent_transactions = [];
if (file_exists($duplicate_check_file)) {
    $recent_transactions = json_decode(file_get_contents($duplicate_check_file), true) ?: [];
}

// Clean old transactions (older than 5 minutes)
$five_minutes_ago = time() - 300;
$recent_transactions = array_filter($recent_transactions, function($tx) use ($five_minutes_ago) {
    return $tx['timestamp'] > $five_minutes_ago;
});

// Check for duplicate (same phone, amount, and description within 5 minutes)
$transaction_hash = md5($phone . $amount . $description);
foreach ($recent_transactions as $tx) {
    if ($tx['hash'] === $transaction_hash) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "error" => "Transação duplicada detectada. Aguarde 5 minutos antes de enviar o mesmo pagamento.",
            "duplicate_detected" => true
        ]);
        exit;
    }
}

$payload = [
    "currency" => "EUR",
    "customer" => [
        "name" => $name,
        "email" => $email,
        "phone" => $phone,
        "phone_indicative" => "+351"
    ],
    "key" => $key,
    "value" => $amount,
    "method" => "mbw",
    "type" => "sale",
    "capture" => [
        "descriptive" => $description,
        "transaction_key" => $transaction_key
    ]
];

// ==============================
// ENVIAR PARA Easypay
// ==============================
// Update session tracking before making external call
$_SESSION['last_submission_time'] = $current_time;
$_SESSION['submission_count']++;

// Add transaction to recent transactions before API call
$recent_transactions[] = [
    'hash' => $transaction_hash,
    'timestamp' => $current_time,
    'phone' => substr($phone, -4), // Only store last 4 digits for privacy
    'amount' => $amount
];
file_put_contents($duplicate_check_file, json_encode($recent_transactions));

$ch = curl_init($EASYPAY_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30, // Add timeout to prevent hanging
    CURLOPT_CONNECTTIMEOUT => 10, // Connection timeout
    CURLOPT_HTTPHEADER => [
        "AccountId: $ACCOUNT_ID",
        "ApiKey: $API_KEY",
        "Content-Type: application/json"
    ]
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// ==============================
// TRATAR RESPOSTA
// ==============================
if ($error) {
    echo json_encode([
        "success" => false,
        "error" => "Erro cURL: " . $error
    ]);
    exit;
}

if ($http_code >= 400) {
    echo json_encode([
        "success" => false,
        "http_code" => $http_code,
        "error" => "Erro na API Easypay",
        "payload_sent" => $payload,
        "raw_response" => $response
    ]);
    exit;
}

$data = json_decode($response, true);

// guardar id localmente (ex: ficheiro)
if (!empty($data["id"])) {
    file_put_contents(__DIR__ . "/payments/{$data['id']}.json", json_encode(["status" => "pending", "key" => $key]));
}

// ==============================
// OK ✅
// ==============================
echo json_encode([
    "success" => true,
    "http_code" => $http_code,
    "payload_sent" => $payload,
    "payment_id" => $data['id'],
    "transaction_key" => $transaction_key,
    "response" => $data
]);
?>
