<?php

namespace KasperStuck\Alunta\Laravel\Http;

use Illuminate\Support\Facades\Http;
use KasperStuck\Alunta\Laravel\AluntaApiClient;
use KasperStuck\Alunta\Sdk\AluntaClient;
use KasperStuck\Alunta\Sdk\Credentials;
use KasperStuck\Alunta\Sdk\PreparedCall;
use KasperStuck\Alunta\Sdk\Transport;
use SensitiveParameter;

/**
 * Prepares one Laravel pending request for each Alunta API call, resolved through the Http facade
 * at call time, so Http::fake, the request recorder, preventStrayRequests(), Http::globalOptions()
 * and global middleware apply as to any Laravel request. It is the old client's setup: the API URL,
 * the token through withToken(), no redirects, a JSON Accept header, CONNECT_TIMEOUT_SECONDS and
 * the clamped services.alunta.timeout, read now.
 *
 * Http::globalOptions() is evaluated here, once per call. The SDK calls this outside its error
 * handling, so an exception from the setup, of any class, escapes the call unchanged and is not
 * logged. Every attempt of the call is sent through the returned PendingRequestCall.
 */
final class LaravelHttpTransport implements Transport
{
    public function prepareCall(#[SensitiveParameter] Credentials $credentials): PreparedCall
    {
        return new PendingRequestCall(Http::baseUrl($credentials->baseUrl)
            ->withToken($credentials->token)
            ->withoutRedirecting()
            ->acceptJson()
            ->connectTimeout(AluntaClient::CONNECT_TIMEOUT_SECONDS)
            ->timeout(AluntaApiClient::timeoutSeconds()));
    }
}
