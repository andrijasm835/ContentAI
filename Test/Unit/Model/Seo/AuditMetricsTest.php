<?php

namespace Nistruct\ContentAI\Test\Unit\Model\Seo;

use Nistruct\ContentAI\Model\Seo\AuditMetrics;
use PHPUnit\Framework\TestCase;

class AuditMetricsTest extends TestCase
{
    private AuditMetrics $metrics;

    protected function setUp(): void
    {
        $this->metrics = new AuditMetrics();
    }

    public function testNoMatchesAndNoIssuesStatusesAndScores(): void
    {
        self::assertSame(AuditMetrics::STATUS_NO_MATCHES, $this->metrics->getStatus(0, 0));
        self::assertNull($this->metrics->calculateScore(0, []));
        self::assertSame(AuditMetrics::STATUS_NO_ISSUES, $this->metrics->getStatus(3, 0));
        self::assertSame(100, $this->metrics->calculateScore(3, []));
    }

    public function testNextActionCategoryCombinations(): void
    {
        $content = [$this->metrics->issue(AuditMetrics::SEVERITY_CRITICAL, 'missing_meta_title', '', '')];
        $url = [$this->metrics->issue(AuditMetrics::SEVERITY_WARNING, 'missing_active_rewrite', '', '')];
        $status = [$this->metrics->issue(AuditMetrics::SEVERITY_NOTICE, 'disabled_product', '', '')];

        self::assertSame('Improve the meta title and/or meta description.', $this->metrics->getItemNextAction($content));
        self::assertSame('Review the URL and rewrite configuration.', $this->metrics->getItemNextAction($url));
        self::assertSame('Review whether this inactive or disabled item should remain accessible or indexed.', $this->metrics->getItemNextAction($status));
        self::assertSame('Improve the SEO content, then review the URL and rewrite warnings.', $this->metrics->getItemNextAction(array_merge($content, $url)));
        self::assertSame('Improve the SEO content and review the item status.', $this->metrics->getItemNextAction(array_merge($content, $status)));
        self::assertSame('Review the URL/rewrite configuration and item status.', $this->metrics->getItemNextAction(array_merge($url, $status)));
        self::assertSame('Improve the SEO content, then review the URL/rewrite configuration and item status.', $this->metrics->getItemNextAction(array_merge($content, $url, $status)));
    }

    public function testLegacyRedirectsDoNotLowerScore(): void
    {
        $issue = $this->metrics->issue(AuditMetrics::SEVERITY_NOTICE, 'legacy_redirects_present', '', '');

        self::assertFalse($issue['actionable']);
        self::assertSame(100, $this->metrics->calculateItemScore([$issue]));
        self::assertSame(100, $this->metrics->calculateScore(1, [$issue]));
    }

    public function testOnlyLegacyRedirectsNeedNoSeoAction(): void
    {
        $issue = $this->metrics->issue(AuditMetrics::SEVERITY_NOTICE, 'legacy_redirects_present', '', '');

        self::assertSame(0, $this->metrics->countActionableIssues([$issue]));
        self::assertSame(1, $this->metrics->countInformationalIssues([$issue]));
        self::assertSame('ok', $this->metrics->getItemPriority([$issue]));
        self::assertSame('No SEO action needed.', $this->metrics->getItemNextAction([$issue]));
    }

    public function testAiFixableIsLimitedToContentFields(): void
    {
        $contentIssue = $this->metrics->issue(AuditMetrics::SEVERITY_CRITICAL, 'missing_meta_description', '', '');
        $urlIssue = $this->metrics->issue(AuditMetrics::SEVERITY_WARNING, 'missing_active_rewrite', '', '');

        self::assertTrue($contentIssue['ai_fixable']);
        self::assertFalse($urlIssue['ai_fixable']);
        self::assertTrue($this->metrics->hasAiFixableIssue([$contentIssue, $urlIssue]));
        self::assertFalse($this->metrics->hasAiFixableIssue([$urlIssue]));
    }

    public function testIssueOrdering(): void
    {
        $issues = [
            $this->metrics->issue(AuditMetrics::SEVERITY_NOTICE, 'disabled_product', '', ''),
            $this->metrics->issue(AuditMetrics::SEVERITY_WARNING, 'missing_active_rewrite', '', ''),
            $this->metrics->issue(AuditMetrics::SEVERITY_CRITICAL, 'missing_meta_title', '', ''),
        ];

        $sorted = $this->metrics->sortIssues($issues);

        self::assertSame(['missing_meta_title', 'missing_active_rewrite', 'disabled_product'], array_column($sorted, 'code'));
    }
}
