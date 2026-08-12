<?php

declare(strict_types=1);

namespace Calipso\Sdk\Http;

interface HttpClientInterface
{
    public function send(HttpRequest $request): HttpResponse;
}
