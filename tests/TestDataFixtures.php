<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Tests;

use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\User;

/**
 * Standardized test data fixtures for proper test isolation.
 *
 * This class provides clean, predictable test data that can be
 * set up and torn down consistently across all tests.
 */
class TestDataFixtures
{
    /**
     * Collection handles used in tests.
     */
    public const TEST_COLLECTION = 'test_blog';

    public const TEST_COLLECTION_TITLE = 'Test Blog';

    /**
     * Taxonomy handles used in tests.
     */
    public const TEST_TAXONOMY = 'test_categories';

    public const TEST_TAXONOMY_TITLE = 'Test Categories';

    /**
     * Global set handles used in tests.
     */
    public const TEST_GLOBAL_SET = 'test_settings';

    public const TEST_GLOBAL_SET_TITLE = 'Test Settings';

    /**
     * Site handles used in tests.
     */
    public const TEST_SITE = 'default';

    public const TEST_SITE_SECONDARY = 'secondary';

    /**
     * Test entry data.
     */
    public const TEST_ENTRY_DATA = [
        'title' => 'Test Entry',
        'content' => 'This is test content for the entry.',
        'excerpt' => 'Test excerpt',
        'featured' => true,
    ];

    /**
     * Test term data.
     */
    public const TEST_TERM_DATA = [
        'title' => 'Test Category',
        'description' => 'This is a test category description.',
    ];

    /**
     * Test global data.
     */
    public const TEST_GLOBAL_DATA = [
        'site_name' => 'Test Site',
        'site_description' => 'A test site for MCP testing',
        'contact_email' => 'test@example.com',
    ];

    /**
     * Set up all test fixtures.
     */
    public static function setUp(): void
    {
        static::setupSites();
        static::setupBlueprints();
        static::setupCollections();
        static::setupTaxonomies();
        static::setupGlobals();
    }

    /**
     * Tear down all test fixtures.
     */
    public static function tearDown(): void
    {
        static::tearDownEntries();
        static::tearDownTerms();
        static::tearDownGlobals();
        static::tearDownCollections();
        static::tearDownTaxonomies();
        static::tearDownBlueprints();
        static::tearDownSites();
    }

    /**
     * Set up test sites.
     */
    public static function setupSites(): void
    {
        if (! Site::hasMultiple()) {
            Site::setSites([
                static::TEST_SITE => [
                    'name' => 'English',
                    'locale' => 'en_US',
                    'url' => '/',
                ],
                static::TEST_SITE_SECONDARY => [
                    'name' => 'French',
                    'locale' => 'fr_FR',
                    'url' => '/fr',
                ],
            ]);
        }
    }

    /**
     * Set up test blueprints.
     */
    public static function setupBlueprints(): void
    {
        // Collection blueprint
        Blueprint::make(static::TEST_COLLECTION)
            ->setNamespace('collections')
            ->setContents([
                'title' => static::TEST_COLLECTION_TITLE,
                'fields' => [
                    [
                        'handle' => 'title',
                        'field' => [
                            'type' => 'text',
                            'required' => true,
                            'display' => 'Title',
                        ],
                    ],
                    [
                        'handle' => 'content',
                        'field' => [
                            'type' => 'markdown',
                            'display' => 'Content',
                        ],
                    ],
                    [
                        'handle' => 'excerpt',
                        'field' => [
                            'type' => 'textarea',
                            'display' => 'Excerpt',
                        ],
                    ],
                    [
                        'handle' => 'featured',
                        'field' => [
                            'type' => 'toggle',
                            'display' => 'Featured',
                            'default' => false,
                        ],
                    ],
                ],
            ])
            ->save();

        // Taxonomy blueprint
        Blueprint::make(static::TEST_TAXONOMY)
            ->setNamespace('taxonomies')
            ->setContents([
                'title' => static::TEST_TAXONOMY_TITLE,
                'fields' => [
                    [
                        'handle' => 'title',
                        'field' => [
                            'type' => 'text',
                            'required' => true,
                            'display' => 'Title',
                        ],
                    ],
                    [
                        'handle' => 'description',
                        'field' => [
                            'type' => 'textarea',
                            'display' => 'Description',
                        ],
                    ],
                ],
            ])
            ->save();

        // Global set blueprint
        Blueprint::make(static::TEST_GLOBAL_SET)
            ->setNamespace('globals')
            ->setContents([
                'title' => static::TEST_GLOBAL_SET_TITLE,
                'fields' => [
                    [
                        'handle' => 'site_name',
                        'field' => [
                            'type' => 'text',
                            'required' => true,
                            'display' => 'Site Name',
                        ],
                    ],
                    [
                        'handle' => 'site_description',
                        'field' => [
                            'type' => 'textarea',
                            'display' => 'Site Description',
                        ],
                    ],
                    [
                        'handle' => 'contact_email',
                        'field' => [
                            'type' => 'text',
                            'input_type' => 'email',
                            'display' => 'Contact Email',
                        ],
                    ],
                ],
            ])
            ->save();
    }

