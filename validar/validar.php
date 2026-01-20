<?php
// Iniciar sessão para armazenar o resultado da validação no ficheiro dataset.csv
session_start();

// Ler o dataset CSV
$datasetFile = __DIR__ . '/dataset.csv';
if (!file_exists($datasetFile)) {
    $_SESSION['mensagem'] = 'Erro: Dataset não encontrado.';
    // Enviar para página que fez a requisição
    echo 'Erro: Dataset não encontrado.';
    exit;
}

// Verificar se o código foi enviado
if (isset($_GET['code'])) {
    $codigo = $_GET['code'];

    // Parse o código se necessário (Ex. [68ebfdf1639dc] CDLC: CENTRAL3-Adulto (16+))
    if (preg_match('/^\[(.+?)\] (.+)$/', $codigo, $matches)) {
        $codigo = $matches[1];
        $descricao = $matches[2];
    }

    $dataset = array_map('str_getcsv', file($datasetFile));
    array_shift($dataset); // Remover cabeçalho
    $valido = false;

    // Verificar se o código está no dataset
    foreach ($dataset as &$row) {
        if (trim($row[0]) === trim($codigo)) {
            $valido = true;
            if (trim($row[2]) === 'Sim') {
                $_SESSION['mensagem'] = "Aviso: Código já foi validado anteriormente: {$codigo} - {$descricao}";
                echo $_SESSION['mensagem'];
                exit;
            }
            $row[2] = 'Sim'; // Marcar como validado
            break;
        }
    }
    unset($row);

    // Salvar o dataset atualizado
    $fp = fopen($datasetFile, 'w');
    fputcsv($fp, ['Key', 'Descrição', 'Validado']); // Cabeçalho
    foreach ($dataset as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    // Definir mensagem de resultado
    if ($valido) {
        $_SESSION['mensagem'] = "Sucesso: Código válido: {$codigo} - {$descricao}";
    } else {
        $_SESSION['mensagem'] = "Erro: Código inválido: {$codigo}";
    }
} else {
    $_SESSION['mensagem'] = 'Erro: Nenhum código fornecido para validação.';
}

// Enviar para página html o resultado
echo $_SESSION['mensagem'];
exit;
?>