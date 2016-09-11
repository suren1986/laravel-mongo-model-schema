<?php

namespace Suren\LaravelMongoModelSchema;

use Illuminate\Support\Str;

abstract class NestedMongoModel implements \JsonSerializable
{
    use ModelSchema;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [];

    /**
     * The attributes that should be visible in arrays.
     *
     * @var array
     */
    protected $visible = [];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The model's attributes.
     *
     * @var array
     */
    protected $attributes = [];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [];

    /**
     * Create a new NestedMongo model instance.
     * Attributes will be cast to native PHP type when creating.
     *
     * @param  array  $attributes
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $k => $v) {
            if (!in_array($k, $this->fillable)) continue;
            $this->setAttribute($k, $v);
        }

        $this->attributes += $this->defaultValues();
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array
     */
    public function jsonSerialize()
    {
        $obj = [];
        $keys = array_merge(array_keys($this->getArrayableItems($this->attributes)), $this->appends);
        foreach ($keys as $key) {
            $obj[$key] = $this->getAttribute($key);
        }
        return $obj;
    }

    /**
     * Get an attribute array of all arrayable values.
     *
     * @param  array  $values
     * @return array
     */
    protected function getArrayableItems(array $values)
    {
        if (count($this->visible) > 0) {
            $values = array_intersect_key($values, array_flip($this->visible));
        }

        if (count($this->hidden) > 0) {
            $values = array_diff_key($values, array_flip($this->hidden));
        }

        return $values;
    }

    /**
     * Dynamically retrieve attributes on the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Get an attribute from the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if (array_key_exists($key, $this->attributes) || $this->hasGetMutator($key)) {
            return $this->getAttributeValue($key);
        }
    }

    /**
     * Get a plain attribute
     * Value will be cast by schemas.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttributeValue($key)
    {
        $value = $this->getAttributeFromArray($key);

        if ($this->hasGetMutator($key)) {
            return $this->{'get'.Str::studly($key).'Attribute'}($value);
        }

        if (isset(static::SCHEMAS()[$key])) {
            return $this->castRawAttribute(static::SCHEMAS()[$key]['type'], $value);
        }

        return $value;
    }

    /**
     * Get an attribute from the $attributes array.
     *
     * @param  string  $key
     * @return mixed
     */
    protected function getAttributeFromArray($key)
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }
    }

    /**
     * Determine if a get mutator exists for an attribute.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasGetMutator($key)
    {
        return method_exists($this, 'get' . Str::studly($key) . 'Attribute');
    }

    /**
     * Determine if a set mutator exists for an attribute.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasSetMutator($key)
    {
        return method_exists($this, 'set'.Str::studly($key).'Attribute');
    }

    /**
     * Set a given attribute on the model.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function setAttribute($key, $value)
    {
        if ($this->hasSetMutator($key)) {
            $method = 'set'.Str::studly($key).'Attribute';

            return $this->{$method}($value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Get all of the current attributes on the model.
     *
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }
}