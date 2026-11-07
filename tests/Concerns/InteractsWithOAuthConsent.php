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
        $response = $this->get($url . '?' . http_build_query(array_merge($params, ['response_type' => 'code'])));
        $response->assertOk();

        return array_merge($params, ['consent_offer' => $this->consentOfferFrom($response)]);
    }

    /** @param TestResponse<Response> $response */
    protected function consentOfferFrom(TestResponse $response): string
    {
        preg_match('/name="consent_offer" value="([^"]+)"/', $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches, 'Consent form must contain the server-authenticated offer.');

        // Laravel's HTTP test client does not retain response cookies automatically.
        $this->withCookie(config('session.cookie'), app('session')->getId());

        return html_entity_decode($matches[1], ENT_QUOTES);
    }
}
