<?php

namespace KasperStuck\Alunta\Laravel;

use KasperStuck\Alunta\Sdk\Credentials;
use KasperStuck\Alunta\Sdk\CredentialsProvider;

/**
 * Reads services.alunta on every call: Alunta must be enabled, with a token and an http(s) API URL.
 */
final class ConfigCredentialsProvider implements CredentialsProvider
{
    public function credentials(): ?Credentials
    {
        $token = config('services.alunta.token');
        $baseUrl = AluntaApiClient::configuredBaseUrl();

        if (config('services.alunta.enabled') !== true || ! is_string($token) || $token === '' || $baseUrl === null) {
            return null;
        }

        return new Credentials($baseUrl, $token);
    }
}
