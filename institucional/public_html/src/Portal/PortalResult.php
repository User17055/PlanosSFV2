<?php
declare(strict_types=1);
namespace LuzPropria\LuzPanel\Portal;

final readonly class PortalResult
{
    public function __construct(public int $status,public array $data=[],public string $body='') {}
    public function ok(): bool { return $this->status>=200 && $this->status<300; }
}
