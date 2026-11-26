<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Integration;

use Cboxdk\StatamicMcp\Mcp\Support\SeoProFieldData;
use Cboxdk\StatamicMcp\StatamicMcp;
use Statamic\Fields\Field;

final class SeoPro
{
    public static function register(): void
    {
        $integration = StatamicMcp::integration('statamic/seo-pro')
            ->scope('seo-pro:read', 'Read SEO defaults, redirects and errors', ['access cp'])
            ->scope('seo-pro:write', 'Edit SEO defaults and redirects', ['access cp'])
            ->scope('seo-pro:delete', 'Delete SEO redirects and errors', ['access cp'])
            ->scope('seo-pro:site-wide', 'Read the full sitemap and read or generate site-wide SEO reports')
            ->router(SeoProRouter::class, approvals: ['create', 'generate_report']);

        foreach (['seo_pro', 'seo_pro_source', 'seo_pro_opening_hours'] as $type) {
            $integration->fieldtype($type, SeoProFieldData::spec(...),
                fn (mixed $value, Field $field, string $path): mixed => SeoProFieldData::sanitize($field, $value, $path));
        }
    }
}
