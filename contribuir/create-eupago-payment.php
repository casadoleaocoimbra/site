<?php
// Ativar exibição de erros para debug
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Rate limiting - prevenir chamadas sucessivas rápidas
session_start();
$current_time = time();
$rate_limit_window = 30; // 30 segundos entre submissões
$max_attempts = 50; // Máximo de tentativas por hora por sessão

// Inicializar tracking da sessão
if (!isset($_SESSION['last_submission_time'])) {
    $_SESSION['last_submission_time'] = 0;
    $_SESSION['submission_count'] = 0;
    $_SESSION['hour_start'] = $current_time;
}

// Reset do contador horário
if ($current_time - $_SESSION['hour_start'] > 3600) {
    $_SESSION['submission_count'] = 0;
    $_SESSION['hour_start'] = $current_time;
}

// Verificar rate limits
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
        "error" => "Limite de tentativas excedido. Tente novamente mais tarde.",
        "retry_after" => 3600 - ($current_time - $_SESSION['hour_start'])
    ]);
    exit;
}

// Ler ficheiro .env (mesma lógica do original)
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

// Configurações EuPago
$EUPAGO_URL = $env['EUPAGO_URL'] ?? "https://sandbox.eupago.pt/api/v1.02/mbway/create";
$AUTHORIZATION_HEADER = "ApiKey " . ($env['EUPAGO_API_KEY'] ?? "demo-0be0-21ba-4bc8-6bb"); // pode substituir no .env

// ==============================
// LER INPUT JSON
// ==============================
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

// Campos esperados do front-end (mantendo compatibilidade com o original)
$name = trim($data['name'] ?? '');
$phone_raw = $data['phone'] ?? '';
$phone = preg_replace('/[^0-9]/', '', $phone_raw);
$amount = floatval($data['amount'] ?? 0);
$email = isset($data['email']) && !empty($data['email']) ? filter_var($data['email'], FILTER_VALIDATE_EMAIL) : 'cliente@casadoleaocoimbra.pt';
$description = isset($data['description']) && !empty($data['description']) ? $data['description'] : "Pagamento MB WAY";

// Converter descrição para acronimo simples (sem acentos e espaços)
$short_description = iconv('UTF-8', 'ASCII//TRANSLIT', $description);
$short_description = preg_replace('/[^A-Za-z0-9]/', '', $short_description);

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

// Validar nome e telefone (PT mobile)
if (!$name || !preg_match('/^9[1236][0-9]{7}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Dados inválidos (nome ou telemóvel).']);
    exit;
}

// Validar valor (mantendo limites do original)
if ($amount < 5.00 || $amount > 5000.00) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valor fora dos limites (mín: 5.00, máx: 5000.00).']);
    exit;
}

// ==============================
// PREPARAR PAYLOAD PARA EuPago
// ==============================
$transaction_key = "tx_" . uniqid();
$identifier = "ID_" . uniqid() . "_" . $short_description; // identificador que enviamos para EuPago; pode usar $transaction_key se preferir

// Duplicate-check (com mesmo telefone + valor + descrição em 5 minutos)
$duplicate_check_file = __DIR__ . "/recent_transactions.json";
$recent_transactions = [];
if (file_exists($duplicate_check_file)) {
    $recent_transactions = json_decode(file_get_contents($duplicate_check_file), true) ?: [];
}

// Limpar transações antigas (mais velhas que 5 minutos)
$five_minutes_ago = time() - 300;
$recent_transactions = array_filter($recent_transactions, function ($tx) use ($five_minutes_ago) {
    return $tx['timestamp'] > $five_minutes_ago;
});

// Hash para detecção de duplicados
$transaction_hash = md5($phone . number_format($amount, 2, '.', '') . $description);
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

// Preparar body conforme especificação que forneceu:
$body = [
    "payment" => [
        "identifier" => $identifier,
        "amount" => [
            // Algumas APIs esperam string; mantemos número com 2 decimais (valor em euros)
            "value" => number_format($amount, 2, '.', ''),
            "currency" => "EUR"
        ],
        "customerPhone" => $phone,
        "countryCode" => "+351"
    ],
    "customer" => [
        // incluir notificações se desejar; deixamos notify=false por defeito
        "notify" => false,
        "failOver" => "0",
        "name" => $name,
        "email" => $email,
        "phone" => $phone
    ]
];

// ==============================
// REGISTAR E ENVIAR
// ==============================
// Atualizar tracking da sessão ANTES de chamada externa
$_SESSION['last_submission_time'] = $current_time;
$_SESSION['submission_count']++;

// Adicionar transação aos recentes antes da chamada (evita double clicks)
$recent_transactions[] = [
    'hash' => $transaction_hash,
    'timestamp' => $current_time,
    'phone' => substr($phone, -4), // só últimos 4 dígitos por privacidade
    'amount' => number_format($amount, 2, '.', '')
];
file_put_contents($duplicate_check_file, json_encode(array_values($recent_transactions)));

// Fazer cURL para EuPago
$ch = curl_init($EUPAGO_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        "Authorization: {$AUTHORIZATION_HEADER}",
        "Accept: application/json",
        "Content-Type: application/json"
    ]
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// ==============================
// TRATAR RESPOSTA
// {"success":true,"http_code":201,"payload_sent":{"payment":{"identifier":"ID_690ddf8d65252","amount":{"value":"5.00","currency":"EUR"},"customerPhone":"917818456","countryCode":"+351"},"customer":{"notify":false,"failOver":"0","name":"Magnus","email":"magnuscruz@gmail.com","phone":"917818456"}},"payment_identifier":"ID_690ddf8d65252","transaction_key":"tx_690ddf8d65249","eupago_response_raw":"{\"transactionStatus\":\"Success\",\"transactionID\":\"019a5e31415d7f4e8bce05a5451460e5\",\"reference\":\"52587135\"}","eupago_response":{"transactionStatus":"Success","transactionID":"019a5e31415d7f4e8bce05a5451460e5","reference":"52587135"}}
// ==============================
if ($error) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Erro cURL: " . $error
    ]);
    exit;
}

// Se EuPago devolve >=400 tratamos como erro e mostramos detalhes para debug
if ($http_code >= 400) {
    http_response_code($http_code);
    echo json_encode([
        "success" => false,
        "http_code" => $http_code,
        "error" => "Erro na API EuPago",
        "payload_sent" => $body,
        "raw_response" => $response
    ]);
    exit;
}

// Tentar decodificar resposta
$resp_data = json_decode($response, true);

// Guardar id localmente se existir (mantemos mesma pasta payments/)
if (!empty($resp_data["payment_id"])) {
    if (!is_dir(__DIR__ . "/payments")) {
        mkdir(__DIR__ . "/payments", 0755, true);
    }
    file_put_contents(__DIR__ . "/payments/{$resp_data['payment_id']}.json", json_encode([
        "status" => $resp_data['status'] ?? 'pending',
        "key" => $transaction_key,
        "payload_sent" => $body,
        "created_at" => date('c')
    ]));

    // Log de sucesso
    error_log("Pagamento criado com sucesso: " . $resp_data["payment_id"]);
}

// Resposta final OK
http_response_code(200);
echo json_encode([
    "success" => true,
    "http_code" => $http_code,
    "payload_sent" => $body,
    "payment_id" => $identifier,
    "transaction_key" => $transaction_key,
    "eupago_response_raw" => $response,
    "eupago_response" => $resp_data
]);
exit;
