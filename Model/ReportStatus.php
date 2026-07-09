<?php
namespace Nistruct\ContentAI\Model;

class ReportStatus
{
    public const PENDING_APPROVAL = 'pending_approval';
    public const PROCESSING = 'processing';
    public const PARTIALLY_APPLIED = 'partially_applied';
    public const APPLIED = 'applied';
    public const FAILED = 'failed';
}
