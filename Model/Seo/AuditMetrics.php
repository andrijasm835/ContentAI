<?php
namespace Nistruct\ContentAI\Model\Seo;

class AuditMetrics
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_NOTICE = 'notice';
    public const STATUS_OK = 'ok';
    public const STATUS_NO_MATCHES = 'no_matches';
    public const STATUS_NO_ISSUES = 'no_issues';
    public const CATEGORY_CONTENT = 'content';
    public const CATEGORY_URL = 'url';
    public const CATEGORY_STATUS = 'status';
    public const CATEGORY_OPTIONAL = 'optional';

    private const AI_FIXABLE_CODES = [
        'missing_meta_title' => true,
        'long_meta_title' => true,
        'short_meta_title' => true,
        'duplicate_meta_title' => true,
        'missing_meta_description' => true,
        'long_meta_description' => true,
        'short_meta_description' => true,
        'duplicate_meta_description' => true,
    ];

    private const ISSUE_LABELS = [
        'missing_meta_title' => 'Missing meta title',
        'long_meta_title' => 'Meta title is too long',
        'short_meta_title' => 'Meta title is too short',
        'duplicate_meta_title' => 'Duplicate meta title',
        'missing_meta_description' => 'Missing meta description',
        'long_meta_description' => 'Meta description is too long',
        'short_meta_description' => 'Meta description is too short',
        'duplicate_meta_description' => 'Duplicate meta description',
        'missing_url_key' => 'Missing URL key',
        'bad_url_key_format' => 'Suspicious URL key format',
        'duplicate_request_path' => 'Duplicate request path',
        'empty_request_path' => 'Empty request path',
        'empty_target_path' => 'Empty target path',
        'self_target' => 'Request path points to itself',
        'legacy_redirect_path_format' => 'Legacy redirect path format is suspicious',
        'bad_request_path_format' => 'Request path format is suspicious',
        'missing_active_rewrite' => 'Missing active URL',
        'inactive_entity_direct_rewrite' => 'Inactive item has an active URL',
        'multiple_active_rewrites' => 'Multiple active URLs',
        'legacy_redirects_present' => 'Legacy redirects present',
        'disabled_product' => 'Product is disabled',
        'inactive_category' => 'Category is inactive',
        'inactive_cms_page' => 'CMS page is inactive',
    ];

    private const ISSUE_CATEGORIES = [
        'missing_meta_title' => self::CATEGORY_CONTENT,
        'long_meta_title' => self::CATEGORY_CONTENT,
        'short_meta_title' => self::CATEGORY_CONTENT,
        'duplicate_meta_title' => self::CATEGORY_CONTENT,
        'missing_meta_description' => self::CATEGORY_CONTENT,
        'long_meta_description' => self::CATEGORY_CONTENT,
        'short_meta_description' => self::CATEGORY_CONTENT,
        'duplicate_meta_description' => self::CATEGORY_CONTENT,
        'missing_url_key' => self::CATEGORY_URL,
        'bad_url_key_format' => self::CATEGORY_URL,
        'duplicate_request_path' => self::CATEGORY_URL,
        'empty_request_path' => self::CATEGORY_URL,
        'empty_target_path' => self::CATEGORY_URL,
        'self_target' => self::CATEGORY_URL,
        'legacy_redirect_path_format' => self::CATEGORY_URL,
        'bad_request_path_format' => self::CATEGORY_URL,
        'missing_active_rewrite' => self::CATEGORY_URL,
        'inactive_entity_direct_rewrite' => self::CATEGORY_URL,
        'multiple_active_rewrites' => self::CATEGORY_URL,
        'legacy_redirects_present' => self::CATEGORY_URL,
        'disabled_product' => self::CATEGORY_STATUS,
        'inactive_category' => self::CATEGORY_STATUS,
        'inactive_cms_page' => self::CATEGORY_STATUS,
    ];

    private const SCORE_WEIGHTS = [
        'legacy_redirects_present' => 0,
        'disabled_product' => 1,
        'inactive_category' => 1,
        'inactive_cms_page' => 1,
        'legacy_redirect_path_format' => 1,
        'short_meta_title' => 2,
        'short_meta_description' => 2,
    ];

    public function issue(string $severity, string $code, string $message, string $recommendation): array
    {
        return [
            'severity' => $severity,
            'code' => $code,
            'label' => $this->getIssueLabel($code),
            'category' => $this->getIssueCategory($code),
            'message' => $message,
            'recommendation' => $recommendation,
            'score_weight' => $this->getIssueScoreWeight($severity, $code),
            'ai_fixable' => isset(self::AI_FIXABLE_CODES[$code]),
        ];
    }

    public function getIssueLabel(string $code): string
    {
        return self::ISSUE_LABELS[$code] ?? ucwords(str_replace('_', ' ', $code));
    }

    public function getIssueCategory(string $code): string
    {
        return self::ISSUE_CATEGORIES[$code] ?? self::CATEGORY_OPTIONAL;
    }

    public function getStatus(int $entities, int $issues): string
    {
        if ($entities <= 0) {
            return self::STATUS_NO_MATCHES;
        }

        return $issues > 0 ? self::STATUS_OK : self::STATUS_NO_ISSUES;
    }

    public function calculateScore(int $entities, array $issues): ?int
    {
        if ($entities <= 0) {
            return null;
        }

        $penalty = 0;
        foreach ($issues as $issue) {
            $penalty += (int) ($issue['score_weight'] ?? $this->getIssueScoreWeight(
                (string) ($issue['severity'] ?? self::SEVERITY_NOTICE),
                (string) ($issue['code'] ?? '')
            ));
        }

        return max(0, min(100, 100 - (int) round($penalty / $entities)));
    }

    public function calculateItemScore(array $issues): int
    {
        $penalty = 0;
        foreach ($issues as $issue) {
            $penalty += (int) ($issue['score_weight'] ?? $this->getIssueScoreWeight(
                (string) ($issue['severity'] ?? self::SEVERITY_NOTICE),
                (string) ($issue['code'] ?? '')
            ));
        }

        return max(0, min(100, 100 - $penalty));
    }

    public function hasAiFixableIssue(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (!empty($issue['ai_fixable'])) {
                return true;
            }
        }

        return false;
    }

    public function getItemPriority(array $issues): string
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === self::SEVERITY_CRITICAL) {
                return 'fix_now';
            }
        }
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === self::SEVERITY_WARNING) {
                return 'review';
            }
        }

        return $issues ? 'monitor' : 'ok';
    }

    public function getItemNextAction(array $issues): string
    {
        if (!$issues) {
            return 'No action needed.';
        }

        $hasContent = $this->hasIssueCategory($issues, self::CATEGORY_CONTENT);
        $hasUrl = $this->hasIssueCategory($issues, self::CATEGORY_URL);
        $hasStatus = $this->hasIssueCategory($issues, self::CATEGORY_STATUS);

        if ($hasContent && $hasUrl && $hasStatus) {
            return 'Improve the SEO content, then review the URL/rewrite configuration and item status.';
        }
        if ($hasContent && $hasUrl) {
            return 'Improve the SEO content, then review the URL and rewrite warnings.';
        }
        if ($hasContent && $hasStatus) {
            return 'Improve the SEO content and review the item status.';
        }
        if ($hasUrl && $hasStatus) {
            return 'Review the URL/rewrite configuration and item status.';
        }
        if ($hasContent) {
            return 'Improve the meta title and/or meta description.';
        }
        if ($hasUrl) {
            return 'Review the URL and rewrite configuration.';
        }
        if ($hasStatus) {
            return 'Review whether this inactive or disabled item should remain accessible or indexed.';
        }

        return 'Review and decide whether the recommendation is still relevant.';
    }

    public function sortIssues(array $issues): array
    {
        $severityOrder = [self::SEVERITY_CRITICAL => 0, self::SEVERITY_WARNING => 1, self::SEVERITY_NOTICE => 2];
        $categoryOrder = [self::CATEGORY_CONTENT => 0, self::CATEGORY_URL => 1, self::CATEGORY_STATUS => 2, self::CATEGORY_OPTIONAL => 3];
        usort($issues, static function (array $a, array $b) use ($severityOrder, $categoryOrder): int {
            $severityCompare = ($severityOrder[$a['severity'] ?? self::SEVERITY_NOTICE] ?? 9)
                <=> ($severityOrder[$b['severity'] ?? self::SEVERITY_NOTICE] ?? 9);
            if ($severityCompare !== 0) {
                return $severityCompare;
            }

            return ($categoryOrder[$a['category'] ?? self::CATEGORY_OPTIONAL] ?? 9)
                <=> ($categoryOrder[$b['category'] ?? self::CATEGORY_OPTIONAL] ?? 9);
        });

        return $issues;
    }

    private function getIssueScoreWeight(string $severity, string $code): int
    {
        if (array_key_exists($code, self::SCORE_WEIGHTS)) {
            return self::SCORE_WEIGHTS[$code];
        }
        if ($severity === self::SEVERITY_CRITICAL) {
            return 18;
        }
        if ($severity === self::SEVERITY_WARNING) {
            return 8;
        }

        return 1;
    }

    private function hasIssueCategory(array $issues, string $category): bool
    {
        foreach ($issues as $issue) {
            if (($issue['category'] ?? '') === $category) {
                return true;
            }
        }

        return false;
    }
}
