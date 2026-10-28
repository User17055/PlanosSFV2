<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Portal;

class PortalApiException extends \RuntimeException
{
    public function __construct(public PortalResult $result) { parent::__construct('Portal API request failed.'); }
}
