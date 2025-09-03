<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Tools\Roles;

use Cboxdk\StatamicMcp\Mcp\Tools\BaseStatamicTool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;

#[Title('Create Role')]
class CreateRoleTool extends BaseStatamicTool
{
    /**
     * Get the tool name.
     */
    protected function getToolName(): string
    {
        return 'statamic.roles.create';
    }

    /**
     * Get the tool description.
     */
    protected function getToolDescription(): string
    {
        return 'Create a new role with specified permissions';
    }

    /**
     * Define the tool's input schema.
     */
    protected function defineSchema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('handle')
            ->description('Unique handle for the role')
            ->required()
            ->string('title')
            ->description('Display title for the role')
            ->required()
            ->raw('permissions', [
                'type' => 'array',
                'description' => 'Array of permission strings to grant to this role',
                'items' => ['type' => 'string'],
            ])
            ->optional()
            ->boolean('validate_permissions')
            ->description('Validate that all permissions are valid Statamic permissions')
            ->optional()
            ->boolean('check_dependencies')
            ->description('Check for missing permission dependencies and suggest additions')
            ->optional();
    }

    /**
     * Execute the tool logic.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @return array<string, mixed>
     */
    protected function execute(array $arguments): array
    {
        $handle = $arguments['handle'];
        $title = $arguments['title'];
        $permissions = $arguments['permissions'] ?? [];
        $validatePermissions = $arguments['validate_permissions'] ?? true;
        $checkDependencies = $arguments['check_dependencies'] ?? true;

        try {
            // Check if role already exists
            if (Role::find($handle)) {
                return $this->createErrorResponse("Role with handle '{$handle}' already exists", [
                    'existing_roles' => Role::all()->map->handle()->all(),
                ])->toArray();
            }

            // Validate handle format
            if (! preg_match('/^[a-z0-9_]+$/', $handle)) {
                return $this->createErrorResponse('Role handle must contain only lowercase letters, numbers, and underscores')->toArray();
            }

            // Validate permissions if requested
            $validationResult = [];
            if ($validatePermissions && ! empty($permissions)) {
                $validationResult = $this->validatePermissions($permissions);
                if ($validationResult['has_invalid']) {
                    return $this->createErrorResponse('Invalid permissions detected', [
                        'invalid_permissions' => $validationResult['invalid'],
                        'valid_permissions' => $validationResult['valid'],
                        'suggestions' => $validationResult['suggestions'],
                    ])->toArray();
                }
            }

            // Check dependencies if requested
            $dependencyResult = [];
            if ($checkDependencies && ! empty($permissions)) {
                $dependencyResult = $this->checkPermissionDependencies($permissions);
            }

            // Create the role
            $role = Role::make()
                ->handle($handle)
                ->title($title)
                ->addPermissions($permissions);

            $role->save();

            // Clear caches
            Stache::clear();

            return [
                'success' => true,
                'role' => [
                    'handle' => $handle,
                    'title' => $title,
                    'permissions' => $permissions,
                    'permission_count' => count($permissions),
                ],
                'validation_result' => $validationResult,
                'dependency_analysis' => $dependencyResult,
                'next_steps' => [
                    'assign_to_users' => 'Use statamic.users.update to assign this role to users',
                    'test_permissions' => 'Test the role by assigning it to a test user',
                    'review_security' => $this->getSecurityRecommendations($permissions),
                ],
            ];

        } catch (\Exception $e) {
            return $this->createErrorResponse('Failed to create role: ' . $e->getMessage())->toArray();
        }
    }

    /**
     * Validate permission strings.
     *
     * @param  array<string>  $permissions
     *
     * @return array<string, mixed>
     */
    private function validatePermissions(array $permissions): array
    {
        $knownPermissions = $this->getKnownPermissions();
        $valid = [];
        $invalid = [];
        $suggestions = [];

        foreach ($permissions as $permission) {
            if (in_array($permission, $knownPermissions)) {
                $valid[] = $permission;
            } else {
                $invalid[] = $permission;

                // Find similar permissions
                $similar = $this->findSimilarPermissions($permission, $knownPermissions);
                if (! empty($similar)) {
                    $suggestions[$permission] = $similar;
                }
            }
        }

        return [
            'valid' => $valid,
            'invalid' => $invalid,
            'suggestions' => $suggestions,
            'has_invalid' => ! empty($invalid),
        ];
    }

    /**
     * Check for permission dependencies.
     *
     * @param  array<string>  $permissions
     *
     * @return array<string, mixed>
     */
    private function checkPermissionDependencies(array $permissions): array
    {
        $dependencies = [
            'edit entries' => ['view entries'],
            'create entries' => ['view entries', 'view collections'],
            'delete entries' => ['view entries', 'edit entries'],
            'publish entries' => ['view entries', 'edit entries'],
            'reorder entries' => ['view entries', 'edit entries'],
            'edit users' => ['view users'],
            'create users' => ['view users'],
            'delete users' => ['view users', 'edit users'],
            'assign roles' => ['view users', 'edit users'],
            'upload assets' => ['view assets'],
            'edit assets' => ['view assets'],
            'delete assets' => ['view assets', 'edit assets'],
            'edit collections' => ['view collections'],
            'create collections' => ['view collections'],
            'delete collections' => ['view collections', 'edit collections'],
            'edit forms' => ['view forms'],
            'create forms' => ['view forms'],
            'delete forms' => ['view forms', 'edit forms'],
            'edit blueprints' => ['view blueprints'],
            'create blueprints' => ['view blueprints'],
            'delete blueprints' => ['view blueprints', 'edit blueprints'],
        ];

        $missing = [];
        $recommended = [];

        foreach ($permissions as $permission) {
            if (isset($dependencies[$permission])) {
                foreach ($dependencies[$permission] as $dependency) {
                    if (! in_array($dependency, $permissions)) {
                        $missing[$permission][] = $dependency;
                        if (! in_array($dependency, $recommended)) {
                            $recommended[] = $dependency;
                        }
                    }
                }
            }
        }

        return [
            'missing_dependencies' => $missing,
            'recommended_additions' => $recommended,
            'has_missing' => ! empty($missing),
        ];
    }

    /**
     * Get security recommendations for permissions.
     *
     * @param  array<string>  $permissions
     */
    private function getSecurityRecommendations(array $permissions): ?string
    {
        $highRiskPermissions = [
            'delete users', 'assign roles', 'edit roles', 'delete collections',
            'delete blueprints', 'delete forms', 'configure sites', 'access updater',
            'perform updates',
        ];

        $hasHighRisk = array_intersect($permissions, $highRiskPermissions);

        if (! empty($hasHighRisk)) {
            return 'This role has high-risk permissions. Assign carefully and audit regularly.';
        }

        if (in_array('access cp', $permissions)) {
            return 'This role grants control panel access. Ensure users are trusted.';
        }

        return null;
    }

    /**
     * Get known Statamic permissions.
     *
     * @return array<string>
     */
    private function getKnownPermissions(): array
    {
        return [
            'access cp',
            'view users', 'edit users', 'create users', 'delete users',
            'assign roles', 'edit roles',
            'view entries', 'edit entries', 'create entries', 'delete entries',
            'publish entries', 'reorder entries',
            'view collections', 'edit collections', 'create collections', 'delete collections',
            'view assets', 'edit assets', 'upload assets', 'delete assets',
            'view blueprints', 'edit blueprints', 'create blueprints', 'delete blueprints',
            'view forms', 'edit forms', 'create forms', 'delete forms',
            'view form submissions', 'delete form submissions',
            'view globals', 'edit globals',
            'view taxonomies', 'edit taxonomies', 'create taxonomies', 'delete taxonomies',
            'view terms', 'edit terms', 'create terms', 'delete terms',
            'view nav', 'edit nav', 'create nav', 'delete nav',
            'view sites', 'edit sites', 'create sites', 'delete sites',
            'configure fields', 'configure asset containers',
            'configure collections', 'configure taxonomies',
            'configure globals', 'configure navs', 'configure forms', 'configure sites',
            'manage preferences', 'access updater', 'view updates', 'perform updates',
        ];
    }

    /**
     * Find similar permissions for suggestions.
     *
     * @param  array<string>  $knownPermissions
     *
     * @return array<string>
     */
    private function findSimilarPermissions(string $permission, array $knownPermissions): array
    {
        $similar = [];
        $words = explode(' ', $permission);

        foreach ($knownPermissions as $known) {
            $knownWords = explode(' ', $known);
            $commonWords = array_intersect($words, $knownWords);

            if (count($commonWords) > 0) {
                $similar[] = $known;
            }
        }

        // Limit to 3 most likely suggestions
        return array_slice($similar, 0, 3);
    }
}
