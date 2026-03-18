<?php

declare(strict_types=1);

use Cboxdk\StatamicMcp\Mcp\Tools\System\DiscoveryTool;

describe('DiscoveryTool', function () {
    it('returns tool suggestions for content-related intents', function () {
        $tool = new DiscoveryTool;
        $result = $tool->execute(['intent' => 'I want to manage blog entries']);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        expect($result['data'])->toHaveKey('discovery');
        expect($result['data']['discovery'])->toHaveKey('recommended_tools');
        expect($result['data']['discovery']['recommended_tools'])->not->toBeEmpty();

        // Should recommend entries tool for blog content
        $toolNames = collect($result['data']['discovery']['recommended_tools'])->pluck('tool')->all();
        expect($toolNames)->toContain('statamic-entries');
    });

    it('returns tool suggestions for blueprint intents', function () {
        $tool = new DiscoveryTool;
        $result = $tool->execute(['intent' => 'configure fields and blueprints']);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        $toolNames = collect($result['data']['discovery']['recommended_tools'])->pluck('tool')->all();
        expect($toolNames)->toContain('statamic-blueprints');
    });

    it('returns available tools list', function () {
        $tool = new DiscoveryTool;
        $result = $tool->execute(['intent' => 'what tools are available']);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        expect($result['data']['discovery'])->toHaveKey('available_tools');
        expect($result['data']['discovery']['available_tools'])->not->toBeEmpty();
    });

    it('handles empty intent gracefully', function () {
        $tool = new DiscoveryTool;
        $result = $tool->execute(['intent' => '']);

        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        expect($result['data']['discovery'])->toHaveKey('available_tools');
    });

    it('includes system state in CLI context', function () {
        $tool = new DiscoveryTool;
        $result = $tool->execute(['intent' => 'system status']);

        // In CLI context (test runner), system state should be included
        expect($result)->toHaveKey('success');
        expect($result['success'])->toBeTrue();
        expect($result['data']['discovery'])->toHaveKey('system_state');
    });
});
