<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

class ProdamusService
{
    /**
     * Generate payment link for Prodamus.
     */
    public function generatePaymentLink(int $userId, string $orderId, float $sum): string
    {
        $domain = Config::getProdamusDomain();
        if (empty($domain)) {
            return '';
        }

        $params = [
            'do' => 'pay',
            'sum' => $sum,
            'order_id' => $orderId,
            'customer_extra' => (string) $userId,
            'products[0][name]' => 'Подписка Prime Glow',
            'products[0][price]' => $sum,
            'products[0][quantity]' => 1,
            // You can add more parameters here if needed
        ];

        // Many Prodamus integrations use a simple link. 
        // If the user wants a signature for the link itself, it usually requires a different API.
        // For standard "payment form" integration, the callback signature is the key security part.
        
        $queryString = http_build_query($params);
        return "https://{$domain}.payform.ru/?{$queryString}";
    }

    /**
     * Verify the signature of the incoming callback from Prodamus.
     * Prodamus uses a specific sorting and normalization algorithm for signatures.
     */
    public function verifySignature(array $data, string $receivedSignature): bool
    {
        $secret = Config::getProdamusSecret();
        if (empty($secret)) {
            return false;
        }

        // Strip "v2." prefix if present
        if (str_starts_with($receivedSignature, 'v2.')) {
            $receivedSignature = substr($receivedSignature, 3);
        }

        $dataToSign = $data;
        // 1. Convert all values to strings recursively
        array_walk_recursive($dataToSign, function(&$v) {
            $v = strval($v);
        });

        // 2. Sort keys recursively
        $this->recursiveSort($dataToSign);

        // 3. Encode to JSON with specific flags
        $json = json_encode($dataToSign, JSON_UNESCAPED_UNICODE);

        // 4. Calculate HMAC
        $expectedSignature = hash_hmac('sha256', $json, $secret);
        
        return hash_equals(strtolower($expectedSignature), strtolower($receivedSignature));
    }

    /**
     * Recursive ksort for signature calculation.
     */
    private function recursiveSort(array &$data): void
    {
        ksort($data, SORT_REGULAR);
        foreach ($data as &$arr) {
            if (is_array($arr)) {
                $this->recursiveSort($arr);
            }
        }
    }
}
