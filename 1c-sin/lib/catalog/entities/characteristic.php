<?php

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use JsonSerializable;

class Characteristic implements JsonSerializable
{
    /**
     * @var string
     */
    protected string $name;

    /**
     * @var string
     */
    protected string $value;
    /**
     * @var FilterPropHint|null
     */
    protected ?FilterPropHint $filterPropHint;

    /**
     * @param string $name
     * @param string $value
     * @param FilterPropHint|null $filterPropHint
     */
    public function __construct(string $name, string $value, ?FilterPropHint $filterPropHint)
    {
        $this->name = $name;
        $this->value = $value;
        $this->filterPropHint = $filterPropHint;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    public function getFilterPropHint(): ?FilterPropHint
    {
        return $this->filterPropHint;
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize()
    {
        return [
            'name' => $this->getName(),
            'value' => $this->getValue(),
            'hint' => $this->getFilterPropHint(),
        ];
    }
}