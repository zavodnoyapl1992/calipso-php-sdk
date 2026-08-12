<?php

declare(strict_types=1);

namespace Calipso\Sdk\Event;

use Calipso\Sdk\Exception\InvalidEvent;

final class EntityReference
{
    /** @var string */
    private $type;

    /** @var string */
    private $id;

    public function __construct(string $type, string $id)
    {
        $type = trim($type);
        $id = trim($id);

        if (strlen($type) < 1 || strlen($type) > 64 || preg_match('/^[a-z0-9_-]+$/D', $type) !== 1) {
            throw new InvalidEvent('Entity type must be a lowercase identifier between 1 and 64 bytes.');
        }

        $idLength = preg_match_all('/./us', $id, $matches);
        if ($idLength === false || $idLength < 1 || $idLength > 180) {
            throw new InvalidEvent('Entity ID must contain between 1 and 180 characters.');
        }

        $this->type = $type;
        $this->id = $id;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function id(): string
    {
        return $this->id;
    }
}
