<?php

declare(strict_types=1);

namespace Cboxdk\StatamicMcp\Integration;

use Cboxdk\StatamicMcp\Auth\ResourcePolicy;
use Cboxdk\StatamicMcp\Mcp\DataTransferObjects\ErrorCode;
use Cboxdk\StatamicMcp\Mcp\Exceptions\ToolFailure;
use Illuminate\Support\Facades\Cache;
use Statamic\Facades\Entry;
use Statamic\Facades\Term;
use Statamic\Facades\User;
use Statamic\SeoPro\Reporting\Report;
use Statamic\SeoPro\Sitemap\Sitemap;

/** Native reports and sitemap are global projections, not permission-filtered records. */
final class SeoProReports
{
    public function authorize(): void
    {
        if (! User::current()?->isSuper()) {
            throw new ToolFailure(ErrorCode::Forbidden, 'Site-wide SEO reports and sitemap require a super user.');
        }
        $policy = app(ResourcePolicy::class);
        // A stored report can include deleted content and earlier sites. Checking
        // only today's collections cannot prove its contents are readable.
        foreach (['entries', 'terms', 'structures', 'globals', 'assets', 'seo-pro'] as $domain) {
            $patterns = config("statamic.mcp.tools.{$domain}.resources.read");
            if (($patterns !== null && $patterns !== ['*']) || $policy->getDeniedFields($domain) !== []) {
                throw new ToolFailure(ErrorCode::Forbidden, 'Site-wide SEO output requires unrestricted content and field policies.');
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if (! is_dir(storage_path('statamic/seopro/reports'))) {
            return [];
        }

        return Report::all()->map(fn (Report $report): array => $this->summary($report))->values()->all();
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        if (preg_match('/^[1-9][0-9]{0,8}$/D', $id) !== 1) {
            throw new ToolFailure(ErrorCode::Validation, 'Select a report id from list_reports.', 'id');
        }
        $report = (new Report)->setId((int) $id);
        if (! $report->exists()) {
            throw new ToolFailure(ErrorCode::NotFound, 'Report not found.', 'id');
        }
        $report->load();
        // Do not call generateIfNecessary(): CP show mutates pending and legacy
        // reports. Our read returns their existing data without dispatching jobs.
        $data = json_decode(json_encode($report->data(), JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new \LogicException('SEO Pro returned an invalid report.');
        }

        return array_filter($data, is_string(...), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, mixed> */
    public function generate(): array
    {
        // Native generation finalizes in its last content chunk. An empty site
        // has no chunk and would leave a report permanently pending.
        if (Entry::all()->whereNull('redirect')->isEmpty() && Term::all()->isEmpty()) {
            throw new ToolFailure(ErrorCode::Validation, 'There are no entries or terms to report on.');
        }
        // Native nextId() is max+1. Serialize allocation across MCP callers.
        $result = [];
        Cache::lock('statamic-mcp:seo-pro:report-create', 30)->block(5, function () use (&$result): void {
            $report = Report::create()->save();
            $report->queueGenerate();
            $result = $this->summary($report->fresh());
        });

        return $result;
    }

    /** @return array<string, mixed> */
    private function summary(Report $report): array
    {
        return ['id' => (string) $report->id(), 'status' => $report->status(),
            'date' => $report->date()->timestamp, 'score' => $report->score(),
            'pages_crawled' => $report->pagesCrawled(), 'pages_actionable' => $report->pagesActionable()];
    }

    /** @return list<array<string, mixed>> */
    public function sitemap(): array
    {
        return array_values(array_map(
            fn (array $page): array => array_filter($page, is_string(...), ARRAY_FILTER_USE_KEY),
            (new Sitemap)->pages(),
        ));
    }
}
