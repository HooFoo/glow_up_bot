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
     * Prodamus sends the signature in the 'Sign' header.
     * The signature is HMAC-SHA256 of the raw POST body.
     */
    public function verifySignature(string $rawBody, string $receivedSignature): bool
    {
        $secret = Config::getProdamusSecret();
        if (empty($secret)) {
            return false;
        }

        // Prodamus uses HMAC-SHA256
        $expectedSignature = hash_hmac('sha256', $rawBody, $secret);
        
        return hash_equals($expectedSignature, $receivedSignature);
    }
}
