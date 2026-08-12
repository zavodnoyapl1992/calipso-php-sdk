<?php

declare(strict_types=1);

namespace Calipso\Sdk\Http;

final class CurlHttpClient implements HttpClientInterface
{
    public function send(HttpRequest $request): HttpResponse
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new HttpTransportException('HTTP transport could not be initialized.');
        }

        $responseHeaders = [];
        $headers = [];
        foreach ($request->headers() as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $configured = curl_setopt($handle, CURLOPT_URL, $request->url())
            && curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $request->method())
            && curl_setopt($handle, CURLOPT_HTTPHEADER, $headers)
            && curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body())
            && curl_setopt($handle, CURLOPT_RETURNTRANSFER, true)
            && curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false)
            && curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, self::milliseconds($request->connectTimeout()))
            && curl_setopt($handle, CURLOPT_TIMEOUT_MS, self::milliseconds($request->requestTimeout()))
            && curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS)
            && curl_setopt(
                $handle,
                CURLOPT_HEADERFUNCTION,
                static function ($curl, string $line) use (&$responseHeaders): int {
                    $length = strlen($line);
                    $separator = strpos($line, ':');
                    if ($separator !== false) {
                        $name = trim(substr($line, 0, $separator));
                        $value = trim(substr($line, $separator + 1));
                        if ($name !== '') {
                            $responseHeaders[$name] = $value;
                        }
                    }

                    return $length;
                }
            );

        if (!$configured) {
            curl_close($handle);

            throw new HttpTransportException('HTTP transport could not be configured.');
        }

        $body = curl_exec($handle);
        if (!is_string($body)) {
            curl_close($handle);

            throw new HttpTransportException('HTTP transport request failed.');
        }

        $statusCode = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($statusCode < 100) {
            throw new HttpTransportException('HTTP transport returned an invalid response.');
        }

        return new HttpResponse($statusCode, $responseHeaders, $body);
    }

    private static function milliseconds(float $seconds): int
    {
        return max(1, (int) ceil($seconds * 1000));
    }
}
