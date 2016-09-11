Schema for Laravel MongoDB Model
================================

An extension of [Laravel MongoDB](https://github.com/jenssegers/laravel-mongodb)

Data will be formatted by defined schemas when saving to the MongoDB.
Data will be cast by defined schemas, when retrieving from MongoModel or NestedMongoModel instance, just like what the Laravel Eloquent do.

The new NestedMongoModel is more expressive than array, see the introduction below.

All the other features, like query building, relationships, of Laravel Eloquent has been kept unchanged.

Installation
============

Installation using composer:

```
composer require suren/laravel-mongo-model-schema dev-master
```

Extends models from MongoModel

```
use Suren\LaravelMongoModelSchema\MongoModel;

class User extends MongoModel


use Suren\LaravelMongoModelSchema\NestedMongoModel;

class Avatar extends NestedMongoModel
```

Define schemas:
===============

Override the SCHEMAS function in MongoModel and NestedMongoModel
```
public static function SCHEMAS()
{
    return [
        'integer_field' => ['type' => 'int',    'default' => 1],
        'float_field'   => ['type' => 'float',  'default' => 1.0],
        'string_field'  => ['type' => 'string', 'default' => 'default_string'],
        'bool_field'    => ['type' => 'bool',   'default' => false],
        'date_field'    => ['type' => 'date',   'default' => Carbon:now()],
        'time_field'    => ['type' => 'timestamp', 'default' => time()],
        'object_id_field'       => ['type' => ObjectID::class,      'default' => new ObjectId()],
        'nested_object_field'   => ['type' => NestedObject::class,  'default' => []],
        
        'integer_array_field'   => ['type' => 'array(int)',     'default' => [1, 2]],
        'float_array_field'     => ['type' => 'array(float)',   'default' => [1.0, 2.0]],
        'string_array_field'    => ['type' => 'array(string)',  'default' => ['default_string', 'another_string']],
        'bool_array_field'      => ['type' => 'array(bool)',    'default' => [true, false]],
        'date_array_field'      => ['type' => 'array(date)',    'default' => [Carbon:now(), Carbon:now()]],
        'time_array_field'      => ['type' => 'array(time)',    'default' => [time(), time()]],
        'object_id_array_field'     => ['type' => 'array(' . ObjectID::class . ')',     'default' => [new ObjectId(), new ObjectId()]],
        'nested_object_array_field' => ['type' => 'array(' . NestedObject::class . ')', 'default' => [[], new NestedObject()]],
    ];
}
```

Schema
======

The original Laravel cast type, array, object, json, collection has been abandoned.

ObjectId type will be saved as string in MongoDB.

Array of some type will be saved as array in MongoDB, not json value.

The default field in schema indicates which value will be retrieving, when no value has been set to the model.

NestedMongoModel
================

NestedMongoModel will been attached to its parent object in MongoDB, not a stand along document. It will be formatted to key-value array when saving to the MongoDB, and will be cast to defined NestedMongoModel automatically when retrieving from parent object.

Schemas can also been defined for NestedMongoModel.

You can define another NestedMongoModel inside NestedMongoModel.

Mass assignment is supported, just like Eloquent.
```
protected $fillable = ['width', 'height']; 
```

All fields will been show when json_encode by default, the visible and hidden field can be defined to control this, just like Eloquent.
```
protected $visible = ['name', 'gender'];    // only these fields will show
protected $hidden = ['password', 'secret']; // these fields will be show
```

Appending fields is supported for json_encode, just like Eloquent.
```
protected $appends = ['is_admin'];

public function getIsAdminAttribute()
{
    return $this->role == self::ROLE_ADMIN;
}
```

Sample
======

We define a User model, and an Avatar model to store user's avatar info.
```
class User extends MongoModel
{
    const ROLE_STAFF = 1;
    const ROLE_ADMIN = 2;
    
    protected $fillable = ['name', 'role', 'avatar', 'password'];
    protected $hidden = ['password'];
    
    public static function SCHEMAS()
    {
        return [
            'name'      => ['type' => 'string'],
            'age'       => ['type' => 'int'],
            'role'      => ['type' => 'int', 'default' => self::ROLE_STAFF],
            'avatar'    => ['type' => Avatar::class, 'default' => new Avatar()],
            'password'  => ['type' => 'string'],
        ];
    }
}

class Avatar extends NestedMongoModel
{
    const SOURCE_UPLOAD         = 1;    // directly upload
    const SOURCE_THIRD_PARTY    = 2;    // get from third party account
    
    protected $visible = ['width', 'height'];
    protected $append = ['full_url'];
    
    public static function SCHEMAS()
    {
        return [
            'url'       => ['type' => 'string'],
            'width'     => ['type' => 'int'],
            'height'    => ['type' => 'int'],
            'source'    => ['type' => 'int'],
        ];
    }
    
    public function getFullUrlAttribute()
    {
        return 'http://hostname/' . $this->url;
    }
}
```

MongoModel and it's NestedMongoModel can be create like this.
```
$user = User::create([
    'name'  => 'Suren',
    'age'   => '30',
    'avatar' => new Avatar([
        'url' => 'saved_path',
        'width' => 100,
        'height' => 50,
    ]),
    'password' => 'secret',
]);
```
The role is not defined, but it will be saved as `User::ROLE_STAFF`, which is the default value.
The age will be saved as integer, despite it's has been set with a string value.
The source of user avatar will not be saved, for it's has no default value.

Json encode result.
```
$user = User::first();
echo json_encode($user, JSON_PRETTY_PRINT);

### json_encode result ###
{
    "name": "Suren",
    "age": 30,
    "role": 1,
    "avatar": {
        "width": 100,
        "height": 50,
        "full_url": "http://hostname/saved_path" 
    }
}
```