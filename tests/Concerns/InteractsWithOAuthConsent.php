<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

trait InteractsWithOAuthConsent
{
    /** @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function withConsentOffer(string $url, array $params): array
    {
        $params += ['resource' => 'http://localhost/mcp/statamic'];
        $response = $this->get($url . '?' . http_build_query(array_merge($params, ['response_type' => 'code'])));
        $response->assertOk();

        return array_merge($params, ['consent_offer' => $this->consentOfferFrom($response)]);
    }

    /** @param TestResponse<Response> $response */
    protected function consentOfferFrom(TestResponse $response): string
    {
        $offer = $response->viewData('page')['props']['consentOffer'] ?? null;
        $this->assertIsString($offer, 'Consent page must contain the server-authenticated offer.');

        // Laravel's HTTP test client does not retain response cookies automatically.
        $this->withCookie(config('session.cookie'), app('session')->getId());

        return $offer;
    }
}
