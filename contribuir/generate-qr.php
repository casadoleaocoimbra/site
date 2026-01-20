<?php
// Custom QR Code Generator - Using QR Server API

class SimpleQRCode {
    private $data;
    private $size;
    private $margin;
    
    public function __construct($data, $size = 300, $margin = 10) {
        $this->data = $data;
        $this->size = $size;
        $this->margin = $margin;
    }
    
    public function generatePNG($filePath) {
        // Try multiple QR code APIs as fallbacks
        $apis = [
            // API 1: QR Server
            'https://api.qrserver.com/v1/create-qr-code/?size=' . $this->size . 'x' . $this->size . '&data=' . urlencode($this->data),
            // API 2: QR Code Generator (backup)
            'https://qr-code-generator-new.herokuapp.com/api/qr/text/' . urlencode($this->data) . '?size=' . $this->size,
        ];
        
        $qrImage = false;
        $usedAPI = '';
        
        foreach ($apis as $index => $qrURL) {
            try {
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 15,
                        'user_agent' => 'Mozilla/5.0 (compatible; QR Generator/1.0)',
                        'method' => 'GET',
                        'ignore_errors' => true
                    ]
                ]);
                
                $qrImage = file_get_contents($qrURL, false, $context);
                
                if ($qrImage !== false && strlen($qrImage) > 100) {
                    $usedAPI = "API " . ($index + 1);
                    break;
                }
            } catch (Exception $e) {
                error_log("QR API " . ($index + 1) . " failed: " . $e->getMessage());
                continue;
            }
        }
        
        // If all APIs fail, create a simple text file as fallback
        if ($qrImage === false) {
            $textContent = "QR Code Data: " . $this->data . "\n";
            $textContent .= "Generated: " . date('Y-m-d H:i:s') . "\n";
            $textContent .= "Note: QR image generation failed, but payment data is preserved.\n";
            
            $textFilePath = str_replace('.png', '.txt', $filePath);
            file_put_contents($textFilePath, $textContent);
            
            error_log("QR image generation failed, created text file: " . $textFilePath);
            return $textFilePath;
        }
        
        // Ensure directory exists
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        // Save the image
        $result = file_put_contents($filePath, $qrImage);
        
        if ($result === false) {
            throw new Exception("Failed to save QR code to: " . $filePath);
        }
        
        error_log("QR code generated successfully using " . $usedAPI . ": " . $filePath);
        return $filePath;
    }
}

function generateQRcodeKey(string $content, string $directory): string
{
    if(preg_match('/\[(.*?)\]/', $content, $matches)){
        $code = $matches[1];
    } else {
        $code = 'without_code_' . time();
    }

    // Create QR code instance
    $qr = new SimpleQRCode($content, 300, 10);
    
    // Preparing the path
    $filePath = rtrim($directory, '/') . '/qr-' . $code . '.png';
    
    try {
        return $qr->generatePNG($filePath);
    } catch (Exception $e) {
        error_log("QR Code generation failed: " . $e->getMessage());
        throw $e;
    }
}
?>