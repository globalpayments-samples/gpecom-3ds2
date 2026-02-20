<?php

declare(strict_types=1);

namespace GpecomSdk;

class ApiResponse
{
    /**
     * Send a success JSON response.
     */
    public static function success(array $data, string $message = 'Success'): void
    {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Send an error JSON response.
     */
    public static function error(string $message, int $httpCode = 400, array $details = []): void
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        $response = [
            'success' => false,
            'message' => $message,
        ];
        if (!empty($details)) {
            $response['details'] = $details;
        }
        echo json_encode($response, JSON_UNESCAPED_SLASHES);
        exit;
    }
}