    /**
     * Set up test collections.
     */
    public static function setupCollections(): void
    {
        if (! Collection::find(static::TEST_COLLECTION)) {
            Collection::make(static::TEST_COLLECTION)
                ->title(static::TEST_COLLECTION_TITLE)
                ->pastDateBehavior('public')
                ->futureDateBehavior('private')
                ->sites([static::TEST_SITE, static::TEST_SITE_SECONDARY])
                ->save();
        }
    }

    /**
     * Set up test taxonomies.
     */
    public static function setupTaxonomies(): void
    {
        if (! Taxonomy::find(static::TEST_TAXONOMY)) {
            Taxonomy::make(static::TEST_TAXONOMY)
                ->title(static::TEST_TAXONOMY_TITLE)
                ->sites([static::TEST_SITE, static::TEST_SITE_SECONDARY])
                ->save();
        }
    }

    /**
     * Set up test globals.
     */
    public static function setupGlobals(): void
    {
        if (! GlobalSet::find(static::TEST_GLOBAL_SET)) {
            $globalSet = GlobalSet::make(static::TEST_GLOBAL_SET)
                ->title(static::TEST_GLOBAL_SET_TITLE);
            $globalSet->save();

            // Set initial global values
            $globalSet = GlobalSet::find(static::TEST_GLOBAL_SET);
            if ($globalSet) {
                $variables = $globalSet->in(static::TEST_SITE);
                if ($variables) {
                    $variables->data(static::TEST_GLOBAL_DATA)->save();
                }
            }
        }
    }

    /**
     * Create a test entry.
     */
    public static function createTestEntry(array $data = [], ?string $site = null): \Statamic\Entries\Entry
    {
        $entryData = array_merge(static::TEST_ENTRY_DATA, $data);
        $site = $site ?? static::TEST_SITE;

        $entry = Entry::make()
            ->collection(static::TEST_COLLECTION)
            ->locale($site)
            ->data($entryData)
            ->published(true);

        if (! $entry->slug()) {
            $entry->slug(\Illuminate\Support\Str::slug($entryData['title'] ?? 'test-entry'));
        }

        $entry->save();

        return $entry;
    }

    /**
     * Create a test term.
     */
    public static function createTestTerm(array $data = [], ?string $site = null): \Statamic\Taxonomies\Term
    {
        $termData = array_merge(static::TEST_TERM_DATA, $data);
        $site = $site ?? static::TEST_SITE;

        $term = Term::make()
            ->taxonomy(static::TEST_TAXONOMY)
            ->locale($site)
            ->data($termData);

        if (! $term->slug()) {
            $term->slug(\Illuminate\Support\Str::slug($termData['title'] ?? 'test-category'));
        }

        $term->save();

        return $term;
    }

    /**
     * Create a test user.
     */
    public static function createTestUser(array $data = []): \Statamic\Auth\User
    {
        $userData = array_merge([
            'email' => 'test@example.com',
            'name' => 'Test User',
            'password' => bcrypt('password'),
        ], $data);

        $user = User::make()
            ->email($userData['email'])
            ->data($userData);

        $user->save();

        return $user;
    }

    /**
     * Tear down test entries.
     */
    public static function tearDownEntries(): void
    {
        try {
            Entry::query()
                ->where('collection', static::TEST_COLLECTION)
                ->get()
                ->each(fn ($entry) => $entry->delete());
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }

    /**
     * Tear down test terms.
     */
    public static function tearDownTerms(): void
    {
        try {
            Term::query()
                ->where('taxonomy', static::TEST_TAXONOMY)
                ->get()
                ->each(fn ($term) => $term->delete());
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }

    /**
     * Tear down test globals.
     */
    public static function tearDownGlobals(): void
    {
        try {
            $globalSet = GlobalSet::find(static::TEST_GLOBAL_SET);
            if ($globalSet) {
                $globalSet->delete();
            }
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }

    /**
     * Tear down test collections.
     */
    public static function tearDownCollections(): void
    {
        try {
            $collection = Collection::find(static::TEST_COLLECTION);
            if ($collection) {
                $collection->delete();
            }
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }

    /**
     * Tear down test taxonomies.
     */
    public static function tearDownTaxonomies(): void
    {
        try {
            $taxonomy = Taxonomy::find(static::TEST_TAXONOMY);
            if ($taxonomy) {
                $taxonomy->delete();
            }
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }

    /**
     * Tear down test blueprints.
     */
    public static function tearDownBlueprints(): void
    {
        try {
            $blueprints = [
                'collections.' . static::TEST_COLLECTION,
                'taxonomies.' . static::TEST_TAXONOMY,
                'globals.' . static::TEST_GLOBAL_SET,
            ];

            foreach ($blueprints as $blueprintHandle) {
                $blueprint = Blueprint::find($blueprintHandle);
                if ($blueprint) {
                    $blueprint->delete();
                }
            }
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }

    /**
     * Tear down test sites.
     */
    public static function tearDownSites(): void
    {
        try {
            Site::setSites([
                'default' => [
                    'name' => 'Default',
                    'locale' => 'en_US',
                    'url' => '/',
                ],
            ]);
        } catch (\Exception $e) {
            // Ignore errors during cleanup
        }
    }

    /**
     * Reset all test data - complete cleanup and setup.
     */
    public static function reset(): void
    {
        static::tearDown();
        static::setUp();
    }
}
