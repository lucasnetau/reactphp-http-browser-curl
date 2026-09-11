<?php declare(strict_types=1);

namespace EdgeTelemetrics\React\Http\Tests;

use EdgeTelemetrics\React\Http\Browser;
use ReflectionMethod;
use ReflectionProperty;

class HttpVersionMappingTest extends \React\Tests\Http\TestCase
{
    /**
     * @dataProvider provideCurlHttpVersions
     */
    public function testHttpVersionMapping(int $curlHttpVersion, string $expected): void
    {
        $method = new ReflectionMethod(Browser::class, 'httpVersionName');

        $this->assertSame($expected, $method->invoke(null, $curlHttpVersion));
    }

    /**
     * Numeric values are used for the HTTP/2TLS, 2-prior-knowledge and HTTP/3 variants because
     * CURL_HTTP_VERSION_3 / _3ONLY do not exist before PHP 8.3.
     */
    public static function provideCurlHttpVersions(): array
    {
        return [
            'none' => [CURL_HTTP_VERSION_NONE, '1.0'],
            'http/1.0' => [CURL_HTTP_VERSION_1_0, '1.0'],
            'http/1.1' => [CURL_HTTP_VERSION_1_1, '1.1'],
            'http/2' => [CURL_HTTP_VERSION_2, '2'],
            'http/2 over tls' => [4, '2'],
            'http/2 prior knowledge' => [5, '2'],
            'http/3' => [30, '3'],
            'http/3 only' => [31, '3'],
            'unknown' => [99, '1.1'],
        ];
    }

    /**
     * @dataProvider provideProtocolVersions
     */
    public function testWithProtocolVersionMapsToCurlOption(string $protocolVersion, int $expected): void
    {
        $browser = (new Browser())->withProtocolVersion($protocolVersion);

        $property = new ReflectionProperty(Browser::class, 'httpVersion');

        $this->assertSame($expected, $property->getValue($browser));
    }

    /**
     * Unknown versions intentionally fall back to CURL_HTTP_VERSION_NONE so cURL negotiates.
     * HTTP/3 uses the numeric ABI value because CURL_HTTP_VERSION_3 needs PHP 8.3+.
     */
    public static function provideProtocolVersions(): array
    {
        return [
            'http/1.0' => ['1.0', CURL_HTTP_VERSION_1_0],
            'http/1.1' => ['1.1', CURL_HTTP_VERSION_1_1],
            'http/2' => ['2', CURL_HTTP_VERSION_2],
            'http/2 dotted' => ['2.0', CURL_HTTP_VERSION_2],
            'http/3' => ['3', defined('CURL_HTTP_VERSION_3') ? CURL_HTTP_VERSION_3 : 30],
            'http/3 dotted' => ['3.0', defined('CURL_HTTP_VERSION_3') ? CURL_HTTP_VERSION_3 : 30],
            'unknown' => ['9.9', CURL_HTTP_VERSION_NONE],
        ];
    }
}
