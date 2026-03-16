<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\System\SchemaTool;

describe('SchemaTool', function () {
    it('returns catalog when no tool specified', function () {
        $tool = new SchemaTool;
        $result = $tool->execute([]);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('tools');
        expect($result['data'])->toHaveKey('total');
        expect($result['data']['total'])->toBeGreaterThan(0);
    });

    it('returns schema for a known tool', function () {
        $tool = new SchemaTool;
        $result = $tool->execute(['tool_name' => 'statamic-entries']);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('tool');
        expect($result['data']['tool'])->toHaveKey('domain');
        expect($result['data']['tool'])->toHaveKey('actions');
    });

    it('returns error for unknown tool', function () {
        $tool = new SchemaTool;
        $result = $tool->execute(['tool_name' => 'nonexistent-tool']);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeFalse();
    });

    it('catalog contains all expected tools', function () {
        $tool = new SchemaTool;
        $result = $tool->execute([]);

        $toolNames = array_keys($result['data']['tools']);
        expect($toolNames)->toContain('statamic-entries');
        expect($toolNames)->toContain('statamic-blueprints');
        expect($toolNames)->toContain('statamic-structures');
        expect($toolNames)->toContain('statamic-assets');
        expect($toolNames)->toContain('statamic-users');
        expect($toolNames)->toContain('statamic-system');
    });
});
