<?php

namespace Suren\LaravelMongoModelSchema;

use DateTimeInterface;
use Carbon\Carbon;
use MongoDB\BSON\ObjectID;
use MongoDB\BSON\UTCDateTime;
use DateTime;

trait ModelSchema {
    /**
     * The schema attributes for the model.
     *
     * @var array
     */
    public static function SCHEMAS() {
        return [];
    }

    /**
     * Get formatted attributes which meet the schema.
     *
     * @param  array $attributes
     * @return array
     */
    public function formattedAttributes($attributes)
    {
        $result = [];

        foreach (static::SCHEMAS() as $field => $schema) {
            if (!isset($attributes[$field])) continue;

            $value = $this->formatAttribute($schema['type'], $attributes[$field]);
            if (!is_null($value)) $result[$field] = $value;
        }

        return $result;
    }

    /**
     * Get formatted attribute which meets the schema.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    protected function formatAttribute($type, $value)
    {
        if (is_null($value)) return $value;

        switch ($type) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
            case ObjectID::class:
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'date':
            case 'datetime':
                return $this->formatDateTime($value);
            case 'timestamp':
                return $this->asTimeStamp($value);
        }

        if (starts_with($type, 'array(') && ends_with($type, ')')) {
            if (!is_array($value)) return null;

            $subtype = substr($type, 6, -1);
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->formatAttribute($subtype, $v);
            }

            return $result;
        }

        if (is_subclass_of($type, NestedMongoModel::class)) {
            $object = null;

            if (is_object($value) && get_class($value) == $type) {
                $object = $value;
            } else if (is_array($value)) {
                $object = new $type();
                foreach ($value as $k => $v) {
                    $object->$k = $v;
                }
            }

            if (!is_null($object)) {
                return $object->formattedAttributes($object->getAttributes());
            }
        }
    }

    /**
     * Get the schema for a model attribute.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function getSchema($key)
    {
        if (!isset(static::SCHEMAS()[$key])) return null;
        return static::SCHEMAS()[$key] + [
            'type' => 'int',
            'default' => null,
            'allow_null' => false,
        ];
    }


    /**
     * Cast a raw attribute to a native PHP type.
     *
     * @param  string  $type
     * @param  mixed  $value
     * @return mixed
     */
    protected function castRawAttribute($type, $value)
    {
        switch ($type) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
            case ObjectID::class:
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'date':
            case 'datetime':
                return $this->asDateTime($value);
            case 'timestamp':
                return $this->asTimeStamp($value);
        }

        if (starts_with($type, 'array(') && ends_with($type, ')')) {
            if (!is_array($value)) return null;

            $subtype = substr($type, 6, -1);
            $result = [];
            foreach ($value as $k => $v) {
                $result[$k] = $this->castRawAttribute($subtype, $v);
            }

            return $result;
        }

        if (is_subclass_of($type, NestedMongoModel::class) && is_array($value)) {
            $nestedModel = new $type();
            foreach ($value as $k => $v) {
                $nestedModel->$k = $v;
            }
            return $nestedModel;
        }

        return $value;
    }

    /**
     * Return a timestamp as DateTime object.
     *
     * @param  mixed  $value
     * @return \Carbon\Carbon
     */
    protected function asDateTime($value)
    {
        if ($value instanceof UTCDateTime) {
            return Carbon::createFromTimestamp($value->toDateTime()->getTimestamp());
        }

        // If this value is already a Carbon instance, we shall just return it as is.
        // This prevents us having to re-instantiate a Carbon instance when we know
        // it already is one, which wouldn't be fulfilled by the DateTime check.
        if ($value instanceof Carbon) {
            return $value;
        }

        // If the value is already a DateTime instance, we will just skip the rest of
        // these checks since they will be a waste of time, and hinder performance
        // when checking the field. We will just return the DateTime right away.
        if ($value instanceof DateTimeInterface) {
            return new Carbon(
                $value->format('Y-m-d H:i:s.u'), $value->getTimeZone()
            );
        }

        // If this value is an integer, we will assume it is a UNIX timestamp's value
        // and format a Carbon object from this timestamp. This allows flexibility
        // when defining your date fields as they might be UNIX timestamps here.
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value);
        }

        // If the value is in simply year, month, day format, we will instantiate the
        // Carbon instances from that format. Again, this provides for simple date
        // fields on the database, while still supporting Carbonized conversion.
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value)) {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        }

        // Finally, we will just assume this date is in the format used by default on
        // the database connection and use that format to create the Carbon object
        // that is returned back out to the developers after we convert it here.
        return Carbon::createFromFormat($this->getDateFormat(), $value);
    }

    /**
     * Return a timestamp as unix timestamp.
     *
     * @param  mixed  $value
     * @return int
     */
    protected function asTimeStamp($value)
    {
        return $this->asDateTime($value)->getTimestamp();
    }

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return 'Y-m-d H:i:s';
    }

    /**
     * Format time value to UTCDateTime
     *
     * @param $value
     * @return UTCDateTime
     */
    public function formatDateTime($value)
    {
        // If the value is already a UTCDateTime instance, we don't need to parse it.
        if ($value instanceof UTCDateTime) {
            return $value;
        }

        // Let Eloquent convert the value to a DateTime instance.
        if (! $value instanceof DateTime) {
            $value = $this->asDateTime($value);
        }

        return new UTCDateTime($value->getTimestamp() * 1000);
    }

    /**
     * Get default value from schema
     *
     * @return array
     */
    protected function defaultValues()
    {
        $result = [];

        foreach (static::SCHEMAS() as $field => $schema) {
            if (!isset($schema['default'])) continue;
            $result[$field] = $this->castRawAttribute($schema['type'], $schema['default']);
        }

        return $result;
    }
}