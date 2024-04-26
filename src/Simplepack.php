<?php

namespace DmitryKirillov\Simplepack;

class Simplepack implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    const MAX_ENTRIES = 64;
    const MAX_KEY_LENGTH = 64;
    const MAX_VALUE_LENGTH = 64;

    const BITMASK_TYPE_NULL = 0b0000;
    const BITMASK_TYPE_BOOL_FALSE = 0b0010;
    const BITMASK_TYPE_BOOL_TRUE = 0b0011;
    const BITMASK_TYPE_INT = 0b0100;
    const BITMASK_TYPE_FLOAT = 0b0101;
    const BITMASK_TYPE_STRING = 0b1000;

    private $packedData = '';
    private $unpackedData = null; // This will eventually become an array
    private $count = 0;
    private $usedBytes = 0;

    public function __construct()
    {
        // todo Add fromArray() method
    }

    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        if (is_array($this->unpackedData)) {
            return new \ArrayIterator($this->unpackedData);
        }
        return new SimplepackIterator($this->packedData, $this->usedBytes);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        if (is_array($this->unpackedData)) {
            return array_key_exists($offset, $this->unpackedData);
        }
        return $this->findEntryPosition($offset) !== null;
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        if (is_array($this->unpackedData)) {
            return isset($this->unpackedData[$offset]) ? $this->unpackedData[$offset] : null;
        }

        $entryPosition = $this->findEntryPosition($offset);
        if ($entryPosition !== null) {
            return $this->unpackValueAt($entryPosition);
        }
        return null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        // Early return if the data is already unpacked
        if (is_array($this->unpackedData)) {
            $this->unpackedData[$offset] = $value;
            return;
        }

        // todo Simplify this chain
        if ($this->count >= self::MAX_ENTRIES) {
            $this->transitionToUnpackedData();
        } else if (!is_string($offset) || strlen($offset) > self::MAX_KEY_LENGTH) {
            $this->transitionToUnpackedData();
        } else if (!is_integer($value) && !is_float($value) && !is_bool($value) && !is_null($value) && !is_string($value)) {
            $this->transitionToUnpackedData();
        } else if (is_string($value) && strlen($value) > self::MAX_VALUE_LENGTH) {
            $this->transitionToUnpackedData();
        }

        if (is_array($this->unpackedData)) {
            $this->unpackedData[$offset] = $value;
            return;
        }

        $entryPosition = $this->findEntryPosition($offset); // Returns null if not found
        if ($entryPosition !== null) {
            $this->replaceEntry($entryPosition, $value);
        } else {
            $this->addEntry($offset, $value);
        }
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        // Early return if the data is already unpacked
        if (is_array($this->unpackedData)) {
            unset($this->unpackedData[$offset]);
        } else {
            $entryPosition = $this->findEntryPosition($offset); // Returns null if not found
            if ($entryPosition !== null) {
                $this->removeEntry($entryPosition);
            }
        }
    }

    #[\ReturnTypeWillChange]
    public function count()
    {
        if (is_array($this->unpackedData)) {
            return count($this->unpackedData);
        }
        return $this->count;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        // todo Optimise performance
        if (is_array($this->unpackedData)) {
            return $this->unpackedData;
        }
        $data = [];
        if ($this->count > 0) {
            foreach ($this as $key => $value) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    private function transitionToUnpackedData()
    {
        if ($this->unpackedData === null) {
            $temp = [];
            foreach ($this as $key => $value) {
                $temp[$key] = $value;
            }
            $this->unpackedData = $temp;
            $this->packedData = null;
            $this->usedBytes = 0;
            $this->count = 0;
        }
    }

    private function addEntry($key, $value)
    {
        $packedKey = $this->packKey($key);
        $packedValue = $this->packValue($value);

        $this->packedData = substr_replace(
            $this->packedData,
            $packedKey,
            $this->usedBytes,
            0
        );
        $this->usedBytes += strlen($packedKey);
        $this->packedData = substr_replace(
            $this->packedData,
            $packedValue,
            $this->usedBytes,
            0
        );
        $this->usedBytes += strlen($packedValue);

        $this->count++;
    }

    private function replaceEntry($entryPosition, $value)
    {
        // Calculate the position and header of the existing value
        // todo Consider using custom pack/unpack here as to avoid unpack overhead (array)
        $keySize = unpack('n', substr($this->packedData, $entryPosition, 2))[1];
        $valueHeaderPosition = $entryPosition + 2 + $keySize;
        $valueHeader = unpack('n', substr($this->packedData, $valueHeaderPosition, 2))[1];
        $oldValueSize = $valueHeader & 0x0FFF;

        // todo Return early if the value hasn't changed
        $packedNewValue = $this->packValue($value);
        $adjustment = strlen($packedNewValue) - (2 + $oldValueSize);

        if ($adjustment === 0) {
            $this->packedData = substr_replace(
                $this->packedData,
                $packedNewValue,
                $valueHeaderPosition,
                strlen($packedNewValue)
            );
        } else {
            $remainingEntriesPosition = $valueHeaderPosition + 2 + $oldValueSize;
            $this->adjustRemainingEntriesPosition($remainingEntriesPosition, -1 * (2 + $oldValueSize));
            $this->packedData = substr_replace(
                $this->packedData,
                $packedNewValue,
                $valueHeaderPosition,
                0
            );
        }

        $this->usedBytes += $adjustment;
    }

    private function removeEntry($entryPosition)
    {
        // Calculate the position and header of the existing value
        // todo Consider using custom pack/unpack here as to avoid unpack overhead (array)
        $keySize = unpack('n', substr($this->packedData, $entryPosition, 2))[1];
        $valueHeaderPosition = $entryPosition + 2 + $keySize;
        $valueHeader = unpack('n', substr($this->packedData, $valueHeaderPosition, 2))[1];
        $oldValueSize = $valueHeader & 0x0FFF;

        $entryTotalSize = 2 + $keySize + 2 + $oldValueSize; // Total size including key and value headers
        $adjustment = -1 * $entryTotalSize;
        $remainingEntriesPosition = $valueHeaderPosition + 2 + $oldValueSize;
        $this->adjustRemainingEntriesPosition($remainingEntriesPosition, $adjustment);

        $this->usedBytes += $adjustment;
        $this->count--;
    }

    private function adjustRemainingEntriesPosition($remainingEntriesPosition, $adjustment)
    {
        $remainingEntities = substr(
            $this->packedData,
            $remainingEntriesPosition,
            $this->usedBytes - $remainingEntriesPosition
        );
        $this->packedData = substr_replace(
            $this->packedData,
            $remainingEntities,
            $remainingEntriesPosition + $adjustment,
            strlen($remainingEntities)
        );
    }

    private function packKey($key)
    {
        return pack('n', strlen($key)) . $key;
    }

    private function packValue($value)
    {
        if (is_null($value)) {
            return pack('n', self::BITMASK_TYPE_NULL << 12 | 0);
        }
        if (is_bool($value)) {
            if ($value === true) {
                return pack('n', self::BITMASK_TYPE_BOOL_TRUE << 12 | 0);
            } else {
                return pack('n', self::BITMASK_TYPE_BOOL_FALSE << 12 | 0);
            }
        }
        // todo Add support for 32-bit architecture
        if (is_int($value)) {
            return pack('n', self::BITMASK_TYPE_INT << 12 | 8) . pack('q', $value);
        }
        // todo Add support for 32-bit architecture
        if (is_float($value)) {
            return pack('n', self::BITMASK_TYPE_FLOAT << 12 | 8) . pack('d', $value);
        }
        if (is_string($value)) {
            // todo Check for overflow
            $length = strlen($value);
            return pack('n', self::BITMASK_TYPE_STRING << 12 | $length) . $value;
        }
        throw new \InvalidArgumentException("Unsupported value type");
    }

    private function findEntryPosition($key)
    {
        if (strlen($key) > $this->usedBytes) {
            return null;
        }
        $currentOffset = 0;
        while ($currentOffset < $this->usedBytes) {
            // Skip over the key
            // todo Consider using custom pack/unpack here as to avoid unpack overhead (array)
            $keySize = unpack('n', substr($this->packedData, $currentOffset, 2))[1];
            $currentKey = substr($this->packedData, $currentOffset + 2, $keySize);
            if ($currentKey === $key) {
                return $currentOffset;
            }
            $currentOffset += 2 + $keySize; // 2-byte header + key size

            // Skip over the value
            $valueHeader = unpack('n', substr($this->packedData, $currentOffset, 2))[1];
            $valueSize = $valueHeader & 0x0FFF; // Mask to get the last 12 bits, which represent the size
            $currentOffset += 2 + $valueSize; // 2-byte header + value size
        }
        return null;
    }

    private function unpackValueAt($entryPosition)
    {
        // Skip over the key
        // todo Consider using custom pack/unpack here as to avoid unpack overhead (array)
        $keySize = unpack('n', substr($this->packedData, $entryPosition, 2))[1];
        $valuePosition = $entryPosition + 2 + $keySize;

        $valueHeader = unpack('n', substr($this->packedData, $valuePosition, 2))[1];
        $valueType = $valueHeader >> 12;
        $valueSize = $valueHeader & 0x0FFF;

        // Extract and return the value based on its type.
        $valueDataPosition = $valuePosition + 2;
        switch ($valueType) {
            case self::BITMASK_TYPE_NULL:
                return null;
            case self::BITMASK_TYPE_BOOL_TRUE:
                return true;
            case self::BITMASK_TYPE_BOOL_FALSE:
                return false;
            case self::BITMASK_TYPE_INT:
                return unpack('q', substr($this->packedData, $valueDataPosition, $valueSize))[1];
            case self::BITMASK_TYPE_FLOAT:
                return unpack('d', substr($this->packedData, $valueDataPosition, $valueSize))[1];
            case self::BITMASK_TYPE_STRING:
                return substr($this->packedData, $valueDataPosition, $valueSize);
            default:
                throw new \InvalidArgumentException(
                    "Unsupported value type encountered in packed data"
                );
        }
    }
}
