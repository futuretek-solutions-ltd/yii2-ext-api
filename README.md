ext-api
========

Yii2 JSON API.

##Requirements

- Yii2
- ext-shared-components

##Installation

Use composer and add the following line to your composer.json file:

```
"futuretek/yii2-api": "*"
```

##Usage

Define the custom action in your controller:

```php
public function actions()
{
    return [
        ...,
        'myAction' => [
            'class' => 'futuretek\api\ApiAction',
        ],
        ...,
    ];
}
```

Add phpDoc tags (see below) to the properly documented actions you want:

```php
/**
* Returns hello and the name that you gave
*
* @param string $name Your name
* @return string
* @api
*/
public function getHello($name)
{
    return ['hello' => 'Hello ' . $name];
}
```

##Allowed phpDoc tags

###@param

Usage: `@param type $variable description {additional parameters}`

Indicate input variable. If the input variable is an array, you can define it by using [], eg. String[]

####Additional parameters 

Optionally, extra attributes can be defined for each @param tag by enclosing definitions into curly brackets and separated by comma like so:

`{[attribute1 = value1][, attribute2 = value2], ...}`

where the attribute can be one of following:

**validate** - Specifies validator function to check parameter value against

* isInt
* isString
* Class::method()

**required** - If the parameter is required

* true / 1
* false / 0

**null** - If the parameter can be null

* true / 1
* false / 0

**element** - Array element definition. If @param is of type Array, you can describe array elements with this attribute 

Usage: `{element=name|type|description, element=name|type, ...}`

###@return

Usage: `@return type description`

Indicate method return value. If the return value is an array, you can define it by using [], eg. String[]

**Remember:** API function should always return associative array. If another type is returned, it will be treated like the function has no output.
Additionaly if the function returns boolean false (or another data type that can be typed to false), the API call will result in general error message.
  
If you want to express processing fail inside the method, you can use $this->setError() or $this->setWarning().

###@return-element

Usage: `@return-element type name description`

All API methods must return Array, bool or void(null). In case of Array you can specify each array element with this tag.
This is mainly to describe the method. No additional logic is bind to this tag.  

###@api

Usage: `@api`

Indicates that this method should be accessible via API interface. Methods without this tag are ignored.

###@no-auth

Usage: `@no-auth`

Indicates that this method will be publicly accessible without user identification

###@permission

Usage: `@permission permissionName`

Require specified RBAC permission to run action. If @no-auth is used, this tag will be ignored 


###@transaction

Usage: `@transaction`

Run method in database transaction 
