<?php

namespace App\Trait;

trait SerializableTrait
{
    public function __serialize(): array {
        return get_object_vars($this);
    }

    public function __unserialize(array $data): void {
        foreach ($data as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
    }

}
