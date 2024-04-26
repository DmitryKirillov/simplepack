<?php

declare(strict_types=1);

namespace DmitryKirillov\Simplepack;

class SimplepackIterator implements \Iterator
{
    private $packedData;
    private $usedBytes;
    private $position;
    private $currentKey;
    private $currentValue;
    private $valid;

    public function __construct($packedData, $usedBytes)
    {
        $this->packedData = $packedData;
        $this->usedBytes = $usedBytes;
        $this->rewind();
    }

    #[ReturnTypeWillChange]
    public function current()
    {
        return $this->currentValue;
    }

    #[ReturnTypeWillChange]
    public function next()
    {
        // Advance position past the current key and value
        // todo Reuse code, probably in the form of traits
        $keySize = unpack('n', substr($this->packedData, $this->position, 2))[1];
        $valueHeaderPosition = $this->position + 2 + $keySize;
        $valueHeader = unpack('n', substr($this->packedData, $valueHeaderPosition, 2))[1];
        $valueSize = $valueHeader & 0x0FFF; // Last 12 bits for size
        $this->position = $valueHeaderPosition + 2 + $valueSize;

        if ($this->position >= $this->usedBytes) {
            $this->valid = false;
        } else {
            $this->loadCurrentEntry();
        }
    }

    #[ReturnTypeWillChange]
    public function key()
    {
        return $this->currentKey;
    }

    #[ReturnTypeWillChange]
    public function valid()
    {
        return $this->valid;
    }

    #[ReturnTypeWillChange]
    public function rewind()
    {
        $this->position = 0;
        $this->valid = $this->usedBytes > 0;
        if ($this->valid) {
            $this->loadCurrentEntry();
        }
    }

    private function loadCurrentEntry()
    {
        // todo Reuse code, probably in the form of traits
        $keySize = unpack('n', substr($this->packedData, $this->position, 2))[1];
        $this->currentKey = substr($this->packedData, $this->position + 2, $keySize);
        $valueHeaderPosition = $this->position + 2 + $keySize;
        $valueHeader = unpack('n', substr($this->packedData, $valueHeaderPosition, 2))[1];
        $valueType = $valueHeader >> 12;
        $valueSize = $valueHeader & 0x0FFF;
        $valueDataPosition = $valueHeaderPosition + 2;

        switch ($valueType) {
            case Simplepack::BITMASK_TYPE_NULL:
                $this->currentValue = null;
                break;
            case Simplepack::BITMASK_TYPE_BOOL_FALSE:
                $this->currentValue = false;
                break;
            case Simplepack::BITMASK_TYPE_BOOL_TRUE:
                $this->currentValue = true;
                break;
            case Simplepack::BITMASK_TYPE_INT:
                $this->currentValue = unpack('q', substr($this->packedData, $valueDataPosition, $valueSize))[1];
                break;
            case Simplepack::BITMASK_TYPE_FLOAT:
                $this->currentValue = unpack('d', substr($this->packedData, $valueDataPosition, $valueSize))[1];
                break;
            case Simplepack::BITMASK_TYPE_STRING:
                $this->currentValue = substr($this->packedData, $valueDataPosition, $valueSize);
                break;
        }
    }
}
