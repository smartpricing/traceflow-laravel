<?php

namespace Smartness\TraceFlow\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test helper utilities
 */
class TestHelper
{
    /**
     * Check if TraceFlow API server is available
     */
    public static function isServerAvailable(): bool
    {
        $endpoint = getenv('TRACEFLOW_URL') ?: 'http://localhost:3009';

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Server is available if we get any HTTP response (even 404)
        return $httpCode > 0;
    }

    /**
     * Skip test if server is not available
     */
    public static function skipIfServerUnavailable(TestCase $test): void
    {
        if (!self::isServerAvailable()) {
            $test->markTestSkipped(
                'TraceFlow API server is not available at ' .
                (getenv('TRACEFLOW_URL') ?: 'http://localhost:3009') .
                '. Start the server or set TRACEFLOW_URL to skip integration tests.'
            );
        }
    }

    /**
     * Get API endpoint from environment
     */
    public static function getEndpoint(): string
    {
        return getenv('TRACEFLOW_URL') ?: 'http://localhost:3009';
    }

    /**
     * Get API key from environment
     */
    public static function getApiKey(): ?string
    {
        return getenv('TRACEFLOW_API_KEY') ?: null;
    }

    /**
     * Check if we should run integration tests
     */
    public static function shouldRunIntegrationTests(): bool
    {
        $skipIntegration = getenv('SKIP_INTEGRATION_TESTS');

        if ($skipIntegration === 'true' || $skipIntegration === '1') {
            return false;
        }

        return self::isServerAvailable();
    }
}
