<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Mcp\Skills;

use Cboxdk\StatamicMcp\Authorization\InstallationProfile;
use Laravel\Mcp\Server\Skill;

/** Static package guidance only. Live site data must still go through authorized routers. */
final class ContentWorkflowSkill extends Skill
{
    /** @param 'write_entry'|'translate_entry'|'edit_page_builder'|'model_content' $workflow */
    public function __construct(private readonly string $workflow = 'write_entry') {}

    public function path(): string
    {
        $directory = match ($this->workflow) {
            'write_entry' => 'writing-statamic-entries',
            'translate_entry' => 'translating-statamic-entries',
            'edit_page_builder' => 'editing-statamic-page-builders',
            'model_content' => 'modeling-statamic-content',
        };

        return dirname(__DIR__, 3) . '/resources/skills/' . $directory;
    }

    public function shouldRegister(): bool
    {
        return config('statamic.mcp.resources.enabled', true)
            && app(InstallationProfile::class)->allowsAny('guidance', writes: false);
    }
}
