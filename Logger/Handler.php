<?php

namespace Nistruct\ContentAI\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/contentai.log';

    /**
     * @var int
     */
    protected $loggerType = Logger::DEBUG;
}
