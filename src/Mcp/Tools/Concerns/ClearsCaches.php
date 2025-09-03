<?php

namespace Cboxdk\StatamicMcp\Mcp\Tools\Concerns;

use Illuminate\Support\Facades\Artisan;

trait ClearsCaches
{
    /**
     * Clear relevant Statamic caches after content/structure changes.
     *
     * @param  array<int, string>  $types
     *
     * @return array<string, mixed>
     */
    protected function clearStatamicCaches(array $types = ['stache', 'static']): array
    {
        $results = [];
        $cleared = [];

        try {
            // Always clear Stache (Statamic's primary cache)
            if (in_array('stache', $types)) {
                Artisan::call('statamic:stache:clear');
                $cleared[] = 'stache';  // Will be converted to associative
                $results['stache'] = [
                    'success' => true,
                    'message' => 'Stache cache cleared successfully',
                    'type' => 'stache',
                ];
            }

            // Clear static cache if requested
            if (in_array('static', $types)) {
                try {
                    Artisan::call('statamic:static:clear');
                    $cleared[] = 'static';  // Will be converted to associative
                    $results['static'] = [
                        'success' => true,
                        'message' => 'Static cache cleared successfully',
                        'type' => 'static',
                    ];
                } catch (\Exception $e) {
                    $results['static'] = [
                        'success' => false,
                        'message' => 'Static cache clear failed: ' . $e->getMessage(),
                        'type' => 'static',
                    ];
                }
            }

            // Clear image cache if requested
            if (in_array('images', $types)) {
                try {
                    Artisan::call('statamic:glide:clear');
                    $cleared[] = 'images';  // Will be converted to associative
                    $results['images'] = [
                        'success' => true,
                        'message' => 'Image cache cleared successfully',
                        'type' => 'images',
                    ];
                } catch (\Exception $e) {
                    $results['images'] = [
                        'success' => false,
                        'message' => 'Image cache clear failed: ' . $e->getMessage(),
                        'type' => 'images',
                    ];
                }
            }

            // Clear view cache if requested
            if (in_array('views', $types)) {
                try {
                    Artisan::call('view:clear');
                    $cleared[] = 'views';  // Will be converted to associative
                    $results['views'] = [
                        'success' => true,
                        'message' => 'View cache cleared successfully',
                        'type' => 'views',
                    ];
                } catch (\Exception $e) {
                    $results['views'] = [
                        'success' => false,
                        'message' => 'View cache clear failed: ' . $e->getMessage(),
                        'type' => 'views',
                    ];
                }
            }

            return [
                'cache_cleared' => true,
                'cleared_types' => $cleared,
                'details' => $results,
                'note' => 'Caches cleared automatically after structural changes',
            ];

        } catch (\Exception $e) {
            return [
                'cache_cleared' => false,
                'error' => 'Cache clearing failed: ' . $e->getMessage(),
                'cleared_types' => $cleared,
                'details' => $results,
            ];
        }
    }

    /**
     * Get recommended cache types to clear based on operation type.
     *
     * @return array<int, string>
     */
    protected function getRecommendedCacheTypes(string $operationType): array
    {
        return match ($operationType) {
            'blueprint_change' => ['stache', 'static', 'views'],
            'content_change' => ['stache', 'static'],
            'fieldset_change' => ['stache', 'static', 'views'],
            'collection_change' => ['stache', 'static'],
            'taxonomy_change' => ['stache', 'static'],
            'global_change' => ['stache', 'static'],
            'structure_change' => ['stache', 'static', 'views'],
            'template_change' => ['static', 'views'],
            'asset_change' => ['images', 'static'],
            default => ['stache'],
        };
    }
}
