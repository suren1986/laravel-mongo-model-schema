<?php

namespace Suren\LaravelMongoModelSchema;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Jenssegers\Mongodb\Eloquent\Model;
use MongoDB\BSON\ObjectID;

abstract class MongoModel extends Model
{
    use ModelSchema;

    /**
     * Create a new Eloquent model instance.
     * Attributes will be cast to native PHP type when creating.
     *
     * @param  array  $attributes
     * @return void
     */
    public function __construct(array $attributes = []) {
        parent::__construct($attributes);

        $this->attributes += $this->defaultValues();
    }

    /**
     * Get a plain attribute (not a relationship).
     *
     * Difference from Illuminate\Database\Eloquent\Model:
     * Value will be cast by schemas.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttributeValue($key)
    {
        $value = $this->getAttributeFromArray($key);

        // If the attribute has a get mutator, we will call that then return what
        // it returns as the value, which is useful for transforming values on
        // retrieval from the model to a form that is more useful for usage.
        if ($this->hasGetMutator($key)) {
            return $this->mutateAttribute($key, $value);
        }

        if (isset(static::SCHEMAS()[$key])) {
            return $this->castRawAttribute(static::SCHEMAS()[$key]['type'], $value);
        }

        // If the attribute is listed as a date, we will convert it to a DateTime
        // instance on retrieval, which makes it quite convenient to work with
        // date fields without having to create a mutator for each property.
        if (in_array($key, $this->getDates()) && ! is_null($value)) {
            return $this->asDateTime($value);
        }

        return $value;
    }

    /**
     * Convert the model's attributes to an array.
     *
     * Difference from Illuminate\Database\Eloquent\Model:
     * Value will be cast by schemas.
     *
     * @return array
     */
    public function attributesToArray()
    {
        $attributes = $this->getArrayableAttributes();

        // If an attribute is a date, we will cast it to a string after converting it
        // to a DateTime / Carbon instance. This is so we will get some consistent
        // formatting while accessing attributes vs. arraying / JSONing a model.
        foreach ($this->getDates() as $key) {
            if (! isset($attributes[$key])) {
                continue;
            }

            $attributes[$key] = $this->serializeDate(
                $this->asDateTime($attributes[$key])
            );
        }

        $mutatedAttributes = $this->getMutatedAttributes();

        // We want to spin through all the mutated attributes for this model and call
        // the mutator for the attribute. We cache off every mutated attributes so
        // we don't have to constantly check on attributes that actually change.
        foreach ($mutatedAttributes as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $attributes[$key] = $this->mutateAttributeForArray(
                $key, $attributes[$key]
            );
        }

        foreach (static::SCHEMAS() as $key => $schema) {
            if (! array_key_exists($key, $attributes) ||
                in_array($key, $mutatedAttributes)) {
                continue;
            }

            $attributes[$key] = $this->castRawAttribute(
                static::SCHEMAS()[$key]['type'], $attributes[$key]
            );

            if ($attributes[$key] && (static::SCHEMAS()[$key]['type'] === 'date' || static::SCHEMAS()[$key]['type'] === 'datetime')) {
                $attributes[$key] = $this->serializeDate($attributes[$key]);
            }
        }

        // Here we will grab all of the appended, calculated attributes to this model
        // as these attributes are not really in the attributes array, but are run
        // when we need to array or JSON the model for convenience to the coder.
        foreach ($this->getArrayableAppends() as $key) {
            $attributes[$key] = $this->mutateAttributeForArray($key, null);
        }

        // Because the original Eloquent never returns objects, we convert
        // MongoDB related objects to a string representation. This kind
        // of mimics the SQL behaviour so that dates are formatted
        // nicely when your models are converted to JSON.
        if (isset($attributes['_id']) && $attributes['_id'] instanceof ObjectID) {
            $attributes['_id'] = (string) $attributes['_id'];
        }

        return $attributes;
    }

    /**
     * Perform a model insert operation.
     *
     * Difference from Illuminate\Database\Eloquent\Model:
     * Attributes will be formatted before insert.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $options
     * @return bool
     */
    protected function performInsert(Builder $query, array $options = [])
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        // First we'll need to create a fresh query instance and touch the creation and
        // update timestamps on this model, which are maintained by us for developer
        // convenience. After, we will just continue saving these model instances.
        if ($this->timestamps && Arr::get($options, 'timestamps', true)) {
            $this->updateTimestamps();
        }

        // If the model has an incrementing key, we can use the "insertGetId" method on
        // the query builder, which will give us back the final inserted ID for this
        // table from the database. Not all tables have to be incrementing though.
        $attributes = $this->formattedAttributes($this->attributes);
        $attributes[static::CREATED_AT] = $this->attributes[static::CREATED_AT];
        $attributes[static::UPDATED_AT] = $this->attributes[static::UPDATED_AT];

        if ($this->getIncrementing()) {
            $this->insertAndSetId($query, $attributes);
        }

        // If the table isn't incrementing we'll simply insert these attributes as they
        // are. These attribute arrays must contain an "id" column previously placed
        // there by the developer as the manually determined key for these models.
        else {
            $query->insert($attributes);
        }

        // We will go ahead and set the exists property to true, so that it is set when
        // the created event is fired, just in case the developer tries to update it
        // during the event. This will allow them to do so and run an update here.
        $this->exists = true;

        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * Perform a model update operation.
     *
     * Difference from Illuminate\Database\Eloquent\Model:
     * Attributes will be formatted before insert.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array  $options
     * @return bool
     */
    protected function performUpdate(Builder $query, array $options = [])
    {
        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            // If the updating event returns false, we will cancel the update operation so
            // developers can hook Validation systems into their models and cancel this
            // operation if the model does not pass validation. Otherwise, we update.
            if ($this->fireModelEvent('updating') === false) {
                return false;
            }

            // First we need to create a fresh query instance and touch the creation and
            // update timestamp on the model which are maintained by us for developer
            // convenience. Then we will just continue saving the model instances.
            if ($this->timestamps && Arr::get($options, 'timestamps', true)) {
                $this->updateTimestamps();
            }

            // Once we have run the update operation, we will fire the "updated" event for
            // this model instance. This will allow developers to hook into these after
            // models are updated, giving them a chance to do any special processing.
            $dirty = $this->getDirty();

            $dropFields = array_keys(array_filter($dirty, function ($value) {
                return is_null($value);
            }));

            $dirty = $this->formattedAttributes($dirty);
            $dirty[static::UPDATED_AT] = $this->attributes[static::UPDATED_AT];

            if (count($dirty) > 0) {
                $numRows = $this->setKeysForSaveQuery($query)->update($dirty);
            }

            if (count($dropFields) > 0) {
                $this->setKeysForSaveQuery($query)->drop($dropFields);
            }

            if (count($dirty) > 0 || count($dropFields) > 0) {
                $this->fireModelEvent('updated', false);
            }
        }

        return true;
    }
}