<?php

namespace KasperStuck\Alunta\Laravel\Http;

use Illuminate\Http\Client\PendingRequest;
use KasperStuck\Alunta\Sdk\ApiRequest;
use KasperStuck\Alunta\Sdk\PreparedCall;
use KasperStuck\Alunta\Sdk\TransportException;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Sends every attempt of one Alunta API call through the pending request LaravelHttpTransport
 * prepared for that call, as the old client reused its pending request for each retry.
 *
 * Each attempt is PendingRequest::send() with the path and the headers, query and json options,
 * so Laravel and Guzzle merge them with the global options, encode them and place the headers.
 *
 * Any failure of the attempt becomes a TransportException that keeps only the failure's class: the
 * HTTP client's own exception can carry the URL or the token, so it is never the previous
 * exception.
 */
final readonly class PendingRequestCall implements PreparedCall
{
    public function __construct(private PendingRequest $pendingRequest) {}

    public function send(ApiRequest $request): ResponseInterface
    {
        $options = [];

        if ($request->headers !== []) {
            $options['headers'] = $request->headers;
        }

        if ($request->query !== []) {
            $options['query'] = $request->query;
        }

        if ($request->json !== null) {
            $options['json'] = $request->json;
        }

        try {
            return $this->pendingRequest->send($request->method, $request->path, $options)->toPsrResponse();
        } catch (Throwable $exception) {
            throw new TransportException($request, $exception::class);
        }
    }
}
